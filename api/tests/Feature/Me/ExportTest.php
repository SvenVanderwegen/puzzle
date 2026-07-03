<?php

declare(strict_types=1);

use App\Domain\Auth\Jobs\ExportUserData;
use App\Domain\Auth\Mail\ExportReadyMail;
use App\Models\AnalyticsEvent;
use App\Models\Solve;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spectator\Spectator;

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    Storage::fake('local');
    Mail::fake();
});

/**
 * Run the export flow and return the signed download URL from the mailable.
 */
function exportAndGrabUrl(User $user): string
{
    test()->actingAs($user)->getJson('/api/v1/me/export')->assertStatus(202);

    $url = null;
    Mail::assertQueued(ExportReadyMail::class, function (ExportReadyMail $mail) use (&$url, $user): bool {
        $url = $mail->url;

        return $mail->hasTo((string) $user->email);
    });

    assert(is_string($url));

    return $url;
}

test('GET /me/export queues the export job and returns 202', function (): void {
    Bus::fake([ExportUserData::class]);
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/me/export')
        ->assertStatus(202)
        ->assertValidResponse(202);

    Bus::assertDispatched(ExportUserData::class, fn (ExportUserData $job): bool => $job->userId === $user->id);
});

test('the export job writes a JSON file of all user data and mails a signed link', function (): void {
    $user = User::factory()->create(['email' => 'porter@example.test', 'timezone' => 'Europe/Brussels']);
    Solve::factory()->count(2)->withArtifacts()->create(['user_id' => $user->id]);
    DB::table('ratings')->insert(['user_id' => $user->id, 'rating' => 1550.0, 'games' => 3]);
    DB::table('streaks')->insert(['user_id' => $user->id, 'current_len' => 2, 'best_len' => 6]);
    DB::table('auth_identities')->insert([
        'user_id' => $user->id, 'provider' => 'email', 'provider_uid' => 'porter@example.test',
    ]);
    AnalyticsEvent::query()->create([
        'anon_id' => 'anon-12345678', 'user_id' => $user->id, 'name' => 'solve_complete', 'props' => ['ms' => 61000],
    ]);

    $url = exportAndGrabUrl($user);

    expect($url)->toContain('/exports/'.$user->id.'/')
        ->and($url)->toContain('signature=');

    $download = $this->actingAs($user)->get($url);
    $download->assertStatus(200)->assertHeader('Content-Type', 'application/json');

    /** @var array<string, mixed> $data */
    $data = json_decode((string) $download->getContent(), true, 512, JSON_THROW_ON_ERROR);

    expect($data['format'])->toBe('burnfront.export/1')
        ->and($data['user']['email'])->toBe('porter@example.test')
        ->and($data['user']['timezone'])->toBe('Europe/Brussels')
        ->and($data['auth_identities'])->toHaveCount(1)
        ->and($data['solves'])->toHaveCount(2)
        ->and($data['solves'][0]['shaded_bits'])->toBe('011010')
        ->and($data['solves'][0]['replay_base64'])->not->toBeNull()
        ->and($data['rating']['rating'])->toEqual(1550.0)
        ->and($data['rating']['games'])->toBe(3)
        ->and($data['streak']['current_len'])->toBe(2)
        ->and($data['analytics_events'])->toHaveCount(1)
        ->and($data['analytics_events'][0]['name'])->toBe('solve_complete');
});

test('the export link is single-download', function (): void {
    $user = User::factory()->create();
    $url = exportAndGrabUrl($user);

    $this->actingAs($user)->get($url)->assertStatus(200);
    $this->actingAs($user)->get($url)->assertStatus(410);
});

test('the export download requires a live session for the same user', function (): void {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $url = exportAndGrabUrl($user);

    // exportAndGrabUrl authenticated as the owner. Reset every cached guard
    // (in production each request builds a fresh app; in tests the sanctum
    // request-guard would otherwise keep the owner cached).
    $this->app['auth']->forgetGuards();
    $this->get($url)->assertStatus(403);

    $this->app['auth']->forgetGuards();
    $this->actingAs($stranger)->get($url)->assertStatus(403);

    // Still downloadable by the owner afterwards (the failed attempts must not consume it).
    $this->app['auth']->forgetGuards();
    $this->actingAs($user)->get($url)->assertStatus(200);
});

test('the export link expires after 24 hours', function (): void {
    $user = User::factory()->create();
    $url = exportAndGrabUrl($user);

    $this->travel(25)->hours();

    $this->actingAs($user)->get($url)->assertStatus(403);
});

test('a tampered signature is rejected', function (): void {
    $user = User::factory()->create();
    $url = exportAndGrabUrl($user);

    $this->actingAs($user)->get($url.'tampered')->assertStatus(403);
});

test('export requests are throttled', function (): void {
    Bus::fake([ExportUserData::class]);
    $user = User::factory()->create();

    foreach (range(1, 3) as $i) {
        $this->actingAs($user)->getJson('/api/v1/me/export')->assertStatus(202);
    }

    $this->actingAs($user)->getJson('/api/v1/me/export')
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertValidResponse(429);
});

test('GET /me/export without a session gets 401', function (): void {
    $this->getJson('/api/v1/me/export')
        ->assertStatus(401)
        ->assertValidResponse(401);
});
