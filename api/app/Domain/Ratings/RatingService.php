<?php

declare(strict_types=1);

namespace App\Domain\Ratings;

use App\Models\BoardRating;
use App\Models\Puzzle;
use App\Models\Rating;
use App\Models\RatingEvent;
use App\Models\Solve;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The RATING.md §5 update pipeline: valid solve (or failed daily) -> §3
 * outcome, §4 weight -> user update -> board update (same game, s_board =
 * 1 - s_user, weight 1.0) -> rating_events audit row (before/after both
 * sides) -> ratings.games += 1.
 *
 * Consumes the WS-07 seam events at-least-once, so every apply* method is
 * idempotent: the dedupe check runs under the user's locked ratings row,
 * which every processor of that user's games must take first (lock order is
 * user, then board — deadlock-free).
 */
final class RatingService
{
    /**
     * Marker reject_reason of the synthetic solves row a failed daily books
     * its rating_events audit against (rating_events.solve_id is NOT NULL in
     * the frozen schema). Never surfaced in /me/solves.
     */
    public const FAILED_DAILY_REASON = 'failed_daily';

    public const BOARD_RD_FLOOR = 50.0;

    public function __construct(
        private readonly Glicko2 $glicko2,
        private readonly BoardPriors $priors,
    ) {}

    /**
     * RatableSolveRecorded listener path: one solve = one rating period with
     * one game, both sides updated. Idempotent per solve_id.
     */
    public function applyRatableSolve(int $solveId): void
    {
        /** @var Solve|null $solve */
        $solve = Solve::query()->find($solveId);

        if ($solve === null || $solve->user_id === null) {
            return; // Deleted or anonymized since dispatch.
        }

        // Defense in depth (RATING.md §3/§5): only valid, non-suspect,
        // non-imported, stage-3-free solves may ever move a rating. WS-07
        // filters at dispatch; a mis-dispatched event must still no-op.
        if (! $solve->valid || $solve->suspect || $solve->imported || $solve->hints_s3 > 0) {
            return;
        }

        $userId = $solve->user_id;

        if (! $this->userIsActive($userId)) {
            return;
        }

        $score = Outcome::solveScore($solve->hints_s1, $solve->hints_s2);
        $weight = Outcome::weightFor($solve->mode);

        DB::transaction(function () use ($solve, $userId, $score, $weight): void {
            $rating = $this->lockedUserRating($userId);

            if (RatingEvent::query()->where('solve_id', $solve->id)->exists()) {
                return; // Duplicate delivery (at-least-once).
            }

            $this->settle($solve, $rating, $score, $weight);
        });
    }

    /**
     * FailedDailyRecorded listener path (RATING.md §3): s = 0.25, weight 1.0,
     * one per user per day max. The audit row books against a synthetic
     * invalid solves row whose deterministic client_solve_id makes the
     * (user_id, client_solve_id) unique constraint the dedupe backstop for
     * rollover re-runs.
     */
    public function applyFailedDaily(string $userId, string $date, string $puzzleId): void
    {
        if (! $this->userIsActive($userId)) {
            return;
        }

        if (Puzzle::query()->whereKey($puzzleId)->doesntExist()) {
            return;
        }

        $clientSolveId = self::failedDailyKey($userId, $date);

        DB::transaction(function () use ($userId, $puzzleId, $clientSolveId): void {
            $rating = $this->lockedUserRating($userId);

            $alreadyApplied = Solve::query()
                ->where('user_id', $userId)
                ->where('client_solve_id', $clientSolveId)
                ->exists();

            if ($alreadyApplied) {
                return; // Rollover re-run: this (user, date) is settled.
            }

            $solve = new Solve([
                'user_id' => $userId,
                'puzzle_id' => $puzzleId,
                'mode' => 'daily',
                'client_solve_id' => $clientSolveId,
                'shaded_bits' => '',
                'client_ms' => 0,
                'official_ms' => null,
                'received_at' => Carbon::now('UTC'),
                'valid' => false,
                'reject_reason' => self::FAILED_DAILY_REASON,
                'suspect' => false,
                'imported' => false,
                'hints_s1' => 0,
                'hints_s2' => 0,
                'hints_s3' => 0,
                'undo_count' => 0,
            ]);
            $solve->save();

            $this->settle($solve, $rating, Outcome::FAILED_DAILY_SCORE, 1.0);
        });
    }

    /**
     * Deterministic uuid-shaped key for the synthetic failed-daily row: the
     * DB-unique (user_id, client_solve_id) then enforces one per day.
     */
    public static function failedDailyKey(string $userId, string $date): string
    {
        $hex = substr(hash('sha256', 'burnfront.failed-daily|'.$userId.'|'.$date), 0, 32);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * One Glicko-2 game, both sides. Runs inside the caller's transaction,
     * after the user lock and the dedupe check.
     */
    private function settle(Solve $solve, Rating $rating, float $score, float $weight): void
    {
        $board = null;

        if ($solve->puzzle_id !== null) {
            $board = $this->lockedBoardRating($solve->puzzle_id);
            $boardState = new Glicko2State($board->rating, $board->rd, $board->volatility);
        } else {
            $prior = $this->endlessPrior($solve);

            if ($prior === null) {
                return;
            }

            // Ephemeral opponent (ADR-0006): endless boards are one-shot and
            // client-generated; their post-game state lives only in the audit
            // row.
            $boardState = new Glicko2State($prior, BoardPriors::IMPORT_RD, RatingStore::DEFAULT_VOLATILITY);
        }

        $userState = new Glicko2State($rating->rating, $rating->rd, $rating->volatility);

        $newUser = $this->glicko2->update($userState, [[
            'rating' => $boardState->rating,
            'rd' => $boardState->rd,
            'score' => $score,
        ]], $weight);

        $newBoard = $this->glicko2->update($boardState, [[
            'rating' => $userState->rating,
            'rd' => $userState->rd,
            'score' => 1.0 - $score,
        ]])->withRdFloor(self::BOARD_RD_FLOOR);

        $now = Carbon::now('UTC');

        $rating->rating = $newUser->rating;
        $rating->rd = $newUser->rd;
        $rating->volatility = $newUser->volatility;
        $rating->games = $rating->games + 1;
        $rating->updated_at = $now;
        $rating->save();

        if ($board !== null) {
            $board->rating = $newBoard->rating;
            $board->rd = $newBoard->rd;
            $board->volatility = $newBoard->volatility;
            $board->attempts = $board->attempts + 1;
            $board->updated_at = $now;
            $board->save();
        }

        RatingEvent::query()->create([
            'solve_id' => $solve->id,
            'user_id' => $rating->user_id,
            'puzzle_id' => $solve->puzzle_id,
            'score' => $score,
            'weight' => $weight,
            'user_before' => $userState->rating,
            'user_after' => $newUser->rating,
            'user_rd_before' => $userState->rd,
            'user_rd_after' => $newUser->rd,
            'board_before' => $boardState->rating,
            'board_after' => $newBoard->rating,
            'created_at' => $now,
        ]);
    }

    /**
     * The endless prior (RATING.md §4), quantized through float4 exactly like
     * a stored board's seed, so a rating_events replay reproduces the live
     * chain bit-for-bit from the recorded board_before.
     */
    private function endlessPrior(Solve $solve): ?float
    {
        $spec = $solve->endless_spec;
        $steps = is_array($spec) ? ($spec['deduction_steps'] ?? null) : null;

        if (! is_int($steps) || $steps < 1) {
            // WS-07 requires deduction_steps for endless submissions; a row
            // without one cannot be priced and must not move ratings.
            Log::warning('burnfront.ratings: endless solve without deduction_steps left unrated', [
                'solve_id' => $solve->id,
            ]);

            return null;
        }

        return Float4::quantize([$this->priors->priorForEndless($steps)])[0];
    }

    private function lockedUserRating(string $userId): Rating
    {
        Rating::query()->insertOrIgnore([[
            'user_id' => $userId,
            'rating' => RatingStore::DEFAULT_RATING,
            'rd' => RatingStore::DEFAULT_RD,
            'volatility' => RatingStore::DEFAULT_VOLATILITY,
            'games' => 0,
            'updated_at' => Carbon::now('UTC'),
        ]]);

        /** @var Rating $rating */
        $rating = Rating::query()->lockForUpdate()->findOrFail($userId);

        return $rating;
    }

    /**
     * Locks the board row, seeding the §2 prior on the first rated solve.
     * Always taken after the user lock.
     */
    private function lockedBoardRating(string $puzzleId): BoardRating
    {
        /** @var BoardRating|null $board */
        $board = BoardRating::query()->lockForUpdate()->find($puzzleId);

        if ($board !== null) {
            return $board;
        }

        /** @var Puzzle $puzzle */
        $puzzle = Puzzle::query()->findOrFail($puzzleId);

        BoardRating::query()->insertOrIgnore([[
            'puzzle_id' => $puzzleId,
            'rating' => $this->priors->priorForPuzzle($puzzle),
            'rd' => BoardPriors::IMPORT_RD,
            'volatility' => RatingStore::DEFAULT_VOLATILITY,
            'attempts' => 0,
            'updated_at' => Carbon::now('UTC'),
        ]]);

        /** @var BoardRating $board */
        $board = BoardRating::query()->lockForUpdate()->findOrFail($puzzleId);

        return $board;
    }

    /**
     * GDPR: anonymization deletes the ratings row and disowns the audit
     * trail; a late queued event must not resurrect either.
     */
    private function userIsActive(string $userId): bool
    {
        return User::query()->whereKey($userId)->whereNull('anonymized_at')->exists();
    }
}
