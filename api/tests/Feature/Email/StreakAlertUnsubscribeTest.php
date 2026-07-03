<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\URL;

function unsubscribeUrlFor(string $userId): string
{
    return URL::signedRoute('email.streak-alert.unsubscribe', ['userId' => $userId]);
}

test('the mailed link (GET) flips the preference off, one click, no login', function (): void {
    $user = User::factory()->create(['streak_alert_opt_in' => true]);

    $this->get(unsubscribeUrlFor($user->id))
        ->assertOk()
        ->assertSee('Streak protection alerts are off.');

    expect($user->refresh()->streak_alert_opt_in)->toBeFalse();
});

test('the RFC 8058 one-click POST flips the preference without session or CSRF token', function (): void {
    $user = User::factory()->create(['streak_alert_opt_in' => true]);

    // Mailbox providers post exactly this body, headlessly.
    $this->post(unsubscribeUrlFor($user->id), ['List-Unsubscribe' => 'One-Click'])
        ->assertOk();

    expect($user->refresh()->streak_alert_opt_in)->toBeFalse();
});

test('unsubscribing is idempotent and stays 200 on repeat clicks', function (): void {
    $user = User::factory()->create(['streak_alert_opt_in' => false]);
    $url = unsubscribeUrlFor($user->id);

    $this->get($url)->assertOk();
    $this->get($url)->assertOk();

    expect($user->refresh()->streak_alert_opt_in)->toBeFalse();
});

test('a tampered user id is rejected and flips nothing', function (): void {
    $victim = User::factory()->create(['streak_alert_opt_in' => true]);
    $attacker = User::factory()->create(['streak_alert_opt_in' => true]);

    // Re-point a validly signed URL at somebody else.
    $forged = str_replace($attacker->id, $victim->id, unsubscribeUrlFor($attacker->id));

    $this->get($forged)->assertForbidden();
    $this->post($forged)->assertForbidden();

    expect($victim->refresh()->streak_alert_opt_in)->toBeTrue()
        ->and($attacker->refresh()->streak_alert_opt_in)->toBeTrue();
});

test('an unsigned URL is rejected', function (): void {
    $user = User::factory()->create(['streak_alert_opt_in' => true]);

    $this->get(route('email.streak-alert.unsubscribe', ['userId' => $user->id]))
        ->assertForbidden();

    expect($user->refresh()->streak_alert_opt_in)->toBeTrue();
});

test('a signature minted for another route cannot be replayed here (single purpose)', function (): void {
    $user = User::factory()->create(['streak_alert_opt_in' => true]);

    // A valid signature for the export-download route, grafted onto the
    // unsubscribe path for the same user.
    $donor = URL::signedRoute('exports.download', ['userId' => $user->id, 'file' => 'x.json']);
    parse_str((string) parse_url($donor, PHP_URL_QUERY), $donorQuery);

    $forged = route('email.streak-alert.unsubscribe', ['userId' => $user->id])
        .'?signature='.((string) $donorQuery['signature']);

    $this->get($forged)->assertForbidden();

    expect($user->refresh()->streak_alert_opt_in)->toBeTrue();
});

test('an unknown or anonymized account gets the same calm page, nothing flips', function (): void {
    $ghost = User::factory()->anonymized()->create(['streak_alert_opt_in' => true]);

    $this->get(unsubscribeUrlFor($ghost->id))->assertOk();
    $this->get(unsubscribeUrlFor('01ARZ3NDEKTSV4RRFFQ69G5FAV'))->assertOk();

    // The anonymized row is deliberately left untouched by this route.
    expect($ghost->refresh()->streak_alert_opt_in)->toBeTrue();
});
