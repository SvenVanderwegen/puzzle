<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Compiles the weekly owner digest (ADR-0008: the only reporting surface).
 *
 * Window: the 7 complete UTC days before the run day. Cohort metrics look
 * further back so their observation windows are complete — a cohort is never
 * graded on a day that has not finished:
 *
 * - D1: first_seen on run-day-8 .. run-day-2, returned = any event exactly
 *   1 day after the first_seen day.
 * - D7: first_seen on run-day-14 .. run-day-8, returned = any event exactly
 *   7 days after the first_seen day.
 * - Day-3 conversion: first_seen on run-day-10 .. run-day-4, converted =
 *   account_created within 72h of the first_seen event.
 *
 * Rollup rows (`_rollup.*` / `_system`, see AnalyticsRetention) are excluded
 * from every metric here — they are month aggregates, not user activity.
 *
 * @phpstan-type Ratio array{numerator: int, denominator: int}
 * @phpstan-type WeekdayRow array{date: string, weekday: string, solves: int, starts: int}
 * @phpstan-type ErrorRow array{message: string, count: int}
 * @phpstan-type Report array{
 *   window_start: string,
 *   window_end: string,
 *   activation: Ratio,
 *   median_ttfs_seconds: float|null,
 *   d1: Ratio,
 *   d7: Ratio,
 *   day3_conversion: Ratio,
 *   weekdays: list<WeekdayRow>,
 *   hint_stages_per_solve: float|null,
 *   share: Ratio,
 *   top_errors: list<ErrorRow>
 * }
 */
final class WeeklyDigest
{
    private const int WINDOW_DAYS = 7;

    private const int TOP_ERRORS = 5;

    /**
     * @return Report
     */
    public function compile(CarbonImmutable $now): array
    {
        $today = $now->utc()->startOfDay();
        $start = $today->subDays(self::WINDOW_DAYS);
        $end = $today;

        return [
            'window_start' => $start->toDateString(),
            'window_end' => $end->subDay()->toDateString(),
            'activation' => $this->activation($start, $end),
            'median_ttfs_seconds' => $this->medianTimeToFirstSolve($start, $end),
            'd1' => $this->retention(1, $today),
            'd7' => $this->retention(7, $today),
            'day3_conversion' => $this->day3Conversion($today),
            'weekdays' => $this->weekdays($start, $end),
            'hint_stages_per_solve' => $this->hintStagesPerSolve($start, $end),
            'share' => $this->shareRate($start, $end),
            'top_errors' => $this->topErrors($start, $end),
        ];
    }

    /**
     * Share of the window's new-visitor cohort that completed a solve on the
     * same UTC day they were first seen.
     *
     * @return Ratio
     */
    private function activation(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $cohort = $this->firstSeenBetween($start, $end);

        if ($cohort === []) {
            return ['numerator' => 0, 'denominator' => 0];
        }

        $solveDays = $this->activityDays(array_keys($cohort), $start, ['solve_complete']);
        $activated = 0;

        foreach ($cohort as $anonId => $firstSeen) {
            if (isset($solveDays[$anonId][$firstSeen->toDateString()])) {
                $activated++;
            }
        }

        return ['numerator' => $activated, 'denominator' => count($cohort)];
    }

    private function medianTimeToFirstSolve(CarbonImmutable $start, CarbonImmutable $end): ?float
    {
        $cohort = $this->firstSeenBetween($start, $end);

        if ($cohort === []) {
            return null;
        }

        /** @var array<string, string> $firstSolves anon_id => min solve_complete ts */
        $firstSolves = $this->rawEvents()
            ->where('name', 'solve_complete')
            ->whereIn('anon_id', array_keys($cohort))
            ->groupBy('anon_id')
            ->selectRaw('anon_id, min(created_at) AS first_solve')
            ->pluck('first_solve', 'anon_id')
            ->all();

        $deltas = [];

        foreach ($cohort as $anonId => $firstSeen) {
            if (! isset($firstSolves[$anonId])) {
                continue;
            }

            $firstSolve = CarbonImmutable::parse($firstSolves[$anonId])->utc();

            if ($firstSolve->lessThan($firstSeen)) {
                continue; // pre-first_seen solve (merged history); not a funnel gap
            }

            $deltas[] = (float) $firstSeen->diffInSeconds($firstSolve);
        }

        return self::median($deltas);
    }

    /**
     * Dk retention: cohort of first_seen days whose day+k is complete;
     * returned = any raw event exactly k days after the first_seen day.
     *
     * @return Ratio
     */
    private function retention(int $k, CarbonImmutable $today): array
    {
        $cohortStart = $today->subDays($k + self::WINDOW_DAYS);
        $cohortEnd = $today->subDays($k);

        $cohort = $this->firstSeenBetween($cohortStart, $cohortEnd);

        if ($cohort === []) {
            return ['numerator' => 0, 'denominator' => 0];
        }

        $days = $this->activityDays(array_keys($cohort), $cohortStart);
        $returned = 0;

        foreach ($cohort as $anonId => $firstSeen) {
            $target = $firstSeen->startOfDay()->addDays($k)->toDateString();

            if (isset($days[$anonId][$target])) {
                $returned++;
            }
        }

        return ['numerator' => $returned, 'denominator' => count($cohort)];
    }

    /**
     * @return Ratio
     */
    private function day3Conversion(CarbonImmutable $today): array
    {
        $cohort = $this->firstSeenBetween($today->subDays(3 + self::WINDOW_DAYS), $today->subDays(3));

        if ($cohort === []) {
            return ['numerator' => 0, 'denominator' => 0];
        }

        /** @var array<string, string> $accounts anon_id => min account_created ts */
        $accounts = $this->rawEvents()
            ->where('name', 'account_created')
            ->whereIn('anon_id', array_keys($cohort))
            ->groupBy('anon_id')
            ->selectRaw('anon_id, min(created_at) AS created')
            ->pluck('created', 'anon_id')
            ->all();

        $converted = 0;

        foreach ($cohort as $anonId => $firstSeen) {
            if (! isset($accounts[$anonId])) {
                continue;
            }

            $created = CarbonImmutable::parse($accounts[$anonId])->utc();

            if ($created->between($firstSeen, $firstSeen->addHours(72))) {
                $converted++;
            }
        }

        return ['numerator' => $converted, 'denominator' => count($cohort)];
    }

    /**
     * @return list<WeekdayRow>
     */
    private function weekdays(CarbonImmutable $start, CarbonImmutable $end): array
    {
        /** @var list<object{day: string, name: string, n: int|string}> $rows */
        $rows = $this->rawEvents()
            ->whereIn('name', ['solve_start', 'solve_complete'])
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw("to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD') AS day, name, count(*) AS n")
            ->groupBy('day', 'name')
            ->get()
            ->all();

        $byDay = [];

        foreach ($rows as $row) {
            $byDay[$row->day][$row->name] = (int) $row->n;
        }

        $out = [];

        for ($day = $start; $day->lessThan($end); $day = $day->addDay()) {
            $key = $day->toDateString();

            $out[] = [
                'date' => $key,
                'weekday' => $day->format('D'),
                'solves' => $byDay[$key]['solve_complete'] ?? 0,
                'starts' => $byDay[$key]['solve_start'] ?? 0,
            ];
        }

        return $out;
    }

    private function hintStagesPerSolve(CarbonImmutable $start, CarbonImmutable $end): ?float
    {
        $avg = $this->rawEvents()
            ->where('name', 'solve_complete')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw("avg((props->>'hint_stages')::double precision) AS avg_stages")
            ->value('avg_stages');

        return is_numeric($avg) ? (float) $avg : null;
    }

    /**
     * @return Ratio
     */
    private function shareRate(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $counts = fn (string $name): int => $this->rawEvents()
            ->where('name', $name)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->count();

        return ['numerator' => $counts('share_clicked'), 'denominator' => $counts('solve_complete')];
    }

    /**
     * @return list<ErrorRow>
     */
    private function topErrors(CarbonImmutable $start, CarbonImmutable $end): array
    {
        /** @var list<object{message: string, n: int|string}> $rows */
        $rows = DB::table('frontend_errors')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw('message, count(*) AS n')
            ->groupBy('message')
            ->orderByDesc('n')
            ->orderBy('message')
            ->limit(self::TOP_ERRORS)
            ->get()
            ->all();

        return array_map(
            static fn (object $row): array => ['message' => $row->message, 'count' => (int) $row->n],
            $rows,
        );
    }

    /**
     * Raw (non-rollup) analytics rows; see AnalyticsRetention's namespace.
     *
     * @return Builder
     */
    private function rawEvents()
    {
        return DB::table('events')
            ->where('anon_id', '<>', EventCatalog::SYSTEM_ANON_ID)
            ->where('name', 'not like', str_replace('_', '\_', EventCatalog::ROLLUP_PREFIX).'%');
    }

    /**
     * anon_id => first first_seen timestamp within [start, end).
     *
     * @return array<string, CarbonImmutable>
     */
    private function firstSeenBetween(CarbonImmutable $start, CarbonImmutable $end): array
    {
        /** @var array<string, string> $rows */
        $rows = $this->rawEvents()
            ->where('name', 'first_seen')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->groupBy('anon_id')
            ->selectRaw('anon_id, min(created_at) AS first_seen')
            ->pluck('first_seen', 'anon_id')
            ->all();

        $out = [];

        foreach ($rows as $anonId => $ts) {
            $out[$anonId] = CarbonImmutable::parse($ts)->utc();
        }

        return $out;
    }

    /**
     * Distinct UTC activity days per anon_id, from $from onward.
     *
     * @param  list<string>  $anonIds
     * @param  list<string>|null  $names  restrict to these event names
     * @return array<string, array<string, true>> anon_id => set of Y-m-d days
     */
    private function activityDays(array $anonIds, CarbonImmutable $from, ?array $names = null): array
    {
        $query = $this->rawEvents()
            ->whereIn('anon_id', $anonIds)
            ->where('created_at', '>=', $from);

        if ($names !== null) {
            $query->whereIn('name', $names);
        }

        /** @var list<object{anon_id: string, day: string}> $rows */
        $rows = $query
            ->selectRaw("DISTINCT anon_id, to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD') AS day")
            ->get()
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $out[$row->anon_id][$row->day] = true;
        }

        return $out;
    }

    /**
     * @param  list<float>  $values
     */
    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$mid]
            : ($values[$mid - 1] + $values[$mid]) / 2.0;
    }
}
