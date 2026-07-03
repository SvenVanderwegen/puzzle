<?php

declare(strict_types=1);

use App\Domain\Ratings\RatingRecompute;
use App\Domain\Ratings\RatingService;
use App\Models\DailyPuzzle;
use App\Models\Puzzle;
use App\Models\Solve;
use Carbon\CarbonImmutable;
use Database\Factories\PuzzleFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;

// POST /me/import (WS-20): the anonymous→account merge. The local record is
// a claim — these tests pin the anti-fabrication rules: BurnValidator
// re-checks every daily, streak credit caps at 7 days and only for boards
// published before the claimed solve time, imported rows are percentile-
// ineligible, and the rating is seeded at half weight (RATING.md §5).

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    $this->travelTo('2026-07-10 12:00:00 UTC');
});

/**
 * A published daily whose published_at sits at its own UTC midnight, so
 * same-day solve claims are eligible.
 */
function seedPublishedDaily(string $date): DailyPuzzle
{
    return seedDaily($date, ['published_at' => $date.' 00:00:00+00']);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function importItem(array $overrides = []): array
{
    return [
        'client_solve_id' => (string) Str::uuid7(),
        'mode' => 'daily',
        'date' => '2026-07-10',
        'shaded' => PuzzleFactory::VALID_SHADING,
        'client_ms' => 61000,
        'hints' => ['s1' => 0, 's2' => 0, 's3' => 0],
        'solved_at' => '2026-07-10T10:00:00Z',
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $items
 */
function postImport(array $items): TestResponse
{
    return test()->postJson('/api/v1/me/import', ['items' => $items]);
}

/**
 * Same-day solved items for consecutive dates ending at $endDate.
 *
 * @return list<array<string, mixed>>
 */
function sameDayItems(string $endDate, int $days): array
{
    $items = [];

    for ($i = $days - 1; $i >= 0; $i--) {
        $date = CarbonImmutable::parse($endDate, 'UTC')->subDays($i)->format('Y-m-d');
        $items[] = importItem(['date' => $date, 'solved_at' => $date.'T10:00:00Z']);
    }

    return $items;
}

/**
 * @return list<string>
 */
function statusesOf(TestResponse $response): array
{
    $results = $response->json('results');
    assert(is_array($results));

    return array_map(fn (array $row): string => $row['status'], $results);
}

test('POST /me/import requires a session', function (): void {
    postImport([importItem()])
        ->assertStatus(401)
        ->assertValidResponse(401);
});

test('a guest history of three same-day dailies merges with full streak credit', function (): void {
    $user = actingAsUser();

    foreach (['2026-07-08', '2026-07-09', '2026-07-10'] as $date) {
        seedPublishedDaily($date);
    }

    $response = postImport(sameDayItems('2026-07-10', 3));

    $response->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonPath('credited_days', 3)
        ->assertJsonPath('streak.current', 3)
        ->assertJsonPath('streak.best', 3)
        ->assertJsonPath('streak.last_daily_date', '2026-07-10');

    expect(statusesOf($response))->toBe(['credited', 'credited', 'credited']);

    expect(Solve::query()->where('user_id', $user->id)->where('imported', true)->where('valid', true)->count())->toBe(3)
        ->and(Solve::query()->whereNotNull('official_ms')->count())->toBe(0)
        ->and((int) DB::table('ratings')->where('user_id', $user->id)->value('games'))->toBe(3)
        ->and(DB::table('rating_events')->where('weight', '<>', 0.5)->count())->toBe(0);

    // Percentile-ineligible: imported solves never touch the aggregates.
    $this->assertDatabaseCount('daily_stats', 0);
});

test('re-importing the same log is idempotent per item', function (): void {
    $user = actingAsUser();

    foreach (['2026-07-08', '2026-07-09', '2026-07-10'] as $date) {
        seedPublishedDaily($date);
    }

    $items = sameDayItems('2026-07-10', 3);

    postImport($items)->assertStatus(200);

    $again = postImport($items);

    $again->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonPath('credited_days', 0)
        ->assertJsonPath('streak.current', 3);

    expect(statusesOf($again))->toBe(['duplicate', 'duplicate', 'duplicate']);

    // Zero duplicates stored, zero double-credit anywhere.
    expect(Solve::query()->where('user_id', $user->id)->count())->toBe(3)
        ->and(DB::table('rating_events')->count())->toBe(3)
        ->and((int) DB::table('ratings')->where('user_id', $user->id)->value('games'))->toBe(3)
        ->and((int) DB::table('streaks')->where('user_id', $user->id)->value('best_len'))->toBe(3);
});

test('a fabricated 100-day streak earns at most 7 days, no percentile rows, and only half-weight high-RD rating events', function (): void {
    $user = actingAsUser();

    // Only the last 12 incidents actually exist; the other 88 claimed days
    // never had a published board.
    for ($i = 0; $i < 12; $i++) {
        seedPublishedDaily(CarbonImmutable::parse('2026-07-10', 'UTC')->subDays($i)->format('Y-m-d'));
    }

    $response = postImport(sameDayItems('2026-07-10', 100));

    $statuses = array_count_values(statusesOf($response));

    $response->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonPath('credited_days', 7)
        ->assertJsonPath('streak.current', 7)
        ->assertJsonPath('streak.best', 7)
        ->assertJsonPath('streak.last_daily_date', '2026-07-10');

    expect($statuses)->toBe(['board_unknown' => 88, 'credited' => 12]);

    // No percentile entries, ever.
    $this->assertDatabaseCount('daily_stats', 0);

    // Rating events exist only as half-weight imported seeds on a fresh
    // (high-RD, 350) rating; the live pipeline never ran.
    $events = DB::table('rating_events')->orderBy('id')->get();
    expect($events)->toHaveCount(12)
        ->and($events->pluck('weight')->unique()->all())->toBe([0.5])
        ->and((float) $events->first()->user_rd_before)->toBe(350.0)
        ->and((int) DB::table('ratings')->where('user_id', $user->id)->value('games'))->toBe(12);

    expect(Solve::query()->where('user_id', $user->id)->where('imported', false)->count())->toBe(0)
        ->and(Solve::query()->where('suspect', true)->count())->toBe(0);
});

test('a split-batch attack cannot stack streak credit past the 7-day window', function (): void {
    $user = actingAsUser();

    for ($i = 0; $i < 20; $i++) {
        seedPublishedDaily(CarbonImmutable::parse('2026-07-10', 'UTC')->subDays($i)->format('Y-m-d'));
    }

    // Batch 1: the newest 7 days — the legitimate maximum.
    postImport(sameDayItems('2026-07-10', 7))
        ->assertStatus(200)
        ->assertJsonPath('credited_days', 7)
        ->assertJsonPath('streak.current', 7);

    // Batch 2: the 7 days before that, fresh ids. Real boards, valid
    // shadings, same-day claims — but outside the trailing 7-day window:
    // stats + rating only, never a backward streak union.
    $older = [];
    for ($i = 7; $i < 14; $i++) {
        $date = CarbonImmutable::parse('2026-07-10', 'UTC')->subDays($i)->format('Y-m-d');
        $older[] = importItem(['date' => $date, 'solved_at' => $date.'T10:00:00Z']);
    }

    $second = postImport($older);

    $second->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonPath('credited_days', 0)
        ->assertJsonPath('streak.current', 7)
        ->assertJsonPath('streak.best', 7);

    expect(statusesOf($second))->toBe(array_fill(0, 7, 'credited'))
        ->and((int) DB::table('ratings')->where('user_id', $user->id)->value('games'))->toBe(14);
});

test('invalid local solves are silently dropped with per-item codes', function (): void {
    $user = actingAsUser();
    seedPublishedDaily('2026-07-10');

    $response = postImport([
        importItem(['shaded' => '100000010']), // burn-0002: clue_time_mismatch
        importItem(['shaded' => '0101']),      // wrong cell count
        importItem(['date' => null]),          // a daily claim without its date
        importItem(),                          // the one honest item
    ]);

    $response->assertStatus(200)->assertValidResponse(200);

    expect(statusesOf($response))->toBe(['invalid', 'invalid', 'invalid', 'credited'])
        ->and(Solve::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('claims that predate publication or come from the future are date_ineligible', function (): void {
    $user = actingAsUser();
    seedDaily('2026-07-10', ['published_at' => '2026-07-10 08:00:00+00']);

    $response = postImport([
        importItem(['solved_at' => '2026-07-10T07:00:00Z']), // before published_at
        importItem(['solved_at' => '2026-07-10T13:00:00Z']), // the future (now = 12:00)
    ]);

    $response->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonPath('credited_days', 0)
        ->assertJsonPath('streak.current', 0);

    expect(statusesOf($response))->toBe(['date_ineligible', 'date_ineligible'])
        ->and(Solve::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('unknown and future incident dates are board_unknown, without leaking tomorrow', function (): void {
    $user = actingAsUser();
    // Tomorrow's board is already staged — its existence must not leak.
    seedPublishedDaily('2026-07-11');

    $response = postImport([
        importItem(['date' => '2026-07-01', 'solved_at' => '2026-07-01T10:00:00Z']),
        importItem(['date' => '2026-07-11', 'solved_at' => '2026-07-10T11:00:00Z']),
    ]);

    $response->assertStatus(200)->assertValidResponse(200);

    expect(statusesOf($response))->toBe(['board_unknown', 'board_unknown'])
        ->and(Solve::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('an incident already contained by the account is duplicate under a fresh id', function (): void {
    $user = actingAsUser();
    $daily = seedPublishedDaily('2026-07-10');

    // Contained live, before the import (e.g. solved signed-in on another
    // device) — a gathered solution re-imported under a fresh id buys nothing.
    Solve::query()->create([
        'user_id' => $user->id,
        'puzzle_id' => $daily->puzzle_id,
        'mode' => 'daily',
        'client_solve_id' => (string) Str::uuid7(),
        'shaded_bits' => PuzzleFactory::VALID_SHADING,
        'client_ms' => 60000,
        'official_ms' => 60000,
        'received_at' => now('UTC'),
        'valid' => true,
        'suspect' => false,
        'imported' => false,
        'hints_s1' => 0,
        'hints_s2' => 0,
        'hints_s3' => 0,
        'undo_count' => 0,
    ]);

    $response = postImport([importItem()]);

    $response->assertStatus(200)->assertValidResponse(200);

    expect(statusesOf($response))->toBe(['duplicate'])
        ->and(Solve::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(DB::table('rating_events')->count())->toBe(0);
});

test('endless items merge as stats only and never touch the rating', function (): void {
    $user = actingAsUser();

    $response = postImport([
        importItem([
            'mode' => 'endless',
            'date' => null,
            'shaded' => '011010',
            'solved_at' => '2026-07-09T20:00:00Z',
        ]),
    ]);

    $response->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonPath('credited_days', 0)
        ->assertJsonPath('streak.current', 0);

    expect(statusesOf($response))->toBe(['stats_only']);

    $solve = Solve::query()->where('user_id', $user->id)->sole();
    expect($solve->mode)->toBe('endless')
        ->and($solve->imported)->toBeTrue()
        ->and($solve->valid)->toBeTrue()
        ->and($solve->puzzle_id)->toBeNull();

    // No rating credit for imported endless — there is no board to re-validate.
    $this->assertDatabaseCount('rating_events', 0);
    $this->assertDatabaseCount('ratings', 0);
});

test('a stage-3-hinted daily is credited for the streak but never rated', function (): void {
    $user = actingAsUser();
    seedPublishedDaily('2026-07-10');

    $response = postImport([importItem(['hints' => ['s1' => 0, 's2' => 0, 's3' => 1]])]);

    $response->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonPath('credited_days', 1)
        ->assertJsonPath('streak.current', 1);

    expect(statusesOf($response))->toBe(['credited'])
        ->and(Solve::query()->where('user_id', $user->id)->count())->toBe(1);

    $this->assertDatabaseCount('rating_events', 0);
});

test('an archive solve credits stats and rating but never the streak', function (): void {
    $user = actingAsUser();
    seedPublishedDaily('2026-07-08');

    // Solved two days after its incident day: a real solve, not a streak day.
    $response = postImport([
        importItem(['date' => '2026-07-08', 'solved_at' => '2026-07-10T09:00:00Z']),
    ]);

    $response->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonPath('credited_days', 0)
        ->assertJsonPath('streak.current', 0);

    expect(statusesOf($response))->toBe(['credited'])
        ->and(Solve::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(DB::table('rating_events')->count())->toBe(1);
});

test('the reserved failed-daily namespace and non-v7 uuids drop per item, not per batch', function (): void {
    $user = actingAsUser();
    seedPublishedDaily('2026-07-10');

    $reserved = RatingService::failedDailyKey($user->id, '2026-07-10');

    $response = postImport([
        importItem(['client_solve_id' => $reserved]),
        importItem(['client_solve_id' => (string) Str::uuid()]), // v4
        importItem(),
    ]);

    $response->assertStatus(200)->assertValidResponse(200);

    expect(statusesOf($response))->toBe(['invalid', 'invalid', 'credited']);

    // The v8 anchor namespace stays unclaimed: the rollover's failed-daily
    // bookkeeping for another day still lands.
    expect(Solve::query()->where('client_solve_id', $reserved)->exists())->toBeFalse();
});

test('one incident cannot be credited twice inside one batch', function (): void {
    $user = actingAsUser();
    seedPublishedDaily('2026-07-10');

    $repeatedId = (string) Str::uuid7();

    $response = postImport([
        importItem(['client_solve_id' => $repeatedId]),
        importItem(['client_solve_id' => $repeatedId]), // same id resent
        importItem(),                                   // same incident, fresh id
    ]);

    $response->assertStatus(200)->assertValidResponse(200);

    expect(statusesOf($response))->toBe(['credited', 'duplicate', 'duplicate'])
        ->and(Solve::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(DB::table('rating_events')->count())->toBe(1);
});

test('a clean imported daily seeds the rating at exactly half the live delta (fixture F3, board F5)', function (): void {
    $user = actingAsUser();

    // The F1/F3/F5 fixture board: base(crew) 1300 + 4 x 27 = 1408, RD 200.
    $puzzle = Puzzle::factory()->create(['grade_tier' => 'crew', 'grade_score' => 27]);
    DailyPuzzle::factory()->create([
        'date' => '2026-07-10',
        'puzzle_id' => $puzzle->id,
        'published_at' => '2026-07-10 00:00:00+00',
    ]);

    postImport([importItem()])->assertStatus(200);

    // F3: (1500, 350, 0.06) vs (1408, 200), s = 1.0, w = 1.0 x 0.5 = 0.5.
    $rating = DB::table('ratings')->where('user_id', $user->id)->sole();
    expect((float) $rating->rating)->toEqualWithDelta(1568.8047, 5e-4)
        ->and((float) $rating->rd)->toEqualWithDelta(269.4299, 5e-4)
        ->and((int) $rating->games)->toBe(1);

    // Board side: an ordinary weight-1.0 opponent (F5), bounded to one
    // imported game per account by the one-valid-daily index.
    $board = DB::table('board_ratings')->where('puzzle_id', $puzzle->id)->sole();
    expect((float) $board->rating)->toEqualWithDelta(1352.3300, 5e-4)
        ->and((int) $board->attempts)->toBe(1);

    $event = DB::table('rating_events')->sole();
    expect((float) $event->weight)->toBe(0.5)
        ->and((float) $event->user_rd_before)->toBe(350.0)
        ->and((float) $event->score)->toBe(1.0);
});

test('ratings:recompute reproduces an imported chain bit-for-bit', function (): void {
    $user = actingAsUser();

    foreach (['2026-07-08', '2026-07-09', '2026-07-10'] as $date) {
        seedPublishedDaily($date);
    }

    // Mixed hint claims: clean, one s1, two s2 (different §3 outcomes).
    $items = sameDayItems('2026-07-10', 3);
    $items[1]['hints'] = ['s1' => 1, 's2' => 0, 's3' => 0];
    $items[2]['hints'] = ['s1' => 0, 's2' => 2, 's3' => 0];

    postImport($items)->assertStatus(200);

    $live = DB::table('ratings')->where('user_id', $user->id)->sole();

    app(RatingRecompute::class)->run();

    $replayed = DB::table('ratings')->where('user_id', $user->id)->sole();

    expect(abs((float) $replayed->rating - (float) $live->rating))->toBeLessThan(1e-6)
        ->and(abs((float) $replayed->rd - (float) $live->rd))->toBeLessThan(1e-6)
        ->and(abs((float) $replayed->volatility - (float) $live->volatility))->toBeLessThan(1e-6)
        ->and((int) $replayed->games)->toBe((int) $live->games);
});

test('the import endpoint is throttled per user', function (): void {
    actingAsUser();

    for ($i = 0; $i < 10; $i++) {
        postImport([importItem(['date' => '2026-07-01', 'solved_at' => '2026-07-01T10:00:00Z'])])
            ->assertStatus(200);
    }

    postImport([importItem(['date' => '2026-07-01', 'solved_at' => '2026-07-01T10:00:00Z'])])
        ->assertStatus(429)
        ->assertValidResponse(429);
});

test('batch shape violations reject the whole request', function (): void {
    actingAsUser();

    postImport([])->assertStatus(422);

    postImport(array_map(fn (): array => importItem(), range(1, 101)))->assertStatus(422);

    postImport([importItem(['client_solve_id' => 'not-a-uuid'])])->assertStatus(422);
});
