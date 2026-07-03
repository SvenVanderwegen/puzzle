<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Ratings\RatingService;
use App\Domain\Solves\Board;
use App\Domain\Solves\BurnValidator;
use App\Domain\Solves\InvalidBoardSpec;
use App\Domain\Streaks\StreakService;
use App\Models\DailyPuzzle;
use App\Models\Puzzle;
use App\Models\Solve;
use App\Models\Streak;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * POST /me/import — the anonymous→account merge (contracts/openapi.yaml
 * importLocalRecord; closes critique #11's fabrication hole).
 *
 * The local record is a CLAIM, not evidence, so the server re-derives
 * everything it can and caps what it cannot:
 * - idempotent per item via client_solve_id (solves_user_client_unique);
 * - every daily item re-validated with BurnValidator against the known board
 *   (vectors are law); anything else is dropped with a per-item code;
 * - a claimed solve time before the board's published_at (or in the future)
 *   is a provable lie -> date_ineligible, dropped entirely;
 * - streak credit: only days solved ON their own UTC day (mirrors the live
 *   rule "archive solves never move the streak"), newest consecutive run
 *   only, capped at MAX_STREAK_CREDIT_DAYS;
 * - stored rows carry imported=true: percentile-ineligible (daily_stats is
 *   never touched here) and excluded from the live rating pipeline; the
 *   rating is instead SEEDED by replaying RATING.md §3/§4 at half weight
 *   (RatingService::applyImportedSolve), chronologically by claimed time;
 * - endless items merge as personal stats only — there is no board to
 *   re-validate, so they can never move a rating.
 *
 * A solution gathered from someone else's share page therefore buys nothing
 * a legitimate archive solve would not: one imported solve per board per
 * account, half-weight rating movement, no percentile row, and at most
 * seven streak days from the newest consecutive run.
 */
final class LocalRecordImporter
{
    /**
     * Anti-fabrication ceiling (openapi importLocalRecord description,
     * ImportResult.credited_days maximum): imports can never certify more
     * than one week of streak history. Enforced twice: per call (newest
     * consecutive run, ≤ 7 days) and absolutely (only dates within the
     * trailing 7-day UTC window are streak-eligible), so splitting a
     * fabricated history across many calls stacks nothing.
     */
    public const MAX_STREAK_CREDIT_DAYS = 7;

    public function __construct(
        private readonly BurnValidator $validator,
        private readonly RatingService $ratings,
        private readonly StreakService $streaks,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items  validated importLocalRecord items
     * @return array{results: list<array{client_solve_id: string, status: string}>, credited_days: int, streak: array<string, mixed>}
     */
    public function import(User $user, array $items): array
    {
        $now = CarbonImmutable::now('UTC');

        return DB::transaction(function () use ($user, $items, $now): array {
            // Serialize concurrent imports per user and pin the GDPR lock
            // order (users -> ratings -> board_ratings, see RatingService):
            // two racing uploads of the same log must resolve to duplicates,
            // never to unique-violation 500s.
            User::query()->whereKey($user->id)->lockForUpdate()->exists();

            $results = [];
            /** @var list<array{0: Solve, 1: string}> $seedQueue solve + claimed solved_at (rating replay order) */
            $seedQueue = [];
            /** @var list<string> $streakDates */
            $streakDates = [];
            /** @var array<string, true> $seenIds in-batch client_solve_id dedupe */
            $seenIds = [];

            foreach ($items as $item) {
                /** @var string $rawId */
                $rawId = $item['client_solve_id'];
                $clientSolveId = Str::lower($rawId);

                $status = $this->settleItem($user, $clientSolveId, $item, $now, $seenIds, $seedQueue, $streakDates);
                $seenIds[$clientSolveId] = true;

                $results[] = ['client_solve_id' => $clientSolveId, 'status' => $status->value];
            }

            // Seed the rating chronologically by CLAIMED time (stable on
            // ties), so the Glicko-2 chain replays the guest history in the
            // order it was played (RATING.md §5).
            usort($seedQueue, fn (array $a, array $b): int => [$a[1], $a[0]->id] <=> [$b[1], $b[0]->id]);

            foreach ($seedQueue as [$solve]) {
                $this->ratings->applyImportedSolve($solve->id);
            }

            $creditedDays = $this->mergeStreak($user->id, $streakDates, $now);

            return [
                'results' => $results,
                'credited_days' => $creditedDays,
                'streak' => $this->streaks->summaryFor($user->id),
            ];
        });
    }

    /**
     * Classifies one item and stores it when it earns storage. Appends to
     * the rating seed queue / streak dates for credited dailies.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, true>  $seenIds
     * @param  list<array{0: Solve, 1: string}>  $seedQueue
     * @param  list<string>  $streakDates
     */
    private function settleItem(
        User $user,
        string $clientSolveId,
        array $item,
        CarbonImmutable $now,
        array $seenIds,
        array &$seedQueue,
        array &$streakDates,
    ): ImportItemStatus {
        // Version 7 only, exactly like the POST /solves Idempotency-Key
        // (ADR-0021): the shared client_solve_id namespace keeps the
        // reserved UUIDv8 failed-daily anchors structurally unclaimable.
        if (! Str::isUuid($clientSolveId, 7)) {
            return ImportItemStatus::Invalid;
        }

        if (isset($seenIds[$clientSolveId]) || $this->knownClientSolveId($user->id, $clientSolveId)) {
            return ImportItemStatus::Duplicate;
        }

        /** @var string $mode */
        $mode = $item['mode'];
        /** @var string $solvedAtRaw */
        $solvedAtRaw = $item['solved_at'];
        $solvedAt = CarbonImmutable::parse($solvedAtRaw)->utc();

        if ($solvedAt->greaterThan($now)) {
            // A solve claimed from the future is a fabricated timestamp.
            return ImportItemStatus::DateIneligible;
        }

        if ($mode === 'endless') {
            $this->storeSolve($user, $clientSolveId, null, 'endless', $item, $now);

            return ImportItemStatus::StatsOnly;
        }

        $date = $item['date'] ?? null;

        if (! is_string($date)) {
            return ImportItemStatus::Invalid; // A daily claim without its incident date.
        }

        if ($date > $now->format('Y-m-d')) {
            // Future incidents are unpublished; do not leak their existence
            // (same posture as GET /daily/{date}).
            return ImportItemStatus::BoardUnknown;
        }

        /** @var DailyPuzzle|null $daily */
        $daily = DailyPuzzle::query()->find($date);

        if ($daily === null) {
            return ImportItemStatus::BoardUnknown;
        }

        if ($daily->published_at->greaterThan($solvedAt)) {
            // Claimed to be solved before the board existed (critique #11).
            return ImportItemStatus::DateIneligible;
        }

        /** @var Puzzle|null $puzzle */
        $puzzle = Puzzle::query()->find($daily->puzzle_id);

        if ($puzzle === null) {
            return ImportItemStatus::BoardUnknown; // FK-impossible; defensive.
        }

        try {
            $board = Board::fromArray($puzzle->spec);
        } catch (InvalidBoardSpec) {
            Log::critical('burnfront.content: stored puzzle spec is not a valid board', [
                'puzzle_id' => $puzzle->id,
            ]);

            return ImportItemStatus::BoardUnknown;
        }

        /** @var string $shaded */
        $shaded = $item['shaded'];

        if (strlen($shaded) !== $board->cellCount() || ! $this->validator->verdict($board, $shaded)->valid) {
            return ImportItemStatus::Invalid;
        }

        if (hash('sha256', $shaded) !== $puzzle->solution_sha256) {
            // Same tripwire as the live path: a BFS-valid shading of a
            // certified unique board MUST hash to the published solution.
            Log::critical('burnfront.content: valid shading does not match solution_sha256', [
                'puzzle_id' => $puzzle->id,
                'client_solve_id' => $clientSolveId,
            ]);
        }

        if ($this->alreadySolved($user->id, $puzzle->id)) {
            // One valid daily per account (solves_one_valid_daily): a fresh
            // client_solve_id cannot re-credit a contained incident.
            return ImportItemStatus::Duplicate;
        }

        $solve = $this->storeSolve($user, $clientSolveId, $puzzle->id, 'daily', $item, $now);

        $hints = $this->hintsOf($item);

        if ($hints['s3'] === 0) {
            // Stage-3-hinted solves are unrated everywhere (RATING.md §3).
            $seedQueue[] = [$solve, $solvedAt->toIso8601String()];
        }

        if ($solvedAt->format('Y-m-d') === $date
            && $date >= $now->subDays(self::MAX_STREAK_CREDIT_DAYS - 1)->format('Y-m-d')) {
            // Streak days are days solved ON their own UTC day — archive
            // solves never move the streak, imported or live — AND within
            // the trailing 7-day window. The window is what makes the cap
            // hold across calls: without it, split batches of ever-older
            // dates could union backward 7 days at a time without bound.
            // Older days still merge as stats + rating, never as streak.
            $streakDates[] = $date;
        }

        return ImportItemStatus::Credited;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function storeSolve(
        User $user,
        string $clientSolveId,
        ?string $puzzleId,
        string $mode,
        array $item,
        CarbonImmutable $now,
    ): Solve {
        $hints = $this->hintsOf($item);

        /** @var string $shaded */
        $shaded = $item['shaded'];
        /** @var int $clientMs */
        $clientMs = $item['client_ms'];

        $solve = new Solve([
            'user_id' => $user->id,
            'puzzle_id' => $puzzleId,
            'mode' => $mode,
            'client_solve_id' => $clientSolveId,
            'shaded_bits' => $shaded,
            'client_ms' => $clientMs,
            // No fetch anchor exists for a local solve, so there is no
            // verifiable window: official_ms stays NULL and the row is
            // percentile-ineligible via imported=true regardless.
            'official_ms' => null,
            'started_at' => null,
            'received_at' => $now,
            'valid' => true,
            'reject_reason' => null,
            'suspect' => false,
            'imported' => true,
            'hints_s1' => $hints['s1'],
            'hints_s2' => $hints['s2'],
            'hints_s3' => $hints['s3'],
            'undo_count' => 0,
        ]);

        $solve->save();

        return $solve;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{s1: int, s2: int, s3: int}
     */
    private function hintsOf(array $item): array
    {
        $hints = $item['hints'] ?? null;

        if (! is_array($hints)) {
            return ['s1' => 0, 's2' => 0, 's3' => 0];
        }

        return [
            's1' => is_int($hints['s1'] ?? null) ? $hints['s1'] : 0,
            's2' => is_int($hints['s2'] ?? null) ? $hints['s2'] : 0,
            's3' => is_int($hints['s3'] ?? null) ? $hints['s3'] : 0,
        ];
    }

    private function knownClientSolveId(string $userId, string $clientSolveId): bool
    {
        return Solve::query()
            ->where('user_id', $userId)
            ->where('client_solve_id', $clientSolveId)
            ->exists();
    }

    private function alreadySolved(string $userId, string $puzzleId): bool
    {
        return Solve::query()
            ->where('user_id', $userId)
            ->where('puzzle_id', $puzzleId)
            ->where('mode', 'daily')
            ->where('valid', true)
            ->exists();
    }

    /**
     * Merges the imported same-day dates into the streaks row.
     *
     * Only the NEWEST consecutive run counts, capped at 7 days; it is
     * range-unioned with the live streak when they touch, gap-judged with
     * the exact rollover walk (StreakService::walkGap — frozen, amnestied
     * and unpublished days pass, one freeze per month may burn) when they
     * do not, and finally judged up to yesterday so a stale import cannot
     * resurrect a streak the rollover rules already killed.
     *
     * @param  list<string>  $dates  Y-m-d days credited AND solved on their own day
     * @return int days this import added to the resulting current streak (0..7)
     */
    private function mergeStreak(string $userId, array $dates, CarbonImmutable $now): int
    {
        if ($dates === []) {
            return 0;
        }

        $today = $now->format('Y-m-d');

        $dates = array_values(array_unique($dates));
        rsort($dates);

        $run = [$dates[0]];
        $cursor = CarbonImmutable::parse($dates[0], 'UTC');
        $count = count($dates);

        for ($i = 1; $i < $count && count($run) < self::MAX_STREAK_CREDIT_DAYS; $i++) {
            $previous = $cursor->subDay()->format('Y-m-d');

            if ($dates[$i] !== $previous) {
                break;
            }

            $run[] = $previous;
            $cursor = $cursor->subDay();
        }

        $end = $run[0];
        $start = $run[count($run) - 1];

        /** @var Streak|null $row */
        $row = Streak::query()->lockForUpdate()->find($userId);

        if ($row === null) {
            $row = new Streak([
                'user_id' => $userId,
                'current_len' => 0,
                'best_len' => 0,
                'frozen_dates' => [],
            ]);
        }

        $existingLen = $row->current_len;
        $existingEnd = $existingLen > 0 ? $row->last_daily_date?->format('Y-m-d') : null;
        $existingStart = null;

        if ($existingEnd === null) {
            [$newStart, $newEnd] = [$start, $end];
        } else {
            $existingStart = CarbonImmutable::parse($existingEnd, 'UTC')->subDays($existingLen - 1)->format('Y-m-d');

            if ($start <= $this->dayAfter($existingEnd) && $existingStart <= $this->dayAfter($end)) {
                // Touching or overlapping: one contiguous union.
                [$newStart, $newEnd] = [min($start, $existingStart), max($end, $existingEnd)];
            } elseif ($end > $existingEnd) {
                // Imported run is newer, disjoint: judge the gap like
                // rollover would (may consume a freeze; zeroes current_len
                // on death).
                $bridged = $this->streaks->walkGap($row, $existingEnd, $this->dayBefore($start));
                [$newStart, $newEnd] = [$bridged ? $existingStart : $start, $end];
            } else {
                // Imported run is older, disjoint: the live streak stands;
                // the run is history and can only raise the best.
                $row->best_len = max($row->best_len, count($run));

                if ($row->isDirty()) {
                    $row->updated_at = Carbon::now('UTC');
                    $row->save();
                }

                return 0;
            }
        }

        $row->current_len = (int) CarbonImmutable::parse($newStart, 'UTC')
            ->diffInDays(CarbonImmutable::parse($newEnd, 'UTC')) + 1;
        $row->best_len = max($row->best_len, $row->current_len);
        $row->last_daily_date = Carbon::parse($newEnd, 'UTC');

        if ($newEnd < $today) {
            // Judge the trailing gap immediately (rollover semantics: keeps
            // last_daily_date, zeroes current_len on death) — an import must
            // not resurrect a streak that already died.
            $this->streaks->walkGap($row, $newEnd, $this->dayBefore($today));
        }

        $row->updated_at = Carbon::now('UTC');
        $row->save();

        return $this->creditedDays($run, $row, $existingStart, $existingEnd);
    }

    /**
     * Days of the imported run that ended up inside the FINAL current-streak
     * range and were not already covered by the pre-merge streak.
     *
     * @param  list<string>  $run
     */
    private function creditedDays(array $run, Streak $row, ?string $existingStart, ?string $existingEnd): int
    {
        if ($row->current_len < 1 || $row->last_daily_date === null) {
            return 0;
        }

        $finalEnd = $row->last_daily_date->format('Y-m-d');
        $finalStart = CarbonImmutable::parse($finalEnd, 'UTC')
            ->subDays($row->current_len - 1)
            ->format('Y-m-d');

        $credited = 0;

        foreach ($run as $date) {
            $inFinal = $date >= $finalStart && $date <= $finalEnd;
            $preCovered = $existingStart !== null && $existingEnd !== null
                && $date >= $existingStart && $date <= $existingEnd;

            if ($inFinal && ! $preCovered) {
                $credited++;
            }
        }

        return min(self::MAX_STREAK_CREDIT_DAYS, $credited);
    }

    private function dayAfter(string $date): string
    {
        return CarbonImmutable::parse($date, 'UTC')->addDay()->format('Y-m-d');
    }

    private function dayBefore(string $date): string
    {
        return CarbonImmutable::parse($date, 'UTC')->subDay()->format('Y-m-d');
    }
}
