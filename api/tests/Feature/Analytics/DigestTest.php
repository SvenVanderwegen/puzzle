<?php

declare(strict_types=1);

use App\Domain\Analytics\Mail\WeeklyDigestMail;
use App\Models\AnalyticsEvent;
use App\Models\FrontendError;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;

beforeEach(function (): void {
    // Run day: 2026-07-15 (a Wednesday). Digest window: the 7 complete UTC
    // days 2026-07-08 (Wed) .. 2026-07-14 (Tue).
    $this->travelTo('2026-07-15 08:00:00 UTC');
    config()->set('analytics.owner_digest_email', 'owner@burnfront.test');
    Mail::fake();
});

/**
 * @param  array<string, mixed>  $props
 */
function digestEvent(string $anonId, string $name, string $ts, array $props = []): void
{
    AnalyticsEvent::query()->create([
        'anon_id' => $anonId,
        'name' => $name,
        'props' => $props,
        'created_at' => $ts,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function solveProps(array $overrides = []): array
{
    return [
        'puzzle_id' => 'p-fixture', 'ms' => 60000, 'hint_stages' => 0,
        'undo_count' => 0, 'wrong_checks' => 0, 'first' => true,
        ...$overrides,
    ];
}

/**
 * The hand-computed cohort fixture. Every expected digest number below is
 * derived in the comments — the test is the arithmetic witness the brief
 * demands for D1/D7.
 *
 * u1  first_seen 07-08 09:00 · solve 07-08 09:20 (hints 1) · hint_used 07-09
 *     · account_created 07-09 10:05 (25h after first_seen)
 * u2  first_seen 07-08 22:00 · solve 07-09 01:00 (hints 0)
 *     · account_created 07-12 01:00 (75h after first_seen — outside 72h)
 * u3  first_seen 07-10 12:00 · nothing else
 * u4  first_seen 07-13 08:00 · solve 07-13 09:00 (hints 2)
 * u5  first_seen 07-07 10:00 · share_clicked 07-08 11:00 · solve_start 07-14 09:00
 * u6  first_seen 07-03 15:00 · solve 07-10 16:00 (hints 1)
 * u7  first_seen 07-05 11:00 · solve_start 07-06 12:00
 * u8  first_seen 07-01 09:00 · nothing else
 */
function seedDigestCohort(): void
{
    digestEvent('anon-u1-0000', 'first_seen', '2026-07-08 09:00:00+00');
    digestEvent('anon-u1-0000', 'solve_complete', '2026-07-08 09:20:00+00', solveProps(['hint_stages' => 1]));
    digestEvent('anon-u1-0000', 'hint_used', '2026-07-09 10:00:00+00', ['stage' => 1]);
    digestEvent('anon-u1-0000', 'account_created', '2026-07-09 10:05:00+00', ['from_nudge' => true]);

    digestEvent('anon-u2-0000', 'first_seen', '2026-07-08 22:00:00+00');
    digestEvent('anon-u2-0000', 'solve_complete', '2026-07-09 01:00:00+00', solveProps(['hint_stages' => 0]));
    digestEvent('anon-u2-0000', 'account_created', '2026-07-12 01:00:00+00', ['from_nudge' => false]);

    digestEvent('anon-u3-0000', 'first_seen', '2026-07-10 12:00:00+00');

    digestEvent('anon-u4-0000', 'first_seen', '2026-07-13 08:00:00+00');
    digestEvent('anon-u4-0000', 'solve_complete', '2026-07-13 09:00:00+00', solveProps(['hint_stages' => 2]));

    digestEvent('anon-u5-0000', 'first_seen', '2026-07-07 10:00:00+00');
    digestEvent('anon-u5-0000', 'share_clicked', '2026-07-08 11:00:00+00');
    digestEvent('anon-u5-0000', 'solve_start', '2026-07-14 09:00:00+00');

    digestEvent('anon-u6-0000', 'first_seen', '2026-07-03 15:00:00+00');
    digestEvent('anon-u6-0000', 'solve_complete', '2026-07-10 16:00:00+00', solveProps(['hint_stages' => 1]));

    digestEvent('anon-u7-0000', 'first_seen', '2026-07-05 11:00:00+00');
    digestEvent('anon-u7-0000', 'solve_start', '2026-07-06 12:00:00+00');

    digestEvent('anon-u8-0000', 'first_seen', '2026-07-01 09:00:00+00');

    // A retention rollup row inside the window: month aggregates must never
    // leak into weekly metrics (excluded by the reserved namespace).
    digestEvent('_system', '_rollup.solve_complete', '2026-07-10 00:00:00+00', ['month' => '2025-05', 'count' => 999]);

    FrontendError::query()->create(['message' => 'TypeError: x is undefined', 'created_at' => '2026-07-09 10:00:00+00']);
    FrontendError::query()->create(['message' => 'TypeError: x is undefined', 'created_at' => '2026-07-10 10:00:00+00']);
    FrontendError::query()->create(['message' => 'TypeError: x is undefined', 'created_at' => '2026-07-11 10:00:00+00']);
    FrontendError::query()->create(['message' => 'ReferenceError: y', 'created_at' => '2026-07-12 10:00:00+00']);
    // Outside the window — not counted.
    FrontendError::query()->create(['message' => 'TypeError: x is undefined', 'created_at' => '2026-07-01 10:00:00+00']);
}

test('the weekly digest computes the fixture cohort correctly', function (): void {
    seedDigestCohort();

    $this->artisan('analytics:digest')->assertSuccessful();

    $sent = null;
    Mail::assertSent(WeeklyDigestMail::class, function (WeeklyDigestMail $mail) use (&$sent): bool {
        $sent = $mail;

        return $mail->hasTo('owner@burnfront.test');
    });

    /** @var WeeklyDigestMail $sent */
    $sent->assertSeeInText('Window: 2026-07-08 to 2026-07-14');

    // Activation — cohort: first_seen inside the window = u1, u2, u3, u4 (4).
    // Same-UTC-day solve: u1 (07-08) yes, u2 (solved 07-09, seen 07-08) no,
    // u3 no, u4 (07-13) yes → 2 of 4 = 50.0%.
    $sent->assertSeeInText('Activation (solve_complete on the first_seen day): 50.0% (2 of 4)');

    // Median time to first solve — same cohort, members with a first solve:
    // u1: 09:00→09:20 = 1200s; u2: 22:00→01:00(+1d) = 10800s; u4: 08:00→09:00
    // = 3600s. Median of [1200, 3600, 10800] = 3600s = 60.0 min.
    $sent->assertSeeInText('Median time to first solve: 60.0 min');

    // D1 — cohort: first_seen on 07-07..07-13 (day+1 complete): u5(07-07),
    // u1(07-08), u2(07-08), u3(07-10), u4(07-13) → 5.
    // Returned exactly 1 day after the first_seen day:
    //   u5: share_clicked 07-08 ✓ · u1: hint_used 07-09 ✓
    //   u2: solve_complete 07-09 ✓ · u3: nothing 07-11 ✗ · u4: nothing 07-14 ✗
    // → 3 of 5 = 60.0%.
    $sent->assertSeeInText('D1 retention: 60.0% (3 of 5)');

    // D7 — cohort: first_seen on 07-01..07-07 (day+7 complete): u8(07-01),
    // u6(07-03), u7(07-05), u5(07-07) → 4.
    // Returned exactly 7 days after the first_seen day:
    //   u8: nothing 07-08 ✗ · u6: solve_complete 07-10 ✓
    //   u7: nothing 07-12 ✗ · u5: solve_start 07-14 ✓
    // → 2 of 4 = 50.0%.
    $sent->assertSeeInText('D7 retention: 50.0% (2 of 4)');

    // Day-3 conversion — cohort: first_seen on 07-05..07-11 (72h window
    // complete): u7, u5, u1, u2, u3 → 5. account_created within 72h of the
    // first_seen event: u1 at +25h ✓; u2 at +75h ✗ → 1 of 5 = 20.0%.
    $sent->assertSeeInText('Day-3 account conversion: 20.0% (1 of 5)');

    // Completions by weekday (window solves/starts per UTC day).
    $sent->assertSeeInText('Wed 2026-07-08: 1 solve, 0 starts');
    $sent->assertSeeInText('Thu 2026-07-09: 1 solve, 0 starts');
    $sent->assertSeeInText('Fri 2026-07-10: 1 solve, 0 starts');
    $sent->assertSeeInText('Sat 2026-07-11: 0 solves, 0 starts');
    $sent->assertSeeInText('Mon 2026-07-13: 1 solve, 0 starts');
    $sent->assertSeeInText('Tue 2026-07-14: 0 solves, 1 start');

    // Hint stages per solve — window solves u1(1), u2(0), u6(1), u4(2): avg 1.00.
    $sent->assertSeeInText('Hint stages per solve: 1.00');

    // Share rate — 1 share_clicked / 4 solve_complete in the window.
    $sent->assertSeeInText('Share rate (share_clicked per solve_complete): 25.0% (1 of 4)');

    // Top frontend errors — window only (the 07-01 row is out).
    $sent->assertSeeInText('3 × TypeError: x is undefined');
    $sent->assertSeeInText('1 × ReferenceError: y');
});

test('the digest renders with no data at all', function (): void {
    $this->artisan('analytics:digest')->assertSuccessful();

    $sent = null;
    Mail::assertSent(WeeklyDigestMail::class, function (WeeklyDigestMail $mail) use (&$sent): bool {
        $sent = $mail;

        return true;
    });

    /** @var WeeklyDigestMail $sent */
    $sent->assertSeeInText('Activation (solve_complete on the first_seen day): n/a (empty cohort)');
    $sent->assertSeeInText('Median time to first solve: n/a (no first solves)');
    $sent->assertSeeInText('Hint stages per solve: n/a (no solves)');
    $sent->assertSeeInText('None filed.');
});

test('the digest fails loudly when no owner address is configured', function (): void {
    config()->set('analytics.owner_digest_email', '');

    $this->artisan('analytics:digest')->assertFailed();

    Mail::assertNothingSent();
});

test('the digest is scheduled weekly and the purge daily', function (): void {
    $commands = collect(Schedule::events())->map(fn ($event): string => (string) $event->command);

    expect($commands->filter(fn (string $cmd): bool => str_contains($cmd, 'analytics:digest')))->toHaveCount(1)
        ->and($commands->filter(fn (string $cmd): bool => str_contains($cmd, 'analytics:purge')))->toHaveCount(1);
});
