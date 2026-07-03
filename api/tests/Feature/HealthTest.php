<?php

declare(strict_types=1);

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
