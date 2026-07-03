<?php

declare(strict_types=1);

use App\Models\DailyPuzzle;
use Spectator\Spectator;

// Streak-side behavior of the flag (covered days, no freeze consumed, no
// failed-daily events, risk-mail suppression) is asserted in
// tests/Feature/Streaks/StreakTest.php and Email/StreakRiskAlertTest.php;
// these tests cover the operator command that sets it (RUNBOOK §7).

beforeEach(function (): void {
    $this->travelTo('2026-07-10 12:00:00 UTC');
});

test('ops:daily-amnesty sets the flag and clients see it', function (): void {
    Spectator::using('openapi.yaml');
    seedDaily('2026-07-10');

    $this->artisan('ops:daily-amnesty', ['date' => '2026-07-10'])
        ->expectsOutputToContain('amnesty set for 2026-07-10')
        ->assertExitCode(0);

    expect(DailyPuzzle::query()->findOrFail('2026-07-10')->amnesty)->toBeTrue();

    // The in-app signal (RUNBOOK §7 step 4): GET /daily/{date} reports it.
    $this->getJson('/api/v1/daily/2026-07-10')
        ->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonPath('amnesty', true);
});

test('--revoke clears the flag', function (): void {
    seedDaily('2026-07-10', ['amnesty' => true]);

    $this->artisan('ops:daily-amnesty', ['date' => '2026-07-10', '--revoke' => true])
        ->expectsOutputToContain('amnesty revoked for 2026-07-10')
        ->assertExitCode(0);

    expect(DailyPuzzle::query()->findOrFail('2026-07-10')->amnesty)->toBeFalse();
});

test('an unpublished date is refused', function (): void {
    $this->artisan('ops:daily-amnesty', ['date' => '2026-07-10'])
        ->expectsOutputToContain('no published daily for 2026-07-10')
        ->assertExitCode(1);
});

test('a malformed date is refused', function (): void {
    $this->artisan('ops:daily-amnesty', ['date' => '2026-02-30'])->assertExitCode(2);
    $this->artisan('ops:daily-amnesty', ['date' => 'tomorrow'])->assertExitCode(2);
});
