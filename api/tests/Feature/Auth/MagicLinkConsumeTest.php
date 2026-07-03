<?php

declare(strict_types=1);

use App\Domain\Auth\MagicLinkService;
use App\Domain\Auth\Mail\MagicLinkMail;
use App\Models\MagicLinkToken;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Spectator\Spectator;

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    Mail::fake();
});

/**
 * Request a link through the endpoint and capture the raw token from the mailable.
 */
function requestToken(string $email): string
{
    $token = null;

    test()->postJson('/api/v1/auth/magic-link', ['email' => $email])->assertStatus(202);

    Mail::assertQueued(MagicLinkMail::class, function (MagicLinkMail $mail) use (&$token): bool {
        $token = $mail->token;

        return true;
    });

    assert(is_string($token));

    return $token;
}

test('consuming a fresh token signs the user in and creates the account', function (): void {
    $token = requestToken('First.Crew@Example.Test');

    $response = $this->postJson('/api/v1/auth/magic-link/consume', ['token' => $token]);

    $response->assertStatus(204)->assertValidResponse(204);
    $this->assertAuthenticated('web');

    // First consume creates users + auth_identities (ADR-0003), email normalized.
    $user = User::query()->sole();
    expect($user->email)->toBe('first.crew@example.test')
        ->and($user->anonymized_at)->toBeNull();

    $this->assertDatabaseHas('auth_identities', [
        'user_id' => $user->id,
        'provider' => 'email',
        'provider_uid' => 'first.crew@example.test',
    ]);
});

test('consuming a token for an existing account signs in without duplicating rows', function (): void {
    $user = User::factory()->create(['email' => 'veteran@example.test']);
    $token = requestToken('veteran@example.test');

    $this->postJson('/api/v1/auth/magic-link/consume', ['token' => $token])->assertStatus(204);

    $this->assertAuthenticatedAs($user, 'web');
    expect(User::query()->count())->toBe(1);
});

test('a token is single-use: the second consume gets 410', function (): void {
    $token = requestToken('crew@example.test');

    $this->postJson('/api/v1/auth/magic-link/consume', ['token' => $token])->assertStatus(204);

    $this->postJson('/api/v1/auth/magic-link/consume', ['token' => $token])
        ->assertStatus(410)
        ->assertValidResponse(410);

    expect(MagicLinkToken::query()->sole()->consumed_at)->not->toBeNull();
});

test('an expired token gets 410', function (): void {
    $token = requestToken('late@example.test');

    $this->travel(MagicLinkService::TTL_MINUTES + 1)->minutes();

    $this->postJson('/api/v1/auth/magic-link/consume', ['token' => $token])
        ->assertStatus(410)
        ->assertValidResponse(410);

    $this->assertGuest('web');
    expect(User::query()->count())->toBe(0);
});

test('an unknown token gets 410', function (): void {
    $this->postJson('/api/v1/auth/magic-link/consume', ['token' => str_repeat('ab', 32)])
        ->assertStatus(410)
        ->assertValidResponse(410);
});

test('the session id is rotated on consume', function (): void {
    $token = requestToken('rotate@example.test');

    // Establish a pre-auth session and round-trip its cookie, as a browser would.
    $prime = $this->getJson('/api/v1/me');
    $cookieName = config('session.cookie');
    expect($cookieName)->toBe('burnfront_session');

    $sent = collect($prime->headers->getCookies())
        ->first(fn ($cookie): bool => $cookie->getName() === $cookieName);
    expect($sent)->not->toBeNull();

    $idBefore = $this->app['session.store']->getId();

    $consume = $this->withUnencryptedCookie($cookieName, (string) $sent->getValue())
        ->postJson('/api/v1/auth/magic-link/consume', ['token' => $token]);

    $consume->assertStatus(204);
    expect($this->app['session.store']->getId())->not->toBe($idBefore);
});

test('consume attempts are throttled per IP', function (): void {
    foreach (range(1, 5) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.3.0.7'])
            ->postJson('/api/v1/auth/magic-link/consume', ['token' => str_repeat((string) $i, 40)])
            ->assertStatus(410);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.3.0.7'])
        ->postJson('/api/v1/auth/magic-link/consume', ['token' => str_repeat('6', 40)])
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertValidResponse(429);
});

test('a malformed token is rejected with the error envelope', function (): void {
    $this->postJson('/api/v1/auth/magic-link/consume', ['token' => 'short'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});
