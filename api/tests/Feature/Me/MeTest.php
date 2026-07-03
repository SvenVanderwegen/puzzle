<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

test('GET /me without a session gets the 401 envelope', function (): void {
    $this->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated')
        ->assertValidResponse(401);
});

test('GET /me returns the profile with default streak and rating summaries', function (): void {
    $user = User::factory()->create(['email' => 'crew@example.test']);

    $response = $this->actingAs($user)->getJson('/api/v1/me');

    $response->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJson([
            'id' => $user->id,
            'email' => 'crew@example.test',
            'timezone' => 'UTC',
            'plan' => 'free',
            'pro_until' => null,
            'streak_alert_opt_in' => false,
            'streak' => [
                'current' => 0,
                'best' => 0,
                'last_daily_date' => null,
                'freeze_available' => true,
                'safe_until' => null,
            ],
            'rating' => [
                'rating' => 1500,
                'rd' => 350,
                'volatility' => 0.06,
                'games' => 0,
                'calibrating' => true,
            ],
        ]);
});

test('GET /me reflects stored streak and rating rows', function (): void {
    $user = User::factory()->create();

    // Raw inserts: only the Ratings domain may use the rating models (arch rule).
    DB::table('ratings')->insert([
        'user_id' => $user->id,
        'rating' => 1622.5,
        'rd' => 80.0,
        'volatility' => 0.05,
        'games' => 12,
    ]);
    DB::table('streaks')->insert([
        'user_id' => $user->id,
        'current_len' => 4,
        'best_len' => 9,
        'last_daily_date' => '2026-07-02',
    ]);

    $this->actingAs($user)->getJson('/api/v1/me')
        ->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonPath('rating.rating', 1622.5)
        ->assertJsonPath('rating.games', 12)
        ->assertJsonPath('rating.calibrating', false)
        ->assertJsonPath('streak.current', 4)
        ->assertJsonPath('streak.best', 9)
        ->assertJsonPath('streak.last_daily_date', '2026-07-02');
});

test('PATCH /me updates timezone and streak_alert_opt_in', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->patchJson('/api/v1/me', [
        'timezone' => 'Europe/Brussels',
        'streak_alert_opt_in' => true,
    ]);

    $response->assertStatus(200)
        ->assertValidResponse(200)
        ->assertJsonPath('timezone', 'Europe/Brussels')
        ->assertJsonPath('streak_alert_opt_in', true);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'timezone' => 'Europe/Brussels',
        'streak_alert_opt_in' => true,
    ]);
});

test('PATCH /me ignores fields outside the contract', function (): void {
    $user = User::factory()->create(['email' => 'crew@example.test']);

    $this->actingAs($user)->patchJson('/api/v1/me', [
        'email' => 'takeover@example.test',
        'plan' => 'pro',
        'timezone' => 'Asia/Tokyo',
    ])->assertStatus(200);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'email' => 'crew@example.test',
        'plan' => 'free',
        'timezone' => 'Asia/Tokyo',
    ]);
});

test('PATCH /me rejects an invalid timezone with the error envelope', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/v1/me', ['timezone' => 'Middle/Nowhere'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

test('PATCH /me without a session gets 401', function (): void {
    $this->patchJson('/api/v1/me', ['timezone' => 'UTC'])
        ->assertStatus(401)
        ->assertValidResponse(401);
});
