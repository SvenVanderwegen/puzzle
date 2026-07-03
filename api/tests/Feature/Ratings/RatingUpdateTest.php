<?php

declare(strict_types=1);

use App\Domain\Ratings\BoardPriors;
use App\Domain\Ratings\Events\FailedDailyRecorded;
use App\Domain\Ratings\Events\RatableSolveRecorded;
use App\Domain\Ratings\Glicko2;
use App\Domain\Ratings\Glicko2State;
use App\Domain\Ratings\Listeners\ApplyFailedDaily;
use App\Domain\Ratings\Listeners\ApplyRatableSolve;
use App\Domain\Ratings\RatingService;
use App\Models\DailyPuzzle;
use App\Models\Puzzle;
use App\Models\Solve;
use App\Models\User;
use Database\Factories\PuzzleFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spectator\Spectator;

// The queue runs sync in tests (phpunit.xml), so the queued listener has
// finished by the time a request returns. DB values live in float32 columns:
// asserting the RATING.md fixtures through the full pipeline uses a 5e-4
// tolerance (float32 quantization); the exact 4-decimal reproduction is
// pinned by tests/Unit/Glicko2FixturesTest.php.

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    $this->travelTo('2026-07-10 12:00:00 UTC');
});

/**
 * A published daily whose board seeds the F1/F5 fixture prior:
 * base(crew) 1300 + 4 x 27 = 1408.
 */
function fixtureDaily(string $date = '2026-07-10'): DailyPuzzle
{
    $puzzle = Puzzle::factory()->create(['grade_tier' => 'crew', 'grade_score' => 27]);

    return DailyPuzzle::factory()->create(['date' => $date, 'puzzle_id' => $puzzle->id]);
}

function submitRated(string $puzzleId, array $overrides = []): string
{
    test()->postJson('/api/v1/daily/2026-07-10/start')->assertStatus(204);
    test()->travel(2)->minutes();

    $solveId = test()->withHeader('Idempotency-Key', (string) Str::uuid7())
        ->postJson('/api/v1/solves', solvePayload($puzzleId, $overrides))
        ->assertStatus(201)
        ->json('solve_id');

    assert(is_string($solveId));

    return $solveId;
}

test('a clean daily solve applies F1 to the user and F5 to the board, with a full audit row', function (): void {
    $user = actingAsUser();
    $daily = fixtureDaily();

    $solveId = submitRated($daily->puzzle_id);

    $rating = DB::table('ratings')->where('user_id', $user->id)->sole();
    expect((float) $rating->rating)->toEqualWithDelta(1637.6094, 5e-4)
        ->and((float) $rating->rd)->toEqualWithDelta(269.4299, 5e-4)
        ->and((float) $rating->volatility)->toEqualWithDelta(0.059999, 5e-6)
        ->and((int) $rating->games)->toBe(1);

    // Board side: same game, s_board = 0, weight 1.0, seeded at 1408/200.
    $board = DB::table('board_ratings')->where('puzzle_id', $daily->puzzle_id)->sole();
    expect((float) $board->rating)->toEqualWithDelta(1352.3300, 5e-4)
        ->and((float) $board->rd)->toEqualWithDelta(187.2294, 5e-4)
        ->and((float) $board->volatility)->toEqualWithDelta(0.060000, 5e-6)
        ->and((int) $board->attempts)->toBe(1);

    $event = DB::table('rating_events')->sole();
    expect((int) $event->solve_id)->toBe((int) $solveId)
        ->and($event->user_id)->toBe($user->id)
        ->and($event->puzzle_id)->toBe($daily->puzzle_id)
        ->and((float) $event->score)->toBe(1.0)
        ->and((float) $event->weight)->toBe(1.0)
        ->and((float) $event->user_before)->toBe(1500.0)
        ->and((float) $event->user_after)->toEqualWithDelta(1637.6094, 5e-4)
        ->and((float) $event->user_rd_before)->toBe(350.0)
        ->and((float) $event->user_rd_after)->toEqualWithDelta(269.4299, 5e-4)
        ->and((float) $event->board_before)->toBe(1408.0)
        ->and((float) $event->board_after)->toEqualWithDelta(1352.3300, 5e-4);
});

test('a hinted daily solve (1xs1 + 2xs2) scores 0.55 and applies F2', function (): void {
    $user = actingAsUser();
    $daily = fixtureDaily();

    submitRated($daily->puzzle_id, ['hints' => ['s1' => 1, 's2' => 2, 's3' => 0]]);

    $rating = DB::table('ratings')->where('user_id', $user->id)->sole();
    expect((float) $rating->rating)->toEqualWithDelta(1478.8473, 5e-4)
        ->and((float) $rating->rd)->toEqualWithDelta(269.4299, 5e-4);

    $event = DB::table('rating_events')->sole();
    expect((float) $event->score)->toEqualWithDelta(0.55, 1e-6);
});

test('an endless solve rates at half weight against a parameter-priced ephemeral board', function (): void {
    Spectator::reset(); // prefixItems limitation — see SubmitSolveTest.
    $user = actingAsUser();

    $this->withHeader('Idempotency-Key', (string) Str::uuid7())
        ->postJson('/api/v1/solves', [
            'mode' => 'endless',
            'endless_spec' => PuzzleFactory::BOARD,
            'shaded' => PuzzleFactory::VALID_SHADING,
            'client_ms' => 45000,
            'started_at' => now('UTC')->subMinute()->toJSON(),
            'hints' => ['s1' => 0, 's2' => 0, 's3' => 0],
            'undo_count' => 0,
            'deduction_steps' => 15,
        ])
        ->assertStatus(201)
        ->assertJsonPath('rating_pending', true);

    // Sparse puzzles table -> fallback bounds: 15 lands in crew [10, 22],
    // prior = 1300 + 4 x 15 = 1360. The expected user state comes from the
    // fixture-pinned engine at w = 0.5.
    $engine = new Glicko2;
    $expected = $engine->update(
        new Glicko2State(1500.0, 350.0, 0.06),
        [['rating' => 1360.0, 'rd' => 200.0, 'score' => 1.0]],
        0.5,
    );

    $rating = DB::table('ratings')->where('user_id', $user->id)->sole();
    expect((float) $rating->rating)->toEqualWithDelta($expected->rating, 5e-4)
        ->and((float) $rating->rd)->toEqualWithDelta($expected->rd, 5e-4)
        ->and((int) $rating->games)->toBe(1);

    // The endless board is ephemeral: audit row only, no board_ratings row.
    expect(DB::table('board_ratings')->count())->toBe(0);

    $event = DB::table('rating_events')->sole();
    expect($event->puzzle_id)->toBeNull()
        ->and((float) $event->weight)->toBe(0.5)
        ->and((float) $event->board_before)->toBe(1360.0);
});

test('duplicate deliveries of the same solve settle exactly once (at-least-once seam)', function (): void {
    $user = actingAsUser();
    $daily = fixtureDaily();

    $solveId = submitRated($daily->puzzle_id);

    // The rollout contract is at-least-once: replay the event twice more.
    RatableSolveRecorded::dispatch((int) $solveId);
    RatableSolveRecorded::dispatch((int) $solveId);

    expect(DB::table('rating_events')->count())->toBe(1);

    $rating = DB::table('ratings')->where('user_id', $user->id)->sole();
    expect((int) $rating->games)->toBe(1)
        ->and((float) $rating->rating)->toEqualWithDelta(1637.6094, 5e-4);
});

test('invalid, suspect, imported and stage-3 solves never touch ratings', function (): void {
    $service = app(RatingService::class);
    $user = User::factory()->create();

    $variants = [
        ['valid' => false, 'reject_reason' => 'wrong_break_count'],
        ['suspect' => true],
        ['imported' => true],
        ['hints_s3' => 1],
    ];

    foreach ($variants as $overrides) {
        $solve = Solve::factory()->create([
            'user_id' => $user->id,
            'puzzle_id' => Puzzle::factory()->create()->id,
            'mode' => 'daily',
            'endless_spec' => null,
            ...$overrides,
        ]);

        $service->applyRatableSolve($solve->id);
    }

    // A vanished solve id is a clean no-op too.
    $service->applyRatableSolve(999999);

    expect(DB::table('ratings')->count())->toBe(0)
        ->and(DB::table('board_ratings')->count())->toBe(0)
        ->and(DB::table('rating_events')->count())->toBe(0);
});

test('board ratings are seeded from the tier prior and their RD never drops below 50', function (): void {
    // Seeding: a lookout board with the factory grade (4) seeds 1000 + 16.
    $user = actingAsUser();
    $daily = seedDaily('2026-07-10');

    submitRated($daily->puzzle_id);

    $event = DB::table('rating_events')->sole();
    expect((float) $event->board_before)->toBe(1016.0);

    // RD floor: an over-settled board (here forced below the cap) is pulled
    // back up to exactly 50 by the next update.
    DB::table('board_ratings')->where('puzzle_id', $daily->puzzle_id)->update(['rd' => 30.0]);

    $second = User::factory()->create();
    $this->actingAs($second);
    $this->postJson('/api/v1/daily/2026-07-10/start')->assertStatus(204);
    $this->travel(2)->minutes();
    $this->withHeader('Idempotency-Key', (string) Str::uuid7())
        ->postJson('/api/v1/solves', solvePayload($daily->puzzle_id))
        ->assertStatus(201);

    $board = DB::table('board_ratings')->where('puzzle_id', $daily->puzzle_id)->sole();
    expect((float) $board->rd)->toBe(50.0)
        ->and((int) $board->attempts)->toBe(2);
});

test('the endless prior derives from observed tier distributions once the corpus is dense', function (): void {
    $priors = app(BoardPriors::class);

    // Sparse table: documented fallback bounds.
    expect($priors->priorForEndless(1))->toBe(1012.0)   // lookout, clamped up to 3
        ->and($priors->priorForEndless(5))->toBe(1020.0)   // lookout, in range
        ->and($priors->priorForEndless(15))->toBe(1360.0)  // crew, in range
        ->and($priors->priorForEndless(100))->toBe(1710.0); // hotshot, clamped down to 40

    // Dense corpus: bounds come from the puzzles table at runtime.
    foreach ([2, 3, 3, 4, 4, 5, 5, 6, 6, 6] as $score) {
        Puzzle::factory()->create(['grade_tier' => 'lookout', 'grade_score' => $score]);
    }

    foreach ([8, 9, 10, 11, 12, 13, 14, 15, 15, 16] as $score) {
        Puzzle::factory()->create(['grade_tier' => 'crew', 'grade_score' => $score]);
    }

    // 7 exceeds the observed lookout max (6): it prices as crew, clamped up
    // to the observed crew min (8) -> 1300 + 32.
    expect($priors->priorForEndless(7))->toBe(1332.0)
        // 4 sits inside observed lookout [2, 6] -> 1000 + 16.
        ->and($priors->priorForEndless(4))->toBe(1016.0)
        // hotshot stays sparse -> fallback [22, 40].
        ->and($priors->priorForEndless(30))->toBe(1670.0);
});

test('rating listeners are queued and wired to the WS-07 seam', function (): void {
    expect(is_subclass_of(ApplyRatableSolve::class, ShouldQueue::class))->toBeTrue()
        ->and(is_subclass_of(ApplyFailedDaily::class, ShouldQueue::class))->toBeTrue()
        ->and(app('events')->hasListeners(RatableSolveRecorded::class))->toBeTrue()
        ->and(app('events')->hasListeners(FailedDailyRecorded::class))->toBeTrue();
});
