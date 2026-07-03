<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\FrontendError;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * GDPR retention (docs/gdpr.md):
 *
 * - events: raw rows older than 13 months are aggregated, then deleted. The
 *   contract freeze (ADR-0011) forbids new tables, so the aggregate lives in
 *   `events` itself: one synthetic rollup row per (calendar month, event name)
 *   with name `_rollup.<name>` and anon_id `_system` — a namespace the API
 *   enum can never produce. Rollup rows are permanent and carry no per-user
 *   data (month, count, distinct anon ids, and the medians/sums the digest
 *   trends need).
 * - frontend_errors: rows older than 90 days are deleted outright.
 *
 * Purging works on whole calendar months so each (month, name) rollup is
 * written exactly once, from complete data: the boundary is the first day of
 * the month that contains now-13mo, i.e. a raw row is deleted at 13 months
 * plus at most one partial month. Idempotent — a second run finds no raw rows
 * behind the boundary and writes nothing.
 */
final class AnalyticsRetention
{
    /** Mirrors contracts/db-schema.sql comment: aggregated then row-purged. */
    public const int EVENT_RETENTION_MONTHS = 13;

    public const int ERROR_RETENTION_DAYS = 90;

    /**
     * @return array{rollups: int, deleted: int}
     */
    public function aggregateThenPurgeEvents(CarbonImmutable $now): array
    {
        $boundary = $now->utc()->subMonths(self::EVENT_RETENTION_MONTHS)->startOfMonth();

        return DB::transaction(function () use ($boundary): array {
            $rollups = $this->writeRollups($boundary);

            $deleted = $this->expiredRaw($boundary)->delete();

            return ['rollups' => $rollups, 'deleted' => $deleted];
        });
    }

    public function purgeFrontendErrors(CarbonImmutable $now): int
    {
        $cutoff = $now->utc()->subDays(self::ERROR_RETENTION_DAYS);

        return FrontendError::query()->where('created_at', '<', $cutoff)->toBase()->delete();
    }

    /**
     * Raw (non-rollup) rows past the whole-month retention boundary.
     *
     * @return Builder
     */
    private function expiredRaw(CarbonImmutable $boundary)
    {
        return AnalyticsEvent::query()->toBase()
            ->where('created_at', '<', $boundary)
            ->where('anon_id', '<>', EventCatalog::SYSTEM_ANON_ID)
            ->where('name', 'not like', str_replace('_', '\_', EventCatalog::ROLLUP_PREFIX).'%');
    }

    private function writeRollups(CarbonImmutable $boundary): int
    {
        /** @var list<object{month: string, name: string, n: int|string, actors: int|string}> $groups */
        $groups = $this->expiredRaw($boundary)
            ->selectRaw("to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM') AS month, name, count(*) AS n, count(DISTINCT anon_id) AS actors")
            ->groupBy('month', 'name')
            ->orderBy('month')
            ->orderBy('name')
            ->get()
            ->all();

        $rows = [];

        foreach ($groups as $group) {
            $props = [
                'month' => $group->month,
                'count' => (int) $group->n,
                'distinct_anon_ids' => (int) $group->actors,
                ...$this->extrasFor($group->month, $group->name),
            ];

            $rows[] = [
                'anon_id' => EventCatalog::SYSTEM_ANON_ID,
                'user_id' => null,
                'name' => EventCatalog::ROLLUP_PREFIX.$group->name,
                'props' => json_encode($props, JSON_THROW_ON_ERROR),
                // Stamped with the month it summarizes; rollups are excluded
                // from purging by name, never by age.
                'created_at' => CarbonImmutable::parse($group->month.'-01', 'UTC'),
            ];
        }

        if ($rows !== []) {
            AnalyticsEvent::query()->insert($rows);
        }

        return count($rows);
    }

    /**
     * Name-specific sums/medians the digest trends need once raw rows are
     * gone (WS-19 ruling). Medians via percentile_cont over the typed prop.
     *
     * @return array<string, float|int>
     */
    private function extrasFor(string $month, string $name): array
    {
        return match ($name) {
            'solve_complete' => [
                'median_ms' => (int) round($this->median($month, $name, 'ms')),
                'sum_hint_stages' => (int) $this->monthRows($month, $name)->sum(DB::raw("(props->>'hint_stages')::bigint")),
                'first_count' => (int) $this->monthRows($month, $name)->whereRaw("(props->>'first')::boolean")->count(),
            ],
            'board_abandoned' => [
                'median_ms' => (int) round($this->median($month, $name, 'ms')),
            ],
            'replay_watched' => [
                'median_fraction' => round($this->median($month, $name, 'fraction'), 4),
            ],
            default => [],
        };
    }

    /**
     * @param  'ms'|'fraction'  $prop
     */
    private function median(string $month, string $name, string $prop): float
    {
        $expression = match ($prop) {
            'ms' => "percentile_cont(0.5) WITHIN GROUP (ORDER BY (props->>'ms')::double precision) AS median",
            'fraction' => "percentile_cont(0.5) WITHIN GROUP (ORDER BY (props->>'fraction')::double precision) AS median",
        };

        $value = $this->monthRows($month, $name)
            ->selectRaw($expression)
            ->value('median');

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @return Builder
     */
    private function monthRows(string $month, string $name)
    {
        $start = CarbonImmutable::parse($month.'-01', 'UTC');

        return AnalyticsEvent::query()->toBase()
            ->where('name', $name)
            ->where('anon_id', '<>', EventCatalog::SYSTEM_ANON_ID)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $start->addMonth());
    }
}
