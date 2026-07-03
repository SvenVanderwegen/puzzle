<?php

declare(strict_types=1);

use App\Domain\Streaks\Mail\StreakAlertSubscribedMail;
use Illuminate\Support\Facades\Mail;
use Spectator\Spectator;

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    Mail::fake();
});

test('turning alerts on queues the opt-in confirmation with its off switch', function (): void {
    $user = actingAsUser();

    $this->patchJson('/api/v1/me', ['streak_alert_opt_in' => true])
        ->assertOk()
        ->assertValidResponse(200)
        ->assertJsonPath('streak_alert_opt_in', true);

    Mail::assertQueued(StreakAlertSubscribedMail::class, function (StreakAlertSubscribedMail $mail) use ($user): bool {
        return $mail->hasTo((string) $user->email)
            && str_contains($mail->unsubscribeUrl(), '/email/streak-alert/unsubscribe/'.$user->id);
    });
    Mail::assertQueuedCount(1);
});

test('re-affirming an existing opt-in sends nothing new', function (): void {
    $user = actingAsUser();
    $user->forceFill(['streak_alert_opt_in' => true])->save();

    $this->patchJson('/api/v1/me', ['streak_alert_opt_in' => true])->assertOk();

    Mail::assertNothingQueued();
});

test('turning alerts off, or patching unrelated fields, sends nothing', function (): void {
    $user = actingAsUser();
    $user->forceFill(['streak_alert_opt_in' => true])->save();

    $this->patchJson('/api/v1/me', ['streak_alert_opt_in' => false])->assertOk();
    $this->patchJson('/api/v1/me', ['timezone' => 'Europe/Brussels'])->assertOk();

    Mail::assertNothingQueued();
});

test('the confirmation unsubscribe link really works end to end', function (): void {
    $user = actingAsUser();

    $this->patchJson('/api/v1/me', ['streak_alert_opt_in' => true])->assertOk();

    $url = null;
    Mail::assertQueued(StreakAlertSubscribedMail::class, function (StreakAlertSubscribedMail $mail) use (&$url): bool {
        $url = $mail->unsubscribeUrl();

        return true;
    });

    assert(is_string($url));
    $this->get($url)->assertOk();

    expect($user->refresh()->streak_alert_opt_in)->toBeFalse();
});
