<?php

declare(strict_types=1);

use App\Domain\Ratings\Events\FailedDailyRecorded;
use App\Models\DailyPuzzle;
use App\Models\Streak;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Spectator\Spectator;

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

function containDaily(DailyPuzzle $daily): void
{
    test()->postJson("/api/v1/daily/{$daily->date}/start")->assertStatus(204);
    test()->travel(2)->minutes();
    test()->withHeader('Idempotency-Key', (string) Str::uuid7())
        ->postJson('/api/v1/solves', solvePayload($daily->puzzle_id))
        ->assertStatus(201)
        ->assertJsonPath('valid', true);
}

test('the streak survives the UTC midnight edge (23:59 -> 00:01)', function (): void {
    actingAsUser();

    $day1 = seedDaily('2026-07-10');
    $day2 = seedDaily('2026-07-11');

    // containDaily travels two minutes between start and submit, so the
    // submission lands at 23:59 — still day one.
    $this->travelTo('2026-07-10 23:57:00 UTC');
    containDaily($day1);

    $this->getJson('/api/v1/me/streak')
        ->assertValidResponse(200)
        ->assertJsonPath('current', 1)
        ->assertJsonPath('last_daily_date', '2026-07-10');

    $this->travelTo('2026-07-11 00:01:00 UTC');
    containDaily($day2);

    $this->getJson('/api/v1/me/streak')
        ->assertValidResponse(200)
        ->assertJsonPath('current', 2)
        ->assertJsonPath('best', 2)
        ->assertJsonPath('last_daily_date', '2026-07-11');
});

test('safe_until announces the exact UTC instant the streak dies', function (): void {
    actingAsUser();

    $daily = seedDaily('2026-07-10');
    $this->travelTo('2026-07-10 12:00:00 UTC');
    containDaily($daily);

    // Freeze available: July 11 would be auto-frozen, July 12 kills it at the
    // July 13 rollover... unless something covers it first.
    $this->getJson('/api/v1/me/streak')
        ->assertValidResponse(200)
        ->assertJsonPath('freeze_available', true)
        ->assertJsonPath('safe_until', '2026-07-13T00:00:00.000000Z');

    // Without a freeze, missing July 11 kills the streak at July 12 rollover.
    $user = User::query()->sole();
    Streak::query()->whereKey($user->id)->update(['freeze_available_at' => '2026-08-01']);

    $this->getJson('/api/v1/me/streak')
        ->assertValidResponse(200)
        ->assertJsonPath('freeze_available', false)
        ->assertJsonPath('safe_until', '2026-07-12T00:00:00.000000Z');
});

test('streaks:rollover consumes the monthly freeze for a missed day, idempotently', function (): void {
    $user = User::factory()->create();

    seedDaily('2026-07-11'); // Published and missed.

    Streak::query()->create([
        'user_id' => $user->id,
        'current_len' => 5,
        'best_len' => 5,
        'last_daily_date' => '2026-07-10',
        'frozen_dates' => [],
    ]);

    $this->travelTo('2026-07-12 00:05:00 UTC');
    $this->artisan('streaks:rollover')->assertExitCode(0);

    /** @var Streak $row */
    $row = Streak::query()->findOrFail($user->id);
    expect($row->current_len)->toBe(5)
        ->and($row->frozen_dates)->toBe(['2026-07-11'])
        ->and($row->freeze_available_at?->format('Y-m-d'))->toBe('2026-08-01');

    // Idempotent: a re-run changes nothing.
    $this->artisan('streaks:rollover')->assertExitCode(0);

    /** @var Streak $again */
    $again = Streak::query()->findOrFail($user->id);
    expect($again->current_len)->toBe(5)
        ->and($again->frozen_dates)->toBe(['2026-07-11'])
        ->and($again->freeze_available_at?->format('Y-m-d'))->toBe('2026-08-01');
});

test('streaks:rollover resets the streak when no freeze is available', function (): void {
    $user = User::factory()->create();

    seedDaily('2026-07-11');

    Streak::query()->create([
        'user_id' => $user->id,
        'current_len' => 9,
        'best_len' => 12,
        'last_daily_date' => '2026-07-10',
        'freeze_available_at' => '2026-08-01', // This month's freeze is spent.
        'frozen_dates' => ['2026-07-02'],
    ]);

    $this->travelTo('2026-07-12 00:05:00 UTC');
    $this->artisan('streaks:rollover')->assertExitCode(0);

    /** @var Streak $row */
    $row = Streak::query()->findOrFail($user->id);
    expect($row->current_len)->toBe(0)
        ->and($row->best_len)->toBe(12)
        ->and($row->frozen_dates)->toBe(['2026-07-02']);
});

test('amnesty and unpublished days never break streaks and consume no freeze', function (): void {
    $amnestied = User::factory()->create();
    $unpublished = User::factory()->create();

    seedDaily('2026-07-11', ['amnesty' => true]); // Pulled board.
    // 2026-07-12 has no daily at all (content outage).
    seedDaily('2026-07-13');

    foreach ([$amnestied, $unpublished] as $user) {
        Streak::query()->create([
            'user_id' => $user->id,
            'current_len' => 3,
            'best_len' => 3,
            'last_daily_date' => '2026-07-10',
            'frozen_dates' => [],
        ]);
    }

    $this->travelTo('2026-07-13 00:05:00 UTC');
    $this->artisan('streaks:rollover')->assertExitCode(0);

    foreach ([$amnestied, $unpublished] as $user) {
        /** @var Streak $row */
        $row = Streak::query()->findOrFail($user->id);
        expect($row->current_len)->toBe(3)
            ->and($row->frozen_dates)->toBe([])
            ->and($row->freeze_available_at)->toBeNull();
    }
});

test('the solve path replays the rollover walk when the scheduler missed it', function (): void {
    actingAsUser();

    $day10 = seedDaily('2026-07-10');
    seedDaily('2026-07-11'); // Missed; freeze should cover it at credit time.
    $day12 = seedDaily('2026-07-12');

    $this->travelTo('2026-07-10 12:00:00 UTC');
    containDaily($day10);

    // No rollover ran. Two days later the player solves again.
    $this->travelTo('2026-07-12 09:00:00 UTC');
    containDaily($day12);

    // A frozen day preserves the chain but adds no length: 10th + 12th = 2.
    $this->getJson('/api/v1/me/streak')
        ->assertValidResponse(200)
        ->assertJsonPath('current', 2)
        ->assertJsonPath('freeze_available', false)
        ->assertJsonPath('last_daily_date', '2026-07-12');

    /** @var Streak $row */
    $row = Streak::query()->sole();
    expect($row->frozen_dates)->toBe(['2026-07-11']);
});

test('a two-day uncovered gap resets to a fresh streak of one at credit time', function (): void {
    actingAsUser();

    $day10 = seedDaily('2026-07-10');
    seedDaily('2026-07-11');
    seedDaily('2026-07-12');
    $day13 = seedDaily('2026-07-13');

    $this->travelTo('2026-07-10 12:00:00 UTC');
    containDaily($day10);

    $this->travelTo('2026-07-13 09:00:00 UTC');
    containDaily($day13);

    // July 11 was frozen (the one monthly freeze), July 12 broke the chain.
    $this->getJson('/api/v1/me/streak')
        ->assertValidResponse(200)
        ->assertJsonPath('current', 1)
        ->assertJsonPath('best', 1);

    /** @var Streak $row */
    $row = Streak::query()->sole();
    expect($row->frozen_dates)->toBe(['2026-07-11']);
});

test('solving an archive daily does not move the streak', function (): void {
    actingAsUser();

    $old = seedDaily('2026-07-08');
    seedDaily('2026-07-10');

    $this->travelTo('2026-07-10 12:00:00 UTC');

    $this->postJson('/api/v1/daily/2026-07-08/start')->assertStatus(204);
    $this->travel(2)->minutes();
    $this->withHeader('Idempotency-Key', (string) Str::uuid7())
        ->postJson('/api/v1/solves', solvePayload($old->puzzle_id))
        ->assertStatus(201)
        ->assertJsonPath('valid', true)
        ->assertJsonPath('streak.current', 0);
});

test('streaks:rollover emits the failed-daily rating hook per RATING.md', function (): void {
    Event::fake([FailedDailyRecorded::class]);

    $daily = seedDaily('2026-07-11');

    $fetchedNoSolve = User::factory()->create();
    $fetchedAndSolved = User::factory()->create();
    $anonymized = User::factory()->anonymized()->create();

    $this->travelTo('2026-07-11 12:00:00 UTC');

    foreach ([$fetchedNoSolve, $fetchedAndSolved, $anonymized] as $user) {
        DB::table('puzzle_fetches')->insert([
            'user_id' => $user->id,
            'puzzle_id' => $daily->puzzle_id,
            'fetched_at' => now(),
        ]);
    }

    $this->actingAs($fetchedAndSolved);
    $this->travel(2)->minutes();
    $this->withHeader('Idempotency-Key', (string) Str::uuid7())
        ->postJson('/api/v1/solves', solvePayload($daily->puzzle_id))
        ->assertStatus(201)
        ->assertJsonPath('valid', true);

    $this->travelTo('2026-07-12 00:05:00 UTC');
    $this->artisan('streaks:rollover')->assertExitCode(0);

    Event::assertDispatchedTimes(FailedDailyRecorded::class, 1);
    Event::assertDispatched(
        FailedDailyRecorded::class,
        fn (FailedDailyRecorded $event): bool => $event->userId === $fetchedNoSolve->id
            && $event->date === '2026-07-11'
            && $event->puzzleId === $daily->puzzle_id,
    );
});

test('streaks:rollover is scheduled right after UTC midnight', function (): void {
    $events = collect(Schedule::events());

    $rollover = $events->filter(
        fn ($event): bool => str_contains((string) $event->command, 'streaks:rollover'),
    );

    expect($rollover)->toHaveCount(1)
        ->and($rollover->first()?->expression)->toBe('5 0 * * *');
});

test('an amnestied daily emits no failed-daily hook', function (): void {
    Event::fake([FailedDailyRecorded::class]);

    $daily = seedDaily('2026-07-11', ['amnesty' => true]);
    $user = User::factory()->create();

    $this->travelTo('2026-07-11 12:00:00 UTC');
    DB::table('puzzle_fetches')->insert([
        'user_id' => $user->id,
        'puzzle_id' => $daily->puzzle_id,
        'fetched_at' => now(),
    ]);

    $this->travelTo('2026-07-12 00:05:00 UTC');
    $this->artisan('streaks:rollover')->assertExitCode(0);

    Event::assertNotDispatched(FailedDailyRecorded::class);
});
