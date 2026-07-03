<?php

declare(strict_types=1);

use App\Models\User;
use Spectator\Spectator;

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

test('logout ends the session', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(204)
        ->assertValidResponse(204);

    $this->assertGuest('web');
});

test('logout without a session gets the 401 envelope', function (): void {
    $this->postJson('/api/v1/auth/logout')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated')
        ->assertValidResponse(401);
});
