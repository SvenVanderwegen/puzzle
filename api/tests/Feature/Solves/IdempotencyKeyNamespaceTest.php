<?php

declare(strict_types=1);

use App\Domain\Ratings\RatingService;
use App\Domain\Solves\SolveSubmissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spectator\Spectator;

// Verifier finding (lead follow-up): failedDailyKey derives from PUBLIC
// inputs, so a player who pre-claims that (user_id, client_solve_id) via
// POST /solves could void their own failed-daily penalty, and a later POST
// could replay the hidden anchor row. Two independent fences:
//   1. the endpoint accepts ONLY UUID version 7 client keys (game-core emits
//      v7); the anchors live in a reserved v8 namespace;
//   2. replayForUser never surfaces failed-daily bookkeeping rows.

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    $this->travelTo('2026-07-10 12:00:00 UTC');
});

test('the solve endpoint accepts only UUIDv7 idempotency keys', function (): void {
    $user = actingAsUser();
    $daily = seedDaily('2026-07-10');
    $this->postJson('/api/v1/daily/2026-07-10/start')->assertStatus(204);
    $this->travel(2)->minutes();

    $rejected = [
        'a v4 key' => (string) Str::uuid(),
        'the reserved v8 anchor namespace' => RatingService::failedDailyKey($user->id, '2026-07-10'),
        'an old-style raw-hash key (any version nibble)' => '01234567-89ab-cdef-0123-456789abcdef',
    ];

    foreach ($rejected as $label => $key) {
        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/solves', solvePayload($daily->puzzle_id))
            ->assertStatus(422)
            ->assertValidResponse(422)
            ->assertJsonPath('error.code', 'validation_failed', "expected {$label} to be rejected");
    }

    $this->assertDatabaseCount('solves', 0);

    // A v7 key sails through.
    $this->withHeader('Idempotency-Key', (string) Str::uuid7())
        ->postJson('/api/v1/solves', solvePayload($daily->puzzle_id))
        ->assertStatus(201)
        ->assertValidResponse(201)
        ->assertJsonPath('valid', true);
});

test('failedDailyKey emits the reserved v8 namespace, deterministically', function (): void {
    $key = RatingService::failedDailyKey('01ARZ3NDEKTSV4RRFFQ69G5FAV', '2026-07-10');

    expect($key)->toBe(RatingService::failedDailyKey('01ARZ3NDEKTSV4RRFFQ69G5FAV', '2026-07-10'))
        ->and(Str::isUuid($key, 8))->toBeTrue()
        ->and(Str::isUuid($key, 7))->toBeFalse();
});

test('a failed-daily anchor cannot pre-empt or leak through the pre-rollover claim', function (): void {
    $this->travelTo('2026-07-10 09:00:00 UTC');
    $user = actingAsUser();
    $daily = seedDaily('2026-07-10');
    $this->postJson('/api/v1/daily/2026-07-10/start')->assertStatus(204);

    // The attack from the verifier report: claim the derived key BEFORE
    // rollover so the dedupe check would match a player row. The v7-only
    // fence stops it at validation; no solve row is created.
    $derived = RatingService::failedDailyKey($user->id, '2026-07-10');

    $this->withHeader('Idempotency-Key', $derived)
        ->postJson('/api/v1/solves', solvePayload($daily->puzzle_id))
        ->assertStatus(422);

    $this->assertDatabaseCount('solves', 0);

    // Rollover still books the penalty.
    $this->travelTo('2026-07-11 00:05:00 UTC');
    $this->artisan('streaks:rollover')->assertExitCode(0);

    expect(DB::table('rating_events')->count())->toBe(1)
        ->and(DB::table('solves')->where('reject_reason', RatingService::FAILED_DAILY_REASON)->count())->toBe(1);
});

test('replayForUser never surfaces a failed-daily bookkeeping row (second fence)', function (): void {
    $this->travelTo('2026-07-10 09:00:00 UTC');
    $user = actingAsUser();
    $daily = seedDaily('2026-07-10');
    $this->postJson('/api/v1/daily/2026-07-10/start')->assertStatus(204);

    $this->travelTo('2026-07-11 00:05:00 UTC');
    $this->artisan('streaks:rollover')->assertExitCode(0);

    $anchorKey = RatingService::failedDailyKey($user->id, '2026-07-10');
    expect(DB::table('solves')->where('client_solve_id', $anchorKey)->count())->toBe(1);

    // Even if the v7 fence were bypassed, the idempotent-replay lookup must
    // not match the anchor row.
    $method = new ReflectionMethod(SolveSubmissionService::class, 'replayForUser');
    $replay = $method->invoke(app(SolveSubmissionService::class), $user->id, $anchorKey);

    expect($replay)->toBeNull();

    // And a real invalid submission still replays (only the failed-daily
    // marker is excluded, not invalid solves in general).
    $realKey = (string) Str::uuid7();
    DB::table('solves')->insert([
        'user_id' => $user->id,
        'puzzle_id' => $daily->puzzle_id,
        'mode' => 'daily',
        'client_solve_id' => $realKey,
        'shaded_bits' => '100000010',
        'client_ms' => 60000,
        'received_at' => now('UTC'),
        'valid' => false,
        'reject_reason' => 'clue_time_mismatch',
        'suspect' => false,
        'imported' => false,
        'hints_s1' => 0,
        'hints_s2' => 0,
        'hints_s3' => 0,
        'undo_count' => 0,
        'response_snapshot' => json_encode(['solve_id' => '77', 'valid' => false, 'reason' => 'clue_time_mismatch', 'suspect' => false]),
    ]);

    $replayed = $method->invoke(app(SolveSubmissionService::class), $user->id, $realKey);

    expect($replayed)->toBeArray()
        ->and($replayed['status'] ?? null)->toBe(200);
});
