<?php

declare(strict_types=1);

use App\Domain\Streaks\Mail\StreakRiskMail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;

beforeEach(function (): void {
    Mail::fake();
});

/**
 * An opted-in user with a 5-day streak, last credited 2026-07-02, whose next
 * freeze is out of reach — solving 2026-07-03 is the only way to survive the
 * 2026-07-04T00:00Z rollover.
 *
 * @param  array<string, mixed>  $userAttrs
 * @param  array<string, mixed>  $streakAttrs
 */
function atRiskUser(array $userAttrs = [], array $streakAttrs = []): User
{
    $user = User::factory()->create([
        'streak_alert_opt_in' => true,
        'timezone' => 'UTC',
        ...$userAttrs,
    ]);

    DB::table('streaks')->insert([
        'user_id' => $user->id,
        'current_len' => 5,
        'best_len' => 9,
        'last_daily_date' => '2026-07-02',
        'freeze_available_at' => '2026-08-01',
        ...$streakAttrs,
    ]);

    return $user;
}

test('an at-risk, opted-in user is alerted in their local 20:00 hour', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-03T20:30:00Z'));
    seedDaily('2026-07-03', ['incident_number' => 142]);
    $user = atRiskUser();

    $this->artisan('notifications:streak-risk')
        ->expectsOutputToContain('1 streak-risk alert(s) queued.')
        ->assertSuccessful();

    Mail::assertQueued(StreakRiskMail::class, function (StreakRiskMail $mail) use ($user): bool {
        // 3.5 hours to midnight UTC rounds UP — never "0 hours" and never
        // a promise of more time than there is at the next full hour.
        return $mail->hasTo((string) $user->email)
            && $mail->envelope()->subject === 'Your 5-day streak has 4 hours.'
            && $mail->incidentNumber === 142
            && $mail->deadline->toISOString() === '2026-07-04T00:00:00.000000Z';
    });
    Mail::assertQueuedCount(1);
});

test('the alert never fires for the ineligible', function (array $userAttrs, array $streakAttrs): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-03T20:30:00Z'));
    seedDaily('2026-07-03');
    atRiskUser($userAttrs, $streakAttrs);

    $this->artisan('notifications:streak-risk')->assertSuccessful();

    Mail::assertNothingQueued();
})->with([
    'preference off' => [['streak_alert_opt_in' => false], []],
    'already solved today' => [[], ['last_daily_date' => '2026-07-03']],
    'streak of 1 (below the >= 2 floor)' => [[], ['current_len' => 1]],
    'streak already dead' => [[], ['current_len' => 0, 'last_daily_date' => '2026-06-20']],
    'a freeze would cover today' => [[], ['freeze_available_at' => null]],
    'wrong local hour' => [['timezone' => 'Europe/Brussels'], []], // 22:30 local
]);

test('an anonymized account is never alerted, even with a stale opt-in flag', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-03T20:30:00Z'));
    seedDaily('2026-07-03');
    $user = atRiskUser();
    // Simulate drift an anonymizer bug could leave behind: flag on, email gone.
    $user->forceFill(['email' => null, 'anonymized_at' => now()])->save();

    $this->artisan('notifications:streak-risk')->assertSuccessful();

    Mail::assertNothingQueued();
});

test('no daily or an amnestied daily suppresses every alert', function (array $dailyOverrides, bool $seed): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-03T20:30:00Z'));

    if ($seed) {
        seedDaily('2026-07-03', $dailyOverrides);
    }

    atRiskUser();

    $this->artisan('notifications:streak-risk')->assertSuccessful();

    Mail::assertNothingQueued();
})->with([
    'no daily published' => [[], false],
    'amnestied daily' => [['amnesty' => true], true],
]);

test('a user without a streaks row is not a candidate', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-03T20:30:00Z'));
    seedDaily('2026-07-03');
    User::factory()->create(['streak_alert_opt_in' => true, 'timezone' => 'UTC']);

    $this->artisan('notifications:streak-risk')->assertSuccessful();

    Mail::assertNothingQueued();
});

test('timezone edges: UTC+14 is in its evening while UTC-11 and Europe are not', function (): void {
    // 06:30 UTC on the incident day: Kiritimati (UTC+14) reads 20:30 already;
    // Pago Pago (UTC-11) is still on the previous local evening (19:30 on
    // 2026-07-02); Brussels reads 08:30.
    $this->travelTo(CarbonImmutable::parse('2026-07-03T06:30:00Z'));
    seedDaily('2026-07-03', ['incident_number' => 142]);
    $kiritimati = atRiskUser(['timezone' => 'Pacific/Kiritimati']);
    atRiskUser(['timezone' => 'Pacific/Pago_Pago']);
    atRiskUser(['timezone' => 'Europe/Brussels']);

    $this->artisan('notifications:streak-risk')->assertSuccessful();

    // Only the UTC+14 user is alerted, with 17.5 hours to the UTC deadline.
    Mail::assertQueuedCount(1);
    Mail::assertQueued(StreakRiskMail::class, fn (StreakRiskMail $mail): bool => $mail->hasTo((string) $kiritimati->email)
        && $mail->envelope()->subject === 'Your 5-day streak has 18 hours.');
});

test('a UTC-11 user is alerted for the current UTC day during their local evening', function (): void {
    // 07:30 UTC on 2026-07-03 = 20:30 on 2026-07-02 in Pago Pago: the alert
    // still concerns UTC day 2026-07-03 — the day boundary never localizes.
    $this->travelTo(CarbonImmutable::parse('2026-07-03T07:30:00Z'));
    seedDaily('2026-07-03', ['incident_number' => 142]);
    $user = atRiskUser(['timezone' => 'Pacific/Pago_Pago']);

    $this->artisan('notifications:streak-risk')->assertSuccessful();

    Mail::assertQueued(StreakRiskMail::class, fn (StreakRiskMail $mail): bool => $mail->hasTo((string) $user->email)
        && $mail->incidentNumber === 142
        && $mail->date === '2026-07-03'
        && $mail->deadline->toISOString() === '2026-07-04T00:00:00.000000Z');
});

test('one alert per user per UTC day, across reruns inside the send hour', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-03T20:20:00Z'));
    seedDaily('2026-07-03');
    atRiskUser();

    $this->artisan('notifications:streak-risk')->assertSuccessful();
    $this->artisan('notifications:streak-risk')->assertSuccessful();

    $this->travelTo(CarbonImmutable::parse('2026-07-03T20:50:00Z'));
    $this->artisan('notifications:streak-risk')
        ->expectsOutputToContain('0 streak-risk alert(s) queued.')
        ->assertSuccessful();

    Mail::assertQueuedCount(1);
});

test('one broken candidate never blocks the rest of the sweep', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-03T20:30:00Z'));
    seedDaily('2026-07-03');
    atRiskUser(['timezone' => 'Not/AZone']); // unparseable stored zone
    $healthy = atRiskUser();

    $this->artisan('notifications:streak-risk')->assertSuccessful();

    Mail::assertQueuedCount(1);
    Mail::assertQueued(StreakRiskMail::class, fn (StreakRiskMail $mail): bool => $mail->hasTo((string) $healthy->email));
});

test('the sweep is scheduled hourly, exactly once', function (): void {
    $events = collect(Schedule::events())
        ->map(fn ($event): string => (string) $event->command)
        ->filter(fn (string $cmd): bool => str_contains($cmd, 'notifications:streak-risk'));

    expect($events)->toHaveCount(1);
});
