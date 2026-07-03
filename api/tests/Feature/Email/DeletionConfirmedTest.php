<?php

declare(strict_types=1);

use App\Domain\Auth\Jobs\AnonymizeUser;
use App\Domain\Auth\Mail\DeletionConfirmedMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
});

test('erasure queues one deletion receipt to the pre-anonymization address', function (): void {
    $user = User::factory()->create(['email' => 'leaving@example.test']);

    // Sync queue: the anonymize job runs inside this request.
    $this->actingAs($user)->deleteJson('/api/v1/me')->assertStatus(202);

    expect($user->refresh()->email)->toBeNull();

    Mail::assertQueued(DeletionConfirmedMail::class, fn (DeletionConfirmedMail $mail): bool => $mail->hasTo('leaving@example.test'));
    Mail::assertQueuedCount(1);
});

test('idempotent anonymize replays never send a second receipt', function (): void {
    $user = User::factory()->create();

    AnonymizeUser::dispatchSync($user->id);
    AnonymizeUser::dispatchSync($user->id);

    Mail::assertQueuedCount(1);
});

test('anonymizing an account that already lost its email sends nothing', function (): void {
    $user = User::factory()->create(['email' => null]);

    AnonymizeUser::dispatchSync($user->id);

    Mail::assertNothingQueued();
});
