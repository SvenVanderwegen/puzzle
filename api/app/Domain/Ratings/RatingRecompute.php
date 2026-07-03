<?php

declare(strict_types=1);

namespace App\Domain\Ratings;

use App\Models\BoardRating;
use App\Models\Puzzle;
use App\Models\Rating;
use App\Models\RatingEvent;
use Illuminate\Support\Carbon;

/**
 * Deterministic replay of the rating_events audit stream (ratings:recompute).
 *
 * Ordering: rating_events.id. Every event row is inserted inside the same
 * transaction that holds the user and board row locks, so per-chain id order
 * equals the order the live updates were applied.
 *
 * Inputs per event come from the joined solve row (hints -> §3 outcome, mode
 * -> §4 weight) through the same code path the live update used; the event
 * row itself contributes the ordering, the endless board prior (the recorded
 * board_before — immune to tier-bound drift after the fact), and the user
 * before-state when the chain was disowned by anonymization.
 *
 * Chain states pass through Float4::quantize after every game: the live chain
 * reads its state back from float32 columns between games, and the replay
 * must round identically to reproduce it bit-for-bit (property-tested against
 * a simulated month, equal to 6 decimals).
 */
final class RatingRecompute
{
    public function __construct(
        private readonly Glicko2 $glicko2,
        private readonly BoardPriors $priors,
    ) {}

    /**
     * Replays the full stream — board chains are shared across users, so even
     * a single-user recompute must walk every event — then writes either
     * every ratings/board_ratings row touched, or (with $onlyUserId) exactly
     * that user's ratings row and nothing else.
     *
     * @return array{events: int, users: int, boards: int}
     */
    public function run(?string $onlyUserId = null): array
    {
        /** @var array<string, array{float, float, float, int}> $users user_id => [rating, rd, volatility, games] */
        $users = [];

        /** @var array<string, array{float, float, float, int}> $boards puzzle_id => [rating, rd, volatility, attempts] */
        $boards = [];

        $events = RatingEvent::query()
            ->join('solves', 'solves.id', '=', 'rating_events.solve_id')
            ->orderBy('rating_events.id')
            ->select([
                'rating_events.*',
                'solves.mode as solve_mode',
                'solves.hints_s1 as solve_hints_s1',
                'solves.hints_s2 as solve_hints_s2',
                'solves.reject_reason as solve_reject_reason',
                'solves.imported as solve_imported',
            ]);

        $replayed = 0;

        foreach ($events->cursor() as $event) {
            $this->replayEvent($event, $users, $boards);
            $replayed++;
        }

        $now = Carbon::now('UTC');
        $usersWritten = 0;
        $boardsWritten = 0;

        if ($onlyUserId !== null) {
            if (isset($users[$onlyUserId])) {
                $this->writeUser($onlyUserId, $users[$onlyUserId], $now);
                $usersWritten = 1;
            }
        } else {
            foreach ($users as $userId => $state) {
                $this->writeUser((string) $userId, $state, $now);
                $usersWritten++;
            }

            foreach ($boards as $puzzleId => $state) {
                $this->writeBoard((string) $puzzleId, $state, $now);
                $boardsWritten++;
            }
        }

        return ['events' => $replayed, 'users' => $usersWritten, 'boards' => $boardsWritten];
    }

    /**
     * @param  array<string, array{float, float, float, int}>  $users
     * @param  array<string, array{float, float, float, int}>  $boards
     */
    private function replayEvent(RatingEvent $event, array &$users, array &$boards): void
    {
        $rejectReason = $event->getAttribute('solve_reject_reason');
        $mode = $event->getAttribute('solve_mode');
        $hintsS1 = $event->getAttribute('solve_hints_s1');
        $hintsS2 = $event->getAttribute('solve_hints_s2');

        $isFailedDaily = $rejectReason === RatingService::FAILED_DAILY_REASON;

        $score = $isFailedDaily
            ? Outcome::FAILED_DAILY_SCORE
            : Outcome::solveScore(
                is_numeric($hintsS1) ? (int) $hintsS1 : 0,
                is_numeric($hintsS2) ? (int) $hintsS2 : 0,
            );

        $weight = $isFailedDaily ? 1.0 : Outcome::weightFor(is_string($mode) ? $mode : 'daily');

        // WS-20 imported seeds replayed at the same halved user weight the
        // live path applied (RatingService::applyImportedSolve); the board
        // side stays an ordinary weight-1.0 opponent in both paths.
        if ((bool) $event->getAttribute('solve_imported')) {
            $weight *= RatingService::IMPORTED_WEIGHT_FACTOR;
        }

        $userId = $event->user_id;
        $puzzleId = $event->puzzle_id;

        // Board "before": stored boards replay their own chain, seeded from
        // the puzzles row exactly like the live path; an endless board is one
        // game long and its recorded prior is authoritative.
        if ($puzzleId !== null) {
            $boards[$puzzleId] ??= $this->boardSeed($puzzleId, $event);
            $boardState = new Glicko2State($boards[$puzzleId][0], $boards[$puzzleId][1], $boards[$puzzleId][2]);
        } else {
            $boardState = new Glicko2State($event->board_before, BoardPriors::IMPORT_RD, RatingStore::DEFAULT_VOLATILITY);
        }

        // User "before": the replayed chain — or, when anonymization disowned
        // the event, the recorded before-values (the volatility is gone with
        // the chain, but the board side only needs mu and phi).
        if ($userId !== null) {
            $users[$userId] ??= [RatingStore::DEFAULT_RATING, RatingStore::DEFAULT_RD, RatingStore::DEFAULT_VOLATILITY, 0];
            $userState = new Glicko2State($users[$userId][0], $users[$userId][1], $users[$userId][2]);
        } else {
            $userState = new Glicko2State($event->user_before, $event->user_rd_before, RatingStore::DEFAULT_VOLATILITY);
        }

        $newUser = $this->glicko2->update($userState, [[
            'rating' => $boardState->rating,
            'rd' => $boardState->rd,
            'score' => $score,
        ]], $weight);

        $newBoard = $this->glicko2->update($boardState, [[
            'rating' => $userState->rating,
            'rd' => $userState->rd,
            'score' => 1.0 - $score,
        ]])->withRdFloor(RatingService::BOARD_RD_FLOOR);

        $quantized = Float4::quantize([
            $newUser->rating, $newUser->rd, $newUser->volatility,
            $newBoard->rating, $newBoard->rd, $newBoard->volatility,
        ]);

        if ($userId !== null) {
            $users[$userId] = [$quantized[0], $quantized[1], $quantized[2], $users[$userId][3] + 1];
        }

        if ($puzzleId !== null) {
            $boards[$puzzleId] = [$quantized[3], $quantized[4], $quantized[5], $boards[$puzzleId][3] + 1];
        }
    }

    /**
     * @return array{float, float, float, int}
     */
    private function boardSeed(string $puzzleId, RatingEvent $event): array
    {
        /** @var Puzzle|null $puzzle */
        $puzzle = Puzzle::query()->find($puzzleId);

        // A missing puzzles row cannot happen under the FK regime; the
        // recorded prior keeps a hypothetical orphan replayable.
        $prior = $puzzle !== null ? $this->priors->priorForPuzzle($puzzle) : $event->board_before;

        return [Float4::quantize([$prior])[0], BoardPriors::IMPORT_RD, RatingStore::DEFAULT_VOLATILITY, 0];
    }

    /**
     * @param  array{float, float, float, int}  $state
     */
    private function writeUser(string $userId, array $state, Carbon $now): void
    {
        Rating::query()->upsert(
            [[
                'user_id' => $userId,
                'rating' => $state[0],
                'rd' => $state[1],
                'volatility' => $state[2],
                'games' => $state[3],
                'updated_at' => $now,
            ]],
            ['user_id'],
            ['rating', 'rd', 'volatility', 'games', 'updated_at'],
        );
    }

    /**
     * @param  array{float, float, float, int}  $state
     */
    private function writeBoard(string $puzzleId, array $state, Carbon $now): void
    {
        BoardRating::query()->upsert(
            [[
                'puzzle_id' => $puzzleId,
                'rating' => $state[0],
                'rd' => $state[1],
                'volatility' => $state[2],
                'attempts' => $state[3],
                'updated_at' => $now,
            ]],
            ['puzzle_id'],
            ['rating', 'rd', 'volatility', 'attempts', 'updated_at'],
        );
    }
}
