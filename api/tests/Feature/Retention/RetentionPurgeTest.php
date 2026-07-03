<?php

declare(strict_types=1);

use App\Models\AnalyticsEvent;
use App\Models\FrontendError;
use App\Models\Solve;
use App\Models\User;
use Illuminate\Support\Facades\Schedule;

test('solve replay and ip/ua hashes are purged at 90 days, rows kept', function (): void {
    $user = User::factory()->create();
    $old = Solve::factory()->withArtifacts()->create(['user_id' => $user->id]);

    $this->travel(91)->days();
    $fresh = Solve::factory()->withArtifacts()->create(['user_id' => $user->id]);

    $this->artisan('retention:purge-solve-artifacts')->assertSuccessful();

    $old->refresh();
    $fresh->refresh();

    expect($old->replay)->toBeNull()
        ->and($old->ip_hash)->toBeNull()
        ->and($old->ua_hash)->toBeNull()
        // The solve itself and its non-PII columns survive.
        ->and($old->valid)->toBeTrue()
        ->and($old->replay_sha256)->not->toBeNull()
        ->and(Solve::query()->count())->toBe(2)
        // Fresh rows keep their artifacts.
        ->and($fresh->ip_hash)->not->toBeNull()
        ->and($fresh->ua_hash)->not->toBeNull();
});

test('frontend_errors rows are deleted at 90 days', function (): void {
    FrontendError::query()->create(['message' => 'stale beacon', 'created_at' => now()]);

    $this->travel(91)->days();
    FrontendError::query()->create(['message' => 'fresh beacon', 'created_at' => now()]);

    $this->artisan('retention:purge-frontend-errors')->assertSuccessful();

    expect(FrontendError::query()->pluck('message')->all())->toBe(['fresh beacon']);
});

test('events rows are aggregated-then-deleted at 13 months', function (): void {
    AnalyticsEvent::query()->create(['anon_id' => 'anon-12345678', 'name' => 'first_seen', 'props' => [], 'created_at' => now()]);

    $this->travel(14)->months();
    AnalyticsEvent::query()->create(['anon_id' => 'anon-12345678', 'name' => 'solve_complete', 'props' => [], 'created_at' => now()]);

    $this->artisan('retention:purge-events')->assertSuccessful();

    // The raw expired row is gone; what remains is the fresh row plus the
    // WS-19 monthly rollup written before the delete (aggregate-then-purge).
    $names = AnalyticsEvent::query()->orderBy('id')->pluck('name')->all();
    sort($names);

    expect($names)->toBe(['_rollup.first_seen', 'solve_complete']);
});

test('the retention commands are scheduled daily, without double runs', function (): void {
    $events = collect(Schedule::events())->map(fn ($event): string => (string) $event->command);

    // analytics:purge (WS-19) covers events + frontend_errors; the narrower
    // WS-06 commands still exist for manual runs but are not scheduled twice.
    expect($events->filter(fn (string $cmd): bool => str_contains($cmd, 'retention:purge-solve-artifacts')))->toHaveCount(1)
        ->and($events->filter(fn (string $cmd): bool => str_contains($cmd, 'analytics:purge')))->toHaveCount(1)
        ->and($events->filter(fn (string $cmd): bool => str_contains($cmd, 'retention:purge-frontend-errors')))->toHaveCount(0)
        ->and($events->filter(fn (string $cmd): bool => str_contains($cmd, 'retention:purge-events')))->toHaveCount(0);
});
