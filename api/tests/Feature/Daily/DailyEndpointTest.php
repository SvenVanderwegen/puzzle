<?php

declare(strict_types=1);

use Database\Factories\PuzzleFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spectator\Spectator;

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    $this->travelTo('2026-07-10 12:00:00 UTC');
});

test('GET /daily/{date} is public and returns metadata with embedded stats', function (): void {
    $daily = seedDaily('2026-07-10');

    $this->getJson('/api/v1/daily/2026-07-10')
        ->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJson([
            'date' => '2026-07-10',
            'incident_number' => $daily->incident_number,
            'puzzle_id' => $daily->puzzle_id,
            'grade_tier' => 'lookout',
            'content_url' => "https://content.burnfront.com/puzzles/{$daily->puzzle_id}.json",
            'amnesty' => false,
            'stats' => ['solved_count' => 0, 'p50_ms' => null],
        ])
        ->assertJsonMissingPath('puzzle');
});

test('GET /daily/{date} embeds the board only under the origin-fallback flag', function (): void {
    $daily = seedDaily('2026-07-10');

    config()->set('burnfront.content.origin_fallback', true);

    // Spectator is switched off here: its schema engine cannot compile the
    // contract's prefixItems (Position) subtree, which only ships when the
    // fallback board is embedded. Structural assertions cover the shape; the
    // Board schema itself is enforced by Board::fromArray in the solve path.
    Spectator::reset();
    $this->getJson('/api/v1/daily/2026-07-10')
        ->assertStatus(200)
        ->assertExactJsonStructure([
            'date', 'incident_number', 'puzzle_id', 'grade_tier', 'content_url', 'amnesty',
            'stats' => ['solved_count', 'p50_ms'],
            'puzzle' => ['rows', 'cols', 'spark', 'breaks', 'clues' => ['*' => ['r', 'c', 'm']]],
        ])
        ->assertJsonPath('puzzle.rows', 3)
        ->assertJsonPath('puzzle.spark', [2, 0])
        ->assertJsonPath('puzzle.breaks', 2);
});

test('GET /daily/{date} surfaces the amnesty flag', function (): void {
    seedDaily('2026-07-10', ['amnesty' => true]);

    $this->getJson('/api/v1/daily/2026-07-10')
        ->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonPath('amnesty', true);
});

test('GET /daily/{date} 404s for unpublished, future and impossible dates', function (): void {
    seedDaily('2026-07-11'); // Published for tomorrow, but tomorrow is not live yet.

    $this->getJson('/api/v1/daily/2026-07-09')->assertStatus(404);
    $this->getJson('/api/v1/daily/2026-07-11')->assertStatus(404);
    $this->getJson('/api/v1/daily/2026-02-31')->assertStatus(404);
});

test('POST /daily/{date}/start requires a session', function (): void {
    seedDaily('2026-07-10');

    $this->postJson('/api/v1/daily/2026-07-10/start')
        ->assertStatus(401)
        ->assertValidResponse(401);
});

test('POST /daily/{date}/start stamps the fetch anchor once, first stamp wins', function (): void {
    $daily = seedDaily('2026-07-10');
    $user = actingAsUser();

    $this->postJson('/api/v1/daily/2026-07-10/start')->assertStatus(204);

    $first = DB::table('puzzle_fetches')
        ->where('user_id', $user->id)
        ->where('puzzle_id', $daily->puzzle_id)
        ->value('fetched_at');

    expect($first)->not->toBeNull();

    $this->travel(90)->seconds();

    $this->postJson('/api/v1/daily/2026-07-10/start')->assertStatus(204);

    $second = DB::table('puzzle_fetches')
        ->where('user_id', $user->id)
        ->where('puzzle_id', $daily->puzzle_id)
        ->value('fetched_at');

    expect($second)->toEqual($first);

    // The start is counted once in the aggregates.
    $this->assertDatabaseHas('daily_stats', ['date' => '2026-07-10', 'started_count' => 1]);
});

test('POST /daily/{date}/start 404s without a published daily', function (): void {
    actingAsUser();

    seedDaily('2026-07-12'); // Future — still 404.

    $this->postJson('/api/v1/daily/2026-07-09/start')->assertStatus(404);
    $this->postJson('/api/v1/daily/2026-07-12/start')->assertStatus(404);
});

test('GET /daily/{date} reflects aggregates after a contained incident', function (): void {
    $daily = seedDaily('2026-07-10');
    actingAsUser();

    $this->postJson('/api/v1/daily/2026-07-10/start')->assertStatus(204);

    $this->travel(2)->minutes(); // Open a plausible fetch->submit window.

    $this->withHeader('Idempotency-Key', (string) Str::uuid7())
        ->postJson('/api/v1/solves', solvePayload($daily->puzzle_id))
        ->assertStatus(201);

    $this->getJson('/api/v1/daily/2026-07-10')
        ->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonPath('stats.solved_count', 1)
        ->assertJsonPath('stats.p50_ms', fn (mixed $p50): bool => is_int($p50) && $p50 > 0);
});

test('the daily fixture board matches the vector shading', function (): void {
    // Guard: the factory constants stay glued to burn-0001 of the vector file.
    expect(hash('sha256', PuzzleFactory::VALID_SHADING))
        ->toBe(hash('sha256', '000010010'));
});
