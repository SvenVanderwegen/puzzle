<?php

declare(strict_types=1);

use App\Domain\Ratings\RatingService;
use App\Models\DailyPuzzle;
use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spectator\Spectator;

// RATING.md §3: a daily with a puzzle_fetches start record left unsolved at
// UTC rollover scores s = 0.25 — applied by streaks:rollover via the queued
// FailedDailyRecorded seam, one per user per day max.

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * The F4 fixture board: base(hotshot) 1550 + 4 x 25 = 1650.
 */
function f4Daily(string $date): DailyPuzzle
{
    $puzzle = Puzzle::factory()->create(['grade_tier' => 'hotshot', 'grade_score' => 25]);

    return DailyPuzzle::factory()->create(['date' => $date, 'puzzle_id' => $puzzle->id]);
}

test('an unsolved started daily applies F4 at rollover, once, through a hidden audit anchor', function (): void {
    $this->travelTo('2026-07-10 09:00:00 UTC');
    $user = actingAsUser();
    $daily = f4Daily('2026-07-10');

    $this->postJson('/api/v1/daily/2026-07-10/start')->assertStatus(204);

    // UTC rollover: the day ends unsolved.
    $this->travelTo('2026-07-11 00:05:00 UTC');
    $this->artisan('streaks:rollover')->assertExitCode(0);

    // F4: (1500, 350, 0.06) vs (1650, 200), s = 0.25, w = 1.0.
    $rating = DB::table('ratings')->where('user_id', $user->id)->sole();
    expect((float) $rating->rating)->toEqualWithDelta(1472.5281, 5e-4)
        ->and((float) $rating->rd)->toEqualWithDelta(273.7811, 5e-4)
        ->and((float) $rating->volatility)->toEqualWithDelta(0.059999, 5e-6)
        ->and((int) $rating->games)->toBe(1);

    $event = DB::table('rating_events')->sole();
    expect((float) $event->score)->toBe(0.25)
        ->and((float) $event->weight)->toBe(1.0)
        ->and($event->puzzle_id)->toBe($daily->puzzle_id)
        ->and((float) $event->board_before)->toBe(1650.0);

    // The board side won its game (s_board = 0.75).
    $board = DB::table('board_ratings')->where('puzzle_id', $daily->puzzle_id)->sole();
    expect((float) $board->rating)->toBeGreaterThan(1650.0)
        ->and((int) $board->attempts)->toBe(1);

    // The audit anchor: a synthetic invalid solve with a deterministic key.
    $anchor = DB::table('solves')->sole();
    expect((bool) $anchor->valid)->toBeFalse()
        ->and($anchor->reject_reason)->toBe(RatingService::FAILED_DAILY_REASON)
        ->and($anchor->client_solve_id)->toBe(RatingService::failedDailyKey($user->id, '2026-07-10'));

    // A rollover re-run re-emits (at-least-once); the outcome must not double.
    $this->artisan('streaks:rollover', ['--date' => '2026-07-11'])->assertExitCode(0);

    expect(DB::table('rating_events')->count())->toBe(1)
        ->and(DB::table('solves')->count())->toBe(1)
        ->and((int) DB::table('ratings')->where('user_id', $user->id)->sole()->games)->toBe(1);

    // The bookkeeping row never surfaces as a player submission.
    $this->getJson('/api/v1/me/solves')
        ->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonCount(0, 'items');
});

test('users who never started, solved in time, or were anonymized book nothing', function (): void {
    $this->travelTo('2026-07-10 09:00:00 UTC');
    $daily = f4Daily('2026-07-10');

    // Solved in time.
    $solver = actingAsUser();
    $this->postJson('/api/v1/daily/2026-07-10/start')->assertStatus(204);
    $this->travel(2)->minutes();
    $this->withHeader('Idempotency-Key', (string) Str::uuid7())
        ->postJson('/api/v1/solves', solvePayload($daily->puzzle_id))
        ->assertStatus(201);

    // Never started.
    $bystander = User::factory()->create();

    $this->travelTo('2026-07-11 00:05:00 UTC');
    $this->artisan('streaks:rollover')->assertExitCode(0);

    // Exactly one rating event: the solver's own rated solve. No failed-daily
    // anchors exist for either account.
    expect(DB::table('rating_events')->count())->toBe(1)
        ->and(DB::table('solves')->where('reject_reason', RatingService::FAILED_DAILY_REASON)->count())->toBe(0)
        ->and(DB::table('ratings')->where('user_id', $bystander->id)->count())->toBe(0);

    // A late queued event for an anonymized account is a clean no-op.
    $ghost = User::factory()->create(['anonymized_at' => now()]);
    app(RatingService::class)->applyFailedDaily($ghost->id, '2026-07-10', $daily->puzzle_id);

    expect(DB::table('ratings')->where('user_id', $ghost->id)->count())->toBe(0);
});
