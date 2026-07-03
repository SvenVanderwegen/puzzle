<?php

declare(strict_types=1);

use App\Domain\Auth\Mail\MagicLinkMail;
use App\Models\MagicLinkToken;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Spectator\Spectator;

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    Mail::fake();
});

test('a well-formed request returns a constant 202 and mails a link', function (): void {
    $response = $this->postJson('/api/v1/auth/magic-link', ['email' => 'new-crew@example.test']);

    $response->assertStatus(202)->assertValidResponse(202);
    expect($response->getContent())->toBe('');

    Mail::assertSent(MagicLinkMail::class, fn (MagicLinkMail $mail): bool => $mail->hasTo('new-crew@example.test'));
});

test('the stored token is sha256-hashed with a 15-minute expiry', function (): void {
    $this->postJson('/api/v1/auth/magic-link', ['email' => 'crew@example.test'])->assertStatus(202);

    $raw = null;
    Mail::assertSent(MagicLinkMail::class, function (MagicLinkMail $mail) use (&$raw): bool {
        $raw = $mail->token;

        return true;
    });

    /** @var string $raw */
    expect(strlen($raw))->toBe(64);

    $row = MagicLinkToken::query()->sole();
    expect($row->token_hash)->toBe(hash('sha256', $raw))
        ->and($row->token_hash)->not->toBe($raw)
        ->and($row->consumed_at)->toBeNull()
        ->and($row->expires_at->diffInMinutes(now()->addMinutes(15)))->toBeLessThan(1);

    // The emailed link points at the SPA consume screen.
    Mail::assertSent(MagicLinkMail::class, fn (MagicLinkMail $mail): bool => str_contains($mail->url(), '/auth/consume?token='));
});

test('known and unknown emails get an indistinguishable response', function (): void {
    User::factory()->create(['email' => 'veteran@example.test']);

    $known = $this->postJson('/api/v1/auth/magic-link', ['email' => 'veteran@example.test']);
    $unknown = $this->postJson('/api/v1/auth/magic-link', ['email' => 'stranger@example.test']);

    // Same status, same (empty) body — and the same work happens on both paths:
    // a token row is stored and a mail is sent whether or not the account exists.
    expect($known->getStatusCode())->toBe($unknown->getStatusCode())
        ->and($known->getContent())->toBe($unknown->getContent());

    expect(MagicLinkToken::query()->where('email', 'veteran@example.test')->count())->toBe(1)
        ->and(MagicLinkToken::query()->where('email', 'stranger@example.test')->count())->toBe(1);

    Mail::assertSent(MagicLinkMail::class, 2);
});

test('a malformed email is rejected with the error envelope', function (): void {
    $response = $this->postJson('/api/v1/auth/magic-link', ['email' => 'not-an-email']);

    $response->assertStatus(422)
        ->assertJsonStructure(['error' => ['code', 'message']])
        ->assertJsonPath('error.code', 'validation_failed');
});

test('requests are throttled to 3 per hour per email, across IPs', function (): void {
    foreach (['10.1.0.1', '10.1.0.2', '10.1.0.3'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/auth/magic-link', ['email' => 'hot@example.test'])
            ->assertStatus(202);
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.4'])
        ->postJson('/api/v1/auth/magic-link', ['email' => 'hot@example.test']);

    $response->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertValidResponse(429);

    // Case variants of the same address share the throttle bucket (citext semantics).
    $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.5'])
        ->postJson('/api/v1/auth/magic-link', ['email' => 'HOT@EXAMPLE.TEST'])
        ->assertStatus(429);
});

test('requests are throttled to 5 per minute per IP', function (): void {
    foreach (range(1, 5) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.9'])
            ->postJson('/api/v1/auth/magic-link', ['email' => "crew-{$i}@example.test"])
            ->assertStatus(202);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.9'])
        ->postJson('/api/v1/auth/magic-link', ['email' => 'crew-6@example.test'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    // A different IP is unaffected.
    $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.10'])
        ->postJson('/api/v1/auth/magic-link', ['email' => 'crew-7@example.test'])
        ->assertStatus(202);
});
