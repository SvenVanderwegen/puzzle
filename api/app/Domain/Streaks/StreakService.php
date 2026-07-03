<?php

declare(strict_types=1);

namespace App\Domain\Streaks;

use App\Models\DailyPuzzle;
use App\Models\Streak;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Streak progression — all day math is UTC calendar arithmetic (ADR-0002).
 *
 * Rules (brief + decisions.md):
 * - The streak increments on the first valid solve of the CURRENT UTC day's
 *   daily. Archive solves never move it.
 * - A missed day survives if it is covered: already in frozen_dates, amnestied
 *   (daily_puzzles.amnesty — pulled boards never break streaks, WS-18), or no
 *   daily was published at all (a content outage is not the player's fault).
 * - Otherwise one freeze can be auto-consumed. Freezes are earned one per
 *   calendar month: freeze_available_at (NULL = available now) is bumped to the
 *   first day of the following month when a freeze is consumed.
 * - streaks:rollover applies the same walk right after UTC midnight; the solve
 *   path replays it defensively so a missed scheduler run cannot corrupt state.
 */
final class StreakService
{
    /**
     * Streak summary per contracts/openapi.yaml #/components/schemas/Streak.
     *
     * @return array{current: int, best: int, last_daily_date: string|null, freeze_available: bool, safe_until: string|null}
     */
    public function summaryFor(string $userId): array
    {
        /** @var Streak|null $row */
        $row = Streak::query()->find($userId);

        return [
            'current' => $row->current_len ?? 0,
            'best' => $row->best_len ?? 0,
            'last_daily_date' => $row?->last_daily_date?->format('Y-m-d'),
            'freeze_available' => $row === null
                || $row->freeze_available_at === null
                || $row->freeze_available_at->format('Y-m-d') <= CarbonImmutable::now('UTC')->format('Y-m-d'),
            'safe_until' => $row === null ? null : $this->safeUntil($row),
        ];
    }

    /**
     * Credit a valid daily solve of the current UTC day. Must run inside the
     * solve transaction; locks the streaks row. No-op if today is already
     * credited. Returns the resulting streak summary fields.
     */
    public function creditDailySolve(string $userId, string $today): void
    {
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

        $last = $row->last_daily_date?->format('Y-m-d');

        if ($last !== null && $last >= $today) {
            return; // Already credited (the partial unique index makes this unreachable in practice).
        }

        $survived = true;

        if ($row->current_len > 0 && $last !== null) {
            $yesterday = CarbonImmutable::parse($today, 'UTC')->subDay()->format('Y-m-d');
            $survived = $this->walkGap($row, $last, $yesterday);
        }

        $row->current_len = $survived ? $row->current_len + 1 : 1;
        $row->best_len = max($row->best_len, $row->current_len);
        $row->last_daily_date = Carbon::parse($today, 'UTC');
        $row->updated_at = Carbon::now('UTC');
        $row->save();
    }

    /**
     * The UTC-midnight rollover walk for one streak row: judge every day from
     * the day after last_daily_date through $throughDate inclusive. Mutates the
     * row in memory (frozen_dates, freeze_available_at, current_len on death)
     * but does not save. Returns whether the streak survived.
     *
     * Idempotent: judged days end up in frozen_dates, so a second pass over the
     * same span changes nothing.
     */
    public function walkGap(Streak $row, string $lastDate, string $throughDate): bool
    {
        $day = CarbonImmutable::parse($lastDate, 'UTC')->addDay();
        $through = CarbonImmutable::parse($throughDate, 'UTC');

        while ($day->lessThanOrEqualTo($through)) {
            $date = $day->format('Y-m-d');

            if (! $this->isCovered($row, $date)) {
                if (! $this->freezeAvailableOn($row, $date)) {
                    $row->current_len = 0;

                    return false;
                }

                $row->frozen_dates = [...$row->frozen_dates, $date];
                $row->freeze_available_at = Carbon::parse($day->startOfMonth()->addMonth()->format('Y-m-d'), 'UTC');
            }

            $day = $day->addDay();
        }

        return true;
    }

    /**
     * The instant the streak dies if the player stops solving now — the first
     * future UTC midnight the rollover walk cannot cover. Simulates freeze
     * earn/consume across month boundaries; unpublished FUTURE days count as
     * normal days (the calendar horizon is not a safety promise).
     */
    private function safeUntil(Streak $row): ?string
    {
        if ($row->current_len < 1 || $row->last_daily_date === null) {
            return null;
        }

        $today = CarbonImmutable::now('UTC')->startOfDay();
        $day = CarbonImmutable::parse($row->last_daily_date->format('Y-m-d'), 'UTC')->addDay();
        $freezeAvailableAt = $row->freeze_available_at?->format('Y-m-d');
        $frozen = array_flip($row->frozen_dates);

        // Bounded: the freeze simulation covers at most one day per month, so
        // the walk exits quickly; the cap is a pure safety net.
        for ($i = 0; $i < 400; $i++) {
            $date = $day->format('Y-m-d');

            $covered = isset($frozen[$date]);

            if (! $covered && $day->lessThan($today)) {
                // Past days are judged like rollover judges them.
                $daily = DailyPuzzle::query()->find($date);
                $covered = $daily === null || $daily->amnesty;
            } elseif (! $covered) {
                $daily = DailyPuzzle::query()->find($date);
                $covered = $daily !== null && $daily->amnesty;
            }

            if (! $covered) {
                if ($freezeAvailableAt !== null && $freezeAvailableAt > $date) {
                    // Day $date stays unsolved and uncoverable: the streak dies
                    // at the rollover following it.
                    return $day->addDay()->startOfDay()->toISOString();
                }

                $freezeAvailableAt = $day->startOfMonth()->addMonth()->format('Y-m-d');
            }

            $day = $day->addDay();
        }

        return $day->startOfDay()->toISOString();
    }

    private function isCovered(Streak $row, string $date): bool
    {
        if (in_array($date, $row->frozen_dates, true)) {
            return true;
        }

        /** @var DailyPuzzle|null $daily */
        $daily = DailyPuzzle::query()->find($date);

        return $daily === null || $daily->amnesty;
    }

    private function freezeAvailableOn(Streak $row, string $date): bool
    {
        $availableAt = $row->freeze_available_at?->format('Y-m-d');

        return $availableAt === null || $availableAt <= $date;
    }
}
