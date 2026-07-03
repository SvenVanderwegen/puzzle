<?php

declare(strict_types=1);

use App\Models\DailyPuzzle;
use App\Models\Puzzle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spectator\Spectator;

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    $this->travelTo('2026-07-10 12:00:00 UTC');
});

test('GET /me/rating serves the live Glicko-2 state with a sparkline, oldest first', function (): void {
    actingAsUser();

    // Two rated dailies: today's and yesterday's archive board.
    foreach (['2026-07-09', '2026-07-10'] as $date) {
        $puzzle = Puzzle::factory()->create(['grade_tier' => 'crew', 'grade_score' => 27]);
        DailyPuzzle::factory()->create(['date' => $date, 'puzzle_id' => $puzzle->id]);

        $this->postJson("/api/v1/daily/{$date}/start")->assertStatus(204);
        $this->travel(2)->minutes();
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/solves', solvePayload($puzzle->id))
            ->assertStatus(201);
    }

    $response = $this->getJson('/api/v1/me/rating')
        ->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonPath('games', 2)
        ->assertJsonPath('calibrating', true)
        ->assertJsonCount(2, 'sparkline');

    $body = $response->json();
    assert(is_array($body));

    // Post-solve ratings, oldest first; the head of the chain is F1.
    expect($body['sparkline'][0])->toEqualWithDelta(1637.6094, 5e-4)
        ->and($body['sparkline'][1])->toBeGreaterThan((float) $body['sparkline'][0])
        ->and($body['rating'])->toEqualWithDelta((float) $body['sparkline'][1], 1e-9)
        ->and($body['rd'])->toBeLessThan(269.44);
});

test('calibrating clears at ten rated games and the sparkline window is 30', function (): void {
    $user = actingAsUser();

    DB::table('ratings')->insert([
        'user_id' => $user->id,
        'rating' => 1580.5,
        'rd' => 120.0,
        'volatility' => 0.059,
        'games' => 10,
    ]);

    $this->getJson('/api/v1/me/rating')
        ->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonPath('games', 10)
        ->assertJsonPath('calibrating', false)
        ->assertJsonPath('sparkline', []);

    // 35 audit rows -> only the most recent 30, oldest first.
    $solveIds = [];

    for ($i = 0; $i < 35; $i++) {
        $solveIds[] = DB::table('solves')->insertGetId([
            'user_id' => $user->id,
            'puzzle_id' => null,
            'mode' => 'endless',
            'client_solve_id' => (string) Str::uuid(),
            'shaded_bits' => '',
            'client_ms' => 1000,
            'received_at' => now('UTC'),
            'valid' => true,
            'suspect' => false,
            'imported' => false,
            'hints_s1' => 0,
            'hints_s2' => 0,
            'hints_s3' => 0,
            'undo_count' => 0,
        ]);
    }

    foreach ($solveIds as $i => $solveId) {
        DB::table('rating_events')->insert([
            'solve_id' => $solveId,
            'user_id' => $user->id,
            'puzzle_id' => null,
            'score' => 1.0,
            'weight' => 0.5,
            'user_before' => 1500.0 + $i,
            'user_after' => 1501.0 + $i,
            'user_rd_before' => 350.0,
            'user_rd_after' => 340.0,
            'board_before' => 1360.0,
            'board_after' => 1350.0,
        ]);
    }

    $body = $this->getJson('/api/v1/me/rating')
        ->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonCount(30, 'sparkline')
        ->json();

    assert(is_array($body));

    expect($body['sparkline'][0])->toEqualWithDelta(1506.0, 1e-6)   // event #5 (0-based) of 35
        ->and($body['sparkline'][29])->toEqualWithDelta(1535.0, 1e-6); // the newest
});
