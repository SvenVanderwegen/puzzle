<?php

declare(strict_types=1);

namespace App\Domain\Streaks;

use App\Domain\Ratings\Events\FailedDailyRecorded;
use App\Models\DailyPuzzle;
use App\Models\Streak;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The streaks:rollover engine, run right after UTC midnight (ADR-0002).
 *
 * For every streak holder who did not solve yesterday it applies the same walk
 * the solve path uses: covered days (frozen, amnestied, unpublished) pass; one
 * freeze per calendar month is auto-consumed (appended to frozen_dates);
 * anything else resets the streak. The walk starts at last_daily_date + 1, so
 * a missed scheduler day is caught up on the next run, and re-running the same
 * day changes nothing (judged days are in frozen_dates already).
 *
 * It also emits the RATING.md §3 failed-daily hook: one FailedDailyRecorded
 * per user who stamped a fetch anchor for yesterday's board but produced no
 * valid solve. Amnestied dailies never emit (a pulled board is not a loss).
 */
final class StreakRollover
{
    public function __construct(private readonly StreakService $streaks) {}

    /**
     * @return array{judged: int, frozen: int, reset: int, failed_daily_events: int}
     */
    public function run(string $asOfDate): array
    {
        $yesterday = CarbonImmutable::parse($asOfDate, 'UTC')->subDay()->format('Y-m-d');

        $judged = 0;
        $frozen = 0;
        $reset = 0;

        $atRisk = Streak::query()
            ->where('current_len', '>', 0)
            ->whereNotNull('last_daily_date')
            ->where('last_daily_date', '<', $yesterday)
            ->orderBy('user_id')
            ->lazyById(200, 'user_id');

        foreach ($atRisk as $candidate) {
            DB::transaction(function () use ($candidate, $yesterday, &$judged, &$frozen, &$reset): void {
                /** @var Streak|null $row */
                $row = Streak::query()->lockForUpdate()->find($candidate->user_id);

                if ($row === null || $row->current_len < 1 || $row->last_daily_date === null) {
                    return;
                }

                $last = $row->last_daily_date->format('Y-m-d');

                if ($last >= $yesterday) {
                    return; // Solved in the meantime.
                }

                $frozenBefore = count($row->frozen_dates);
                $survived = $this->streaks->walkGap($row, $last, $yesterday);

                $judged++;

                if (! $survived) {
                    $reset++;
                } elseif (count($row->frozen_dates) > $frozenBefore) {
                    $frozen++;
                }

                if ($row->isDirty()) {
                    $row->updated_at = Carbon::now('UTC');
                    $row->save();
                }
            });
        }

        return [
            'judged' => $judged,
            'frozen' => $frozen,
            'reset' => $reset,
            'failed_daily_events' => $this->emitFailedDailies($yesterday),
        ];
    }

    /**
     * At-least-once delivery: a re-run re-emits, and the WS-08 listener must
     * dedupe per (user_id, date) — see FailedDailyRecorded.
     */
    private function emitFailedDailies(string $date): int
    {
        /** @var DailyPuzzle|null $daily */
        $daily = DailyPuzzle::query()->find($date);

        if ($daily === null || $daily->amnesty) {
            return 0;
        }

        $userIds = DB::table('puzzle_fetches')
            ->join('users', 'users.id', '=', 'puzzle_fetches.user_id')
            ->whereNull('users.anonymized_at')
            ->where('puzzle_fetches.puzzle_id', $daily->puzzle_id)
            ->whereNotExists(function ($query) use ($daily): void {
                $query->select(DB::raw('1'))
                    ->from('solves')
                    ->whereColumn('solves.user_id', 'puzzle_fetches.user_id')
                    ->where('solves.puzzle_id', $daily->puzzle_id)
                    ->where('solves.mode', 'daily')
                    ->where('solves.valid', true);
            })
            ->orderBy('puzzle_fetches.user_id')
            ->pluck('puzzle_fetches.user_id');

        $emitted = 0;

        foreach ($userIds as $userId) {
            if (! is_string($userId)) {
                continue;
            }

            FailedDailyRecorded::dispatch($userId, $date, $daily->puzzle_id);
            $emitted++;
        }

        return $emitted;
    }
}
