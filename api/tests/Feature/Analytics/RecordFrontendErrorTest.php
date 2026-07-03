<?php

declare(strict_types=1);

use App\Models\FrontendError;
use Spectator\Spectator;

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    $this->travelTo('2026-07-10 12:00:00 UTC');
});

test('a beacon is filed with a bodiless 202', function (): void {
    $response = $this->postJson('/api/v1/errors', [
        'message' => 'TypeError: t is undefined',
        'stack' => "TypeError: t is undefined\n    at hydrate (hero.js:12:3)",
        'route' => '/daily/2026-07-10',
    ]);

    $response->assertStatus(202)->assertValidRequest()->assertValidResponse(202);
    expect($response->getContent())->toBe('');

    $row = FrontendError::query()->sole();
    expect($row->message)->toBe('TypeError: t is undefined')
        ->and($row->stack)->toContain('hero.js:12:3')
        ->and($row->route)->toBe('/daily/2026-07-10');
});

test('stack and route are optional', function (): void {
    $this->postJson('/api/v1/errors', ['message' => 'boom'])->assertStatus(202);

    $row = FrontendError::query()->sole();
    expect($row->stack)->toBeNull()->and($row->route)->toBeNull();
});

test('email addresses and bearer tokens are scrubbed before storage', function (): void {
    $this->postJson('/api/v1/errors', [
        'message' => 'Failed to fetch profile for crew.chief+test@example.test',
        'stack' => "Error: 401\n    Authorization: Bearer abc.DEF-123_456~xyz\n    at request (app.js:1:1)",
        'route' => '/auth/consume?token=deadbeefcafe1234&next=/hub',
    ]);

    $row = FrontendError::query()->sole();
    expect($row->message)->toBe('Failed to fetch profile for [email]')
        ->and($row->message)->not->toContain('@')
        ->and($row->stack)->toContain('Bearer [token]')
        ->and($row->stack)->not->toContain('abc.DEF-123_456~xyz')
        ->and($row->route)->toBe('/auth/consume?token=[token]&next=/hub');
});

test('oversized fields are truncated to the contract caps, never rejected', function (): void {
    $this->postJson('/api/v1/errors', [
        'message' => str_repeat('m', 5000),
        'stack' => str_repeat('s', 20000),
        'route' => '/'.str_repeat('r', 500),
    ])->assertStatus(202)->assertValidResponse(202);

    $row = FrontendError::query()->sole();
    expect(mb_strlen($row->message))->toBe(2000)
        ->and(mb_strlen((string) $row->stack))->toBe(8000)
        ->and(mb_strlen((string) $row->route))->toBe(200);
});

test('a payload without a usable message is dropped without a row (fire-and-forget)', function (): void {
    // The contract declares only 202/429 for this operation, so malformed
    // beacons are answered 202 and simply not stored.
    $this->postJson('/api/v1/errors', [])->assertStatus(202)->assertValidResponse(202);
    $this->postJson('/api/v1/errors', ['message' => 42])->assertStatus(202);
    $this->postJson('/api/v1/errors', ['message' => '   '])->assertStatus(202);

    expect(FrontendError::query()->count())->toBe(0);
});

test('the 11th beacon inside a minute is throttled', function (): void {
    // Stateless client (no SPA session cookie): the limiter falls back to the
    // IP key. Drop the simulated same-origin header so no session starts.
    $headers = ['Origin' => ''];

    for ($i = 1; $i <= 10; $i++) {
        $this->withHeaders($headers)
            ->postJson('/api/v1/errors', ['message' => "beacon {$i}"])
            ->assertStatus(202);
    }

    $this->withHeaders($headers)
        ->postJson('/api/v1/errors', ['message' => 'beacon 11'])
        ->assertStatus(429)
        ->assertValidResponse(429)
        ->assertJsonPath('error.code', 'rate_limited');

    expect(FrontendError::query()->count())->toBe(10);

    // Another client (different IP) is not throttled.
    $this->withHeaders($headers)
        ->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
        ->postJson('/api/v1/errors', ['message' => 'other client'])
        ->assertStatus(202);
});
