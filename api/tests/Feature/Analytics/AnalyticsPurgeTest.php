<?php

declare(strict_types=1);

use App\Models\AnalyticsEvent;
use App\Models\FrontendError;

beforeEach(function (): void {
    // Retention boundary for events: now-13mo = 2025-06-03, so whole months
    // before 2025-06 (i.e. May 2025 and earlier) are aggregated then purged.
    $this->travelTo('2026-07-03 12:00:00 UTC');
});

/**
 * @param  array<string, mixed>  $props
 */
function rawEventRow(string $anonId, string $name, string $ts, array $props = []): void
{
    AnalyticsEvent::query()->create([
        'anon_id' => $anonId,
        'name' => $name,
        'props' => $props,
        'created_at' => $ts,
    ]);
}

test('expired months are rolled up per (month, name) and raw rows deleted', function (): void {
    // April 2025 — fully expired.
    rawEventRow('anon-april-a1', 'solve_complete', '2025-04-05 10:00:00+00', ['puzzle_id' => 'p1', 'ms' => 1000, 'hint_stages' => 1, 'undo_count' => 0, 'wrong_checks' => 0, 'first' => true]);
    rawEventRow('anon-april-a1', 'solve_complete', '2025-04-12 10:00:00+00', ['puzzle_id' => 'p2', 'ms' => 2000, 'hint_stages' => 0, 'undo_count' => 1, 'wrong_checks' => 0, 'first' => false]);
    rawEventRow('anon-april-a2', 'solve_complete', '2025-04-20 10:00:00+00', ['puzzle_id' => 'p3', 'ms' => 3000, 'hint_stages' => 2, 'undo_count' => 0, 'wrong_checks' => 2, 'first' => true]);
    rawEventRow('anon-april-a1', 'first_seen', '2025-04-05 09:00:00+00');

    // May 2025 — fully expired.
    rawEventRow('anon-may-b1', 'replay_watched', '2025-05-02 10:00:00+00', ['fraction' => 0.5]);
    rawEventRow('anon-may-b2', 'replay_watched', '2025-05-03 10:00:00+00', ['fraction' => 1.0]);

    // June 2025 — some rows are older than 13 months to the day, but the
    // month is not behind the whole-month boundary yet: kept until 2026-08.
    rawEventRow('anon-june-c1', 'solve_complete', '2025-06-02 10:00:00+00', ['puzzle_id' => 'p4', 'ms' => 4000, 'hint_stages' => 0, 'undo_count' => 0, 'wrong_checks' => 0, 'first' => true]);

    // Fresh row.
    rawEventRow('anon-fresh-d1', 'first_seen', '2026-07-03 11:00:00+00');

    $this->artisan('analytics:purge')->assertSuccessful();

    // Raw April/May rows are gone; June and fresh rows survive.
    expect(AnalyticsEvent::query()->where('created_at', '<', '2025-06-01')->where('anon_id', '<>', '_system')->count())->toBe(0)
        ->and(AnalyticsEvent::query()->where('anon_id', 'like', 'anon-june-%')->count())->toBe(1)
        ->and(AnalyticsEvent::query()->where('anon_id', 'like', 'anon-fresh-%')->count())->toBe(1);

    // One rollup row per (month, name), under the reserved namespace.
    $rollups = AnalyticsEvent::query()->where('anon_id', '_system')->orderBy('created_at')->orderBy('name')->get();
    expect($rollups->pluck('name')->all())->toBe(['_rollup.first_seen', '_rollup.solve_complete', '_rollup.replay_watched'])
        ->and($rollups->every(fn (AnalyticsEvent $row): bool => $row->user_id === null))->toBeTrue();

    /** @var AnalyticsEvent $solves */
    $solves = $rollups->firstWhere('name', '_rollup.solve_complete');
    // Hand-check: 3 solves by 2 anon ids; ms 1000/2000/3000 → median 2000;
    // hint_stages 1+0+2 = 3; first true/false/true → 2.
    expect($solves->props)->toEqual([
        'month' => '2025-04',
        'count' => 3,
        'distinct_anon_ids' => 2,
        'median_ms' => 2000,
        'sum_hint_stages' => 3,
        'first_count' => 2,
    ]);

    /** @var AnalyticsEvent $replays */
    $replays = $rollups->firstWhere('name', '_rollup.replay_watched');
    // fractions 0.5 and 1.0 → median 0.75.
    expect($replays->props)->toEqual([
        'month' => '2025-05',
        'count' => 2,
        'distinct_anon_ids' => 2,
        'median_fraction' => 0.75,
    ]);

    /** @var AnalyticsEvent $seen */
    $seen = $rollups->firstWhere('name', '_rollup.first_seen');
    expect($seen->props)->toEqual(['month' => '2025-04', 'count' => 1, 'distinct_anon_ids' => 1]);
});

test('the purge is idempotent and never eats rollup rows, however old', function (): void {
    rawEventRow('anon-april-a1', 'share_clicked', '2025-04-05 10:00:00+00');

    $this->artisan('analytics:purge')->assertSuccessful();

    $after = AnalyticsEvent::query()->orderBy('id')->pluck('name')->all();
    expect($after)->toBe(['_rollup.share_clicked']);

    // Second run: the rollup row (created_at 2025-04-01, far past 13 months)
    // is excluded by namespace, not by age — nothing changes.
    $this->artisan('analytics:purge')->assertSuccessful();

    expect(AnalyticsEvent::query()->orderBy('id')->pluck('name')->all())->toBe(['_rollup.share_clicked']);
});

test('analytics:purge also applies the 90-day frontend_errors window', function (): void {
    FrontendError::query()->create(['message' => 'stale', 'created_at' => now()->subDays(91)]);
    FrontendError::query()->create(['message' => 'fresh', 'created_at' => now()->subDays(89)]);

    $this->artisan('analytics:purge')->assertSuccessful();

    expect(FrontendError::query()->pluck('message')->all())->toBe(['fresh']);
});
