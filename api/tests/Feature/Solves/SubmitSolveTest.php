<?php

declare(strict_types=1);

use App\Domain\Ratings\Events\RatableSolveRecorded;
use App\Models\DailyPuzzle;
use App\Models\Puzzle;
use App\Models\Solve;
use App\Models\User;
use Database\Factories\PuzzleFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    $this->travelTo('2026-07-10 12:00:00 UTC');
});

/**
 * @param  array<string, mixed>  $payload
 */
function postSolve(array $payload, ?string $key = null): TestResponse
{
    return test()->withHeader('Idempotency-Key', $key ?? (string) Str::uuid7())
        ->postJson('/api/v1/solves', $payload);
}

function startedDaily(string $date = '2026-07-10', int $travelSeconds = 120): DailyPuzzle
{
    $daily = seedDaily($date);
    test()->postJson("/api/v1/daily/{$date}/start")->assertStatus(204);
    test()->travel($travelSeconds)->seconds();

    return $daily;
}

test('POST /solves requires a session', function (): void {
    $this->postJson('/api/v1/solves', [])
        ->assertStatus(401)
        ->assertValidResponse(401);
});

test('a valid daily solve is accepted with rank, percentile and streak credit', function (): void {
    actingAsUser();
    $daily = startedDaily();

    $response = postSolve(solvePayload($daily->puzzle_id, ['client_ms' => 61000]));

    $response->assertStatus(201)
        ->assertValidResponse(201)
        ->assertJsonPath('valid', true)
        ->assertJsonPath('reason', 'ok')
        ->assertJsonPath('official_ms', 61000)
        ->assertJsonPath('suspect', false)
        ->assertJsonPath('rating_pending', true)
        ->assertJsonPath('streak.current', 1)
        ->assertJsonPath('streak.best', 1)
        ->assertJsonPath('streak.last_daily_date', '2026-07-10')
        ->assertJsonPath('daily.rank', 1)
        ->assertJsonPath('daily.solved_count', 1);

    $this->assertDatabaseHas('daily_stats', ['date' => '2026-07-10', 'solved_count' => 1, 'p50_ms' => 61000]);
    $this->assertDatabaseCount('solves', 1);
});

test('a duplicate Idempotency-Key replays the stored snapshot with 200 and keeps one row', function (): void {
    actingAsUser();
    $daily = startedDaily();

    $key = (string) Str::uuid7();
    $payload = solvePayload($daily->puzzle_id);

    $first = postSolve($payload, $key)->assertStatus(201);

    $second = postSolve($payload, $key);

    $second->assertStatus(200)->assertValidResponse(200);

    // jsonb does not preserve key order; equality is structural.
    expect($second->json())->toEqual($first->json());
    $this->assertDatabaseCount('solves', 1);
    $this->assertDatabaseHas('daily_stats', ['date' => '2026-07-10', 'solved_count' => 1]);
});

test('a second valid daily solve under a fresh key is rejected cleanly', function (): void {
    actingAsUser();
    $daily = startedDaily();

    postSolve(solvePayload($daily->puzzle_id))->assertStatus(201);

    postSolve(solvePayload($daily->puzzle_id))
        ->assertStatus(422)
        ->assertValidResponse(422)
        ->assertJsonPath('error.code', 'validation_failed');

    expect(Solve::query()->where('valid', true)->count())->toBe(1);
});

test('an incorrect shading is stored and returned as invalid, not rejected', function (): void {
    actingAsUser();
    $daily = startedDaily();

    // burn-0002: same board, off-by-one clue time -> clue_time_mismatch.
    $response = postSolve(solvePayload($daily->puzzle_id, ['shaded' => '100000010']));

    $response->assertStatus(201)
        ->assertValidResponse(201)
        ->assertJsonPath('valid', false)
        ->assertJsonPath('reason', 'clue_time_mismatch')
        ->assertJsonPath('official_ms', null)
        ->assertJsonPath('suspect', false)
        ->assertJsonPath('rating_pending', false)
        ->assertJsonPath('streak.current', 0);

    $this->assertDatabaseHas('solves', ['valid' => false, 'reject_reason' => 'clue_time_mismatch']);
    $this->assertDatabaseMissing('daily_stats', ['date' => '2026-07-10', 'solved_count' => 1]);

    // The player may retry with a fresh key and still get daily credit.
    postSolve(solvePayload($daily->puzzle_id))
        ->assertStatus(201)
        ->assertJsonPath('valid', true)
        ->assertJsonPath('streak.current', 1);
});

test('the Idempotency-Key header is required and must be a UUID', function (): void {
    actingAsUser();
    $daily = startedDaily();

    $this->postJson('/api/v1/solves', solvePayload($daily->puzzle_id))
        ->assertStatus(422)
        ->assertValidResponse(422);

    $this->withHeader('Idempotency-Key', 'not-a-uuid')
        ->postJson('/api/v1/solves', solvePayload($daily->puzzle_id))
        ->assertStatus(422)
        ->assertValidResponse(422);
});

test('an overstated clock is clamped to the server window and flagged suspect', function (): void {
    actingAsUser();
    $daily = startedDaily(travelSeconds: 60); // Window: 60s.

    $response = postSolve(solvePayload($daily->puzzle_id, ['client_ms' => 300000]));

    $response->assertStatus(201)
        ->assertValidResponse(201)
        ->assertJsonPath('valid', true)
        ->assertJsonPath('official_ms', 60000)
        ->assertJsonPath('suspect', true)
        ->assertJsonPath('rating_pending', false)
        ->assertJsonPath('daily.rank', null)
        ->assertJsonPath('daily.percentile', null)
        // Suspect solves still count for the streak, never for percentiles.
        ->assertJsonPath('streak.current', 1)
        ->assertJsonPath('daily.solved_count', 0);

    $this->assertDatabaseMissing('daily_stats', ['date' => '2026-07-10', 'solved_count' => 1]);
});

test('a client_ms below the replay duration is flagged suspect', function (): void {
    actingAsUser();
    $daily = startedDaily(travelSeconds: 600);

    $replay = replayFixture([[0, 4, 1], [95000, 7, 1]]);

    postSolve(solvePayload($daily->puzzle_id, ['client_ms' => 20000, ...$replay]))
        ->assertStatus(201)
        ->assertValidResponse(201)
        ->assertJsonPath('valid', true)
        ->assertJsonPath('official_ms', 20000)
        ->assertJsonPath('suspect', true);
});

test('a solve faster than the perceptual floor is flagged suspect', function (): void {
    actingAsUser();
    $daily = startedDaily();

    // Floor: 2 breaks x 250ms = 500ms.
    postSolve(solvePayload($daily->puzzle_id, ['client_ms' => 350]))
        ->assertStatus(201)
        ->assertValidResponse(201)
        ->assertJsonPath('valid', true)
        ->assertJsonPath('suspect', true);
});

test('a daily solve without a fetch anchor is flagged suspect', function (): void {
    actingAsUser();
    $daily = seedDaily('2026-07-10'); // No /start stamp.

    postSolve(solvePayload($daily->puzzle_id))
        ->assertStatus(201)
        ->assertValidResponse(201)
        ->assertJsonPath('valid', true)
        ->assertJsonPath('official_ms', 60000)
        ->assertJsonPath('suspect', true);
});

test('a consistent replay passes integrity and is stored', function (): void {
    actingAsUser();
    $daily = startedDaily();

    $replay = replayFixture([[0, 4, 1], [42000, 7, 1]]);

    postSolve(solvePayload($daily->puzzle_id, ['client_ms' => 61000, ...$replay]))
        ->assertStatus(201)
        ->assertValidResponse(201)
        ->assertJsonPath('valid', true)
        ->assertJsonPath('suspect', false);

    /** @var Solve $solve */
    $solve = Solve::query()->sole();
    expect($solve->replay)->not->toBeNull()
        ->and($solve->replay_sha256)->toBe($replay['replay_sha256']);
});

test('a replay digest mismatch is rejected with 422 (ADR-0012)', function (): void {
    actingAsUser();
    $daily = startedDaily();

    $replay = replayFixture([[0, 4, 1], [42000, 7, 1]]);
    $replay['replay_sha256'] = hash('sha256', 'something-else');

    postSolve(solvePayload($daily->puzzle_id, ['client_ms' => 61000, ...$replay]))
        ->assertStatus(422)
        ->assertValidResponse(422)
        ->assertJsonPath('error.code', 'validation_failed');

    $this->assertDatabaseCount('solves', 0);
});

test('a replay that does not gunzip is rejected with 422', function (): void {
    actingAsUser();
    $daily = startedDaily();

    postSolve(solvePayload($daily->puzzle_id, [
        'replay' => base64_encode('not gzip at all'),
        'replay_sha256' => hash('sha256', 'whatever'),
    ]))
        ->assertStatus(422)
        ->assertValidResponse(422);

    $this->assertDatabaseCount('solves', 0);
});

test('a corrupted solution hash trips the ops alert but stays invisible to the player', function (): void {
    Log::spy();

    actingAsUser();

    $puzzle = Puzzle::factory()->create(['solution_sha256' => hash('sha256', 'not-the-solution')]);
    DailyPuzzle::factory()->create(['date' => '2026-07-10', 'puzzle_id' => $puzzle->id]);
    $this->postJson('/api/v1/daily/2026-07-10/start')->assertStatus(204);
    $this->travel(2)->minutes();

    postSolve(solvePayload($puzzle->id))
        ->assertStatus(201)
        ->assertJsonPath('valid', true)
        ->assertJsonPath('reason', 'ok');

    Log::shouldHaveReceived('critical')
        ->withArgs(fn (string $message): bool => str_contains($message, 'solution_sha256'))
        ->once();
});

test('an unknown or future puzzle id is rejected with 422', function (): void {
    actingAsUser();

    postSolve(solvePayload('bf1-9x9-999999'))
        ->assertStatus(422)
        ->assertValidResponse(422);

    // A daily published for tomorrow must not leak early.
    $future = seedDaily('2026-07-11');

    postSolve(solvePayload($future->puzzle_id))
        ->assertStatus(422)
        ->assertValidResponse(422);
});

test('stage-3 hints keep the solve valid but unrated', function (): void {
    Event::fake([RatableSolveRecorded::class]);

    actingAsUser();
    $daily = startedDaily();

    postSolve(solvePayload($daily->puzzle_id, ['hints' => ['s1' => 0, 's2' => 0, 's3' => 1]]))
        ->assertStatus(201)
        ->assertValidResponse(201)
        ->assertJsonPath('valid', true)
        ->assertJsonPath('rating_pending', false)
        ->assertJsonPath('streak.current', 1);

    Event::assertNotDispatched(RatableSolveRecorded::class);
});

test('a ratable solve emits the WS-08 rating seam event exactly once', function (): void {
    Event::fake([RatableSolveRecorded::class]);

    actingAsUser();
    $daily = startedDaily();

    $solveId = postSolve(solvePayload($daily->puzzle_id))->assertStatus(201)->json('solve_id');

    Event::assertDispatchedTimes(RatableSolveRecorded::class, 1);
    Event::assertDispatched(
        RatableSolveRecorded::class,
        fn (RatableSolveRecorded $event): bool => (string) $event->solveId === $solveId,
    );
});

// The three endless tests below run without Spectator: its schema engine
// cannot compile the contract's prefixItems (Position) subtree, which the
// endless_spec request property references. The response shape is still
// asserted structurally.

test('an endless solve is validated against its submitted spec (ADR-0006)', function (): void {
    Spectator::reset();
    actingAsUser();

    $payload = [
        'mode' => 'endless',
        'endless_spec' => PuzzleFactory::BOARD,
        'shaded' => PuzzleFactory::VALID_SHADING,
        'client_ms' => 45000,
        'started_at' => now('UTC')->subMinute()->toJSON(),
        'hints' => ['s1' => 0, 's2' => 0, 's3' => 0],
        'undo_count' => 3,
        'deduction_steps' => 7,
    ];

    postSolve($payload)
        ->assertStatus(201)
        ->assertExactJsonStructure([
            'solve_id', 'valid', 'reason', 'official_ms', 'suspect', 'rating_pending',
            'streak' => ['current', 'best', 'last_daily_date', 'freeze_available', 'safe_until'],
        ])
        ->assertJsonPath('valid', true)
        ->assertJsonPath('official_ms', 45000)
        ->assertJsonPath('suspect', false)
        ->assertJsonPath('rating_pending', true)
        ->assertJsonMissingPath('daily');

    /** @var Solve $solve */
    $solve = Solve::query()->sole();
    expect($solve->puzzle_id)->toBeNull()
        ->and($solve->endless_spec)->not->toBeNull()
        ->and($solve->endless_spec['deduction_steps'] ?? null)->toBe(7)
        ->and($solve->endless_spec['breaks'] ?? null)->toBe(2);
});

test('an endless solve with a bad shading is invalid, not rejected', function (): void {
    Spectator::reset();
    actingAsUser();

    postSolve([
        'mode' => 'endless',
        'endless_spec' => PuzzleFactory::BOARD,
        'shaded' => '110000010', // Spark [2,0] sealed off: unreachable cells.
        'client_ms' => 45000,
        'started_at' => now('UTC')->subMinute()->toJSON(),
        'hints' => ['s1' => 0, 's2' => 0, 's3' => 0],
        'undo_count' => 0,
        'deduction_steps' => 4,
    ])
        ->assertStatus(201)
        ->assertJsonPath('valid', false)
        ->assertJsonPath('rating_pending', false);
});

test('a malformed endless_spec is rejected with 422', function (): void {
    Spectator::reset();
    actingAsUser();

    $base = [
        'mode' => 'endless',
        'shaded' => PuzzleFactory::VALID_SHADING,
        'client_ms' => 45000,
        'started_at' => now('UTC')->subMinute()->toJSON(),
        'hints' => ['s1' => 0, 's2' => 0, 's3' => 0],
        'undo_count' => 0,
        'deduction_steps' => 4,
    ];

    // Out-of-bounds spark.
    postSolve([...$base, 'endless_spec' => [...PuzzleFactory::BOARD, 'spark' => [5, 0]]])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    // Unknown key (additionalProperties: false).
    postSolve([...$base, 'endless_spec' => [...PuzzleFactory::BOARD, 'solution' => '000010010']])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    // Missing deduction_steps.
    postSolve([...$base, 'endless_spec' => PuzzleFactory::BOARD, 'deduction_steps' => null])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    // Board shape and shading length must agree.
    postSolve([...$base, 'endless_spec' => PuzzleFactory::BOARD, 'shaded' => '0000100100'])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    $this->assertDatabaseCount('solves', 0);
});

test('POST /solves rides the 30/min/user throttle', function (): void {
    $route = collect(Route::getRoutes())
        ->first(fn ($route): bool => $route->uri() === 'api/v1/solves');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:solves');

    $user = actingAsUser();

    $limit = RateLimiter::limiter('solves')(
        request()->setUserResolver(fn (): User => $user),
    );

    expect($limit->maxAttempts)->toBe(30)
        ->and($limit->decaySeconds)->toBe(60);
});

test('percentiles rank the field of eligible solves', function (): void {
    $daily = seedDaily('2026-07-10');

    // Three crews contain the incident at different speeds.
    foreach ([['a', 30000], ['b', 60000], ['c', 90000]] as [$label, $ms]) {
        actingAsUser();
        $this->postJson('/api/v1/daily/2026-07-10/start')->assertStatus(204);
        $this->travel(3)->minutes();

        $response = postSolve(solvePayload($daily->puzzle_id, ['client_ms' => $ms]));
        $response->assertStatus(201)->assertJsonPath('valid', true)->assertJsonPath('suspect', false);
        $this->travelBack();
        $this->travelTo('2026-07-10 12:00:00 UTC');
    }

    /** @var Solve $slowest */
    $slowest = Solve::query()->where('client_ms', 90000)->sole();
    $snapshot = $slowest->response_snapshot;

    expect($snapshot)->not->toBeNull();
    expect($snapshot['daily']['rank'])->toBe(3)
        ->and($snapshot['daily']['solved_count'])->toBe(3)
        ->and($snapshot['daily']['percentile'])->toBe(0);

    $this->assertDatabaseHas('daily_stats', ['date' => '2026-07-10', 'solved_count' => 3, 'p50_ms' => 60000]);
});
