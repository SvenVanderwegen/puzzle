<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Spectator\Spectator;

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    $this->travelTo('2026-07-10 12:00:00 UTC');
});

test('GET /health reports liveness and missing tomorrow content', function (): void {
    $this->getJson('/api/v1/health')
        ->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJson(['ok' => true, 'tomorrow_published' => false]);
});

test('GET /health flips tomorrow_published once the calendar covers tomorrow', function (): void {
    seedDaily('2026-07-11');

    $this->getJson('/api/v1/health')
        ->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJson(['ok' => true, 'tomorrow_published' => true]);
});

test('GET /health degrades to the contract 503 when the database is unreachable', function (): void {
    Exceptions::fake();

    // Empty the search path for THIS transaction only (RefreshDatabase wraps
    // each test): every relation lookup now fails exactly as it would with
    // the database down, and the rollback in teardown restores the world.
    DB::statement("SELECT pg_catalog.set_config('search_path', '', true)");

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertValidResponse(503)
        ->assertJson(['error' => ['code' => 'degraded']]);

    // The failure is report()ed — visible to Nightwatch, not swallowed.
    Exceptions::assertReported(QueryException::class);
});
