<?php

declare(strict_types=1);

use App\Models\Solve;
use Illuminate\Support\Str;
use Spectator\Spectator;

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    $this->travelTo('2026-07-10 12:00:00 UTC');
});

test('GET /me/solves requires a session', function (): void {
    $this->getJson('/api/v1/me/solves')
        ->assertStatus(401)
        ->assertValidResponse(401);
});

test('GET /me/solves lists newest first with daily context', function (): void {
    $user = actingAsUser();
    $daily = seedDaily('2026-07-10');

    Solve::factory()->create(['user_id' => $user->id] + ['received_at' => now()->subMinutes(30)]);
    Solve::factory()->create(['user_id' => $user->id] + [
        'mode' => 'daily',
        'puzzle_id' => $daily->puzzle_id,
        'official_ms' => 61000,
        'hints_s1' => 1,
        'received_at' => now()->subMinutes(5),
    ]);

    // Another crew's solves never leak in.
    Solve::factory()->create(['user_id' => null]);

    $response = $this->getJson('/api/v1/me/solves')
        ->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonCount(2, 'items')
        ->assertJsonPath('next_cursor', null);

    $items = $response->json('items');

    expect($items[0]['mode'])->toBe('daily')
        ->and($items[0]['date'])->toBe('2026-07-10')
        ->and($items[0]['incident_number'])->toBe($daily->incident_number)
        ->and($items[0]['official_ms'])->toBe(61000)
        ->and($items[0]['clean'])->toBeFalse()
        ->and($items[1]['mode'])->toBe('endless')
        ->and($items[1]['date'])->toBeNull()
        ->and($items[1]['clean'])->toBeTrue();
});

test('GET /me/solves paginates with an opaque cursor', function (): void {
    $user = actingAsUser();

    Solve::factory()->count(5)->create(['user_id' => $user->id]);

    $first = $this->getJson('/api/v1/me/solves?limit=2')
        ->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonCount(2, 'items');

    $cursor = $first->json('next_cursor');
    expect($cursor)->not->toBeNull();

    $second = $this->getJson('/api/v1/me/solves?limit=2&cursor='.$cursor)
        ->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonCount(2, 'items');

    $third = $this->getJson('/api/v1/me/solves?limit=2&cursor='.$second->json('next_cursor'))
        ->assertStatus(200)
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('next_cursor', null);

    $ids = array_map(intval(...), [
        ...array_column($first->json('items'), 'solve_id'),
        ...array_column($second->json('items'), 'solve_id'),
        ...array_column($third->json('items'), 'solve_id'),
    ]);

    $sorted = $ids;
    rsort($sorted);

    // No duplicates, no gaps, strictly newest-first across pages.
    expect(array_unique($ids))->toHaveCount(5)
        ->and($ids)->toBe($sorted);
});

test('GET /me/solves rejects a malformed cursor', function (): void {
    actingAsUser();

    // The contract does not declare a 422 for this path, so no Spectator
    // assertion here — just the error envelope.
    $this->getJson('/api/v1/me/solves?cursor='.urlencode('DROP TABLE'))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

test('GET /me/streak and /me/rating return contract defaults for a fresh account', function (): void {
    actingAsUser();

    $this->getJson('/api/v1/me/streak')
        ->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJson([
            'current' => 0,
            'best' => 0,
            'last_daily_date' => null,
            'freeze_available' => true,
            'safe_until' => null,
        ]);

    $this->getJson('/api/v1/me/rating')
        ->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJson([
            'rating' => 1500,
            'rd' => 350,
            'volatility' => 0.06,
            'games' => 0,
            'calibrating' => true,
        ]);
});

test('GET /me/streak and /me/rating require a session', function (): void {
    $this->getJson('/api/v1/me/streak')->assertStatus(401)->assertValidResponse(401);
    $this->getJson('/api/v1/me/rating')->assertStatus(401)->assertValidResponse(401);
});

test('a solve appears in the history right after submission', function (): void {
    actingAsUser();
    $daily = seedDaily('2026-07-10');

    $this->postJson('/api/v1/daily/2026-07-10/start')->assertStatus(204);
    $this->travel(2)->minutes();

    $solveId = $this->withHeader('Idempotency-Key', (string) Str::uuid7())
        ->postJson('/api/v1/solves', solvePayload($daily->puzzle_id))
        ->assertStatus(201)
        ->json('solve_id');

    $this->getJson('/api/v1/me/solves')
        ->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonPath('items.0.solve_id', $solveId)
        ->assertJsonPath('items.0.valid', true)
        ->assertJsonPath('items.0.clean', true)
        ->assertJsonPath('items.0.date', '2026-07-10');
});
