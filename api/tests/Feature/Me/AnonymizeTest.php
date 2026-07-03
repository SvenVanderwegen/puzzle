<?php

declare(strict_types=1);

use App\Domain\Auth\Jobs\AnonymizeUser;
use App\Models\Solve;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

test('DELETE /me queues the anonymize job and ends the session', function (): void {
    Bus::fake([AnonymizeUser::class]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->deleteJson('/api/v1/me');

    $response->assertStatus(202)->assertValidResponse(202);
    $this->assertGuest('web');

    Bus::assertDispatched(AnonymizeUser::class, fn (AnonymizeUser $job): bool => $job->userId === $user->id);
});

test('anonymization erases the profile, deletes ratings and streaks, keeps solves disowned', function (): void {
    $user = User::factory()->create([
        'email' => 'leaving@example.test',
        'handle' => 'reserved-handle',
        'timezone' => 'Europe/Brussels',
        'country' => 'BE',
        'streak_alert_opt_in' => true,
    ]);
    DB::table('auth_identities')->insert([
        'user_id' => $user->id,
        'provider' => 'email',
        'provider_uid' => 'leaving@example.test',
    ]);
    DB::table('magic_link_tokens')->insert([
        'email' => 'leaving@example.test',
        'token_hash' => hash('sha256', 'outstanding-token'),
        'expires_at' => now()->addMinutes(10),
    ]);
    DB::table('ratings')->insert(['user_id' => $user->id, 'games' => 5]);
    DB::table('streaks')->insert(['user_id' => $user->id, 'current_len' => 3, 'best_len' => 8]);
    $solves = Solve::factory()->count(2)->create(['user_id' => $user->id]);
    DB::table('rating_events')->insert([
        'solve_id' => $solves[0]->id,
        'user_id' => $user->id,
        'score' => 1.0,
        'weight' => 0.5,
        'user_before' => 1500.0,
        'user_after' => 1512.0,
        'user_rd_before' => 350.0,
        'user_rd_after' => 290.0,
        'board_before' => 1480.0,
        'board_after' => 1476.0,
    ]);

    // Sync queue: the job runs inside this request.
    $this->actingAs($user)->deleteJson('/api/v1/me')->assertStatus(202);

    // Profile erased: email/handle nulled, timezone reset to the non-identifying
    // default (column is NOT NULL), anonymized_at stamped.
    $fresh = $user->refresh();
    expect($fresh->email)->toBeNull()
        ->and($fresh->handle)->toBeNull()
        ->and($fresh->timezone)->toBe('UTC')
        ->and($fresh->country)->toBeNull()
        ->and($fresh->anonymized_at)->not->toBeNull();

    // Ratings + streaks rows deleted.
    $this->assertDatabaseMissing('ratings', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('streaks', ['user_id' => $user->id]);

    // Solves rows survive with user_id NULL.
    expect(Solve::query()->count())->toBe(2)
        ->and(Solve::query()->whereNull('user_id')->count())->toBe(2);
    $this->assertDatabaseMissing('solves', ['user_id' => $user->id]);

    // The rating audit trail survives, disowned.
    $this->assertDatabaseHas('rating_events', ['solve_id' => $solves[0]->id, 'user_id' => null]);
    $this->assertDatabaseMissing('rating_events', ['user_id' => $user->id]);

    // Identity rows and outstanding tokens are gone (they carry the email).
    $this->assertDatabaseMissing('auth_identities', ['user_id' => $user->id]);
    $this->assertDatabaseMissing('magic_link_tokens', ['email' => 'leaving@example.test']);
});

test('anonymization is idempotent', function (): void {
    $user = User::factory()->create();
    Solve::factory()->create(['user_id' => $user->id]);

    AnonymizeUser::dispatchSync($user->id);
    $stamp = $user->refresh()->anonymized_at;

    $this->travel(1)->hours();
    AnonymizeUser::dispatchSync($user->id);

    expect($user->refresh()->anonymized_at?->toISOString())->toBe($stamp?->toISOString())
        ->and(Solve::query()->count())->toBe(1);
});

test('DELETE /me without a session gets 401', function (): void {
    $this->deleteJson('/api/v1/me')
        ->assertStatus(401)
        ->assertValidResponse(401);
});
