<?php

declare(strict_types=1);

use App\Models\AnalyticsEvent;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    $this->travelTo('2026-07-10 12:00:00 UTC');
});

/**
 * A well-formed recordEvents payload; override per test.
 *
 * @param  list<array<string, mixed>>|null  $events
 * @return array<string, mixed>
 */
function eventsPayload(?array $events = null, string $anonId = 'anon-0123456789'): array
{
    return [
        'anon_id' => $anonId,
        'events' => $events ?? [
            ['name' => 'first_seen', 'ts' => '2026-07-10T11:59:00Z'],
        ],
    ];
}

test('a valid batch is accepted with a bodiless 202 and lands in one insert', function (): void {
    $inserts = 0;
    DB::listen(function ($query) use (&$inserts): void {
        if (str_starts_with($query->sql, 'insert into "events"')) {
            $inserts++;
        }
    });

    $response = $this->postJson('/api/v1/events', eventsPayload([
        ['name' => 'first_seen', 'ts' => '2026-07-10T11:50:00Z'],
        ['name' => 'tutorial_step', 'ts' => '2026-07-10T11:52:00Z', 'props' => ['n' => 3]],
        ['name' => 'solve_start', 'ts' => '2026-07-10T11:55:00Z'],
        ['name' => 'hint_used', 'ts' => '2026-07-10T11:57:00Z', 'props' => ['stage' => 2]],
        ['name' => 'solve_complete', 'ts' => '2026-07-10T11:59:00Z', 'props' => [
            'puzzle_id' => 'p-2026-07-10', 'ms' => 240000, 'hint_stages' => 2,
            'undo_count' => 4, 'wrong_checks' => 1, 'first' => true,
        ]],
    ]));

    $response->assertStatus(202)->assertValidRequest()->assertValidResponse(202);
    expect($response->getContent())->toBe('');

    expect(AnalyticsEvent::query()->count())->toBe(5)
        ->and($inserts)->toBe(1);

    $solve = AnalyticsEvent::query()->where('name', 'solve_complete')->sole();
    // toEqual: jsonb does not preserve key order.
    expect($solve->anon_id)->toBe('anon-0123456789')
        ->and($solve->user_id)->toBeNull()
        ->and($solve->props)->toEqual([
            'puzzle_id' => 'p-2026-07-10', 'ms' => 240000, 'hint_stages' => 2,
            'undo_count' => 4, 'wrong_checks' => 1, 'first' => true,
        ]);
});

test('every catalog name round-trips', function (): void {
    $this->postJson('/api/v1/events', eventsPayload([
        ['name' => 'first_seen', 'ts' => '2026-07-10T11:00:00Z'],
        ['name' => 'tutorial_step', 'ts' => '2026-07-10T11:01:00Z', 'props' => ['n' => 1]],
        ['name' => 'solve_start', 'ts' => '2026-07-10T11:02:00Z'],
        ['name' => 'solve_complete', 'ts' => '2026-07-10T11:03:00Z', 'props' => ['puzzle_id' => 'p1', 'ms' => 1000, 'hint_stages' => 0, 'undo_count' => 0, 'wrong_checks' => 0, 'first' => true]],
        ['name' => 'board_abandoned', 'ts' => '2026-07-10T11:04:00Z', 'props' => ['ms' => 90000, 'marks_placed' => 12, 'last_action_ms' => 60000]],
        ['name' => 'hint_used', 'ts' => '2026-07-10T11:05:00Z', 'props' => ['stage' => 1]],
        ['name' => 'replay_watched', 'ts' => '2026-07-10T11:06:00Z', 'props' => ['fraction' => 0.75]],
        ['name' => 'share_clicked', 'ts' => '2026-07-10T11:07:00Z'],
        ['name' => 'account_created', 'ts' => '2026-07-10T11:08:00Z', 'props' => ['from_nudge' => false]],
    ]))->assertStatus(202)->assertValidRequest()->assertValidResponse(202);

    expect(AnalyticsEvent::query()->count())->toBe(9);
});

test('an authenticated session attaches user_id; anonymous stays null', function (): void {
    $user = actingAsUser();

    $this->postJson('/api/v1/events', eventsPayload())->assertStatus(202);

    expect(AnalyticsEvent::query()->sole()->user_id)->toBe($user->id);
});

test('client timestamps are clamped to [now-48h, now]', function (): void {
    $this->postJson('/api/v1/events', eventsPayload([
        ['name' => 'first_seen', 'ts' => '2026-07-10T11:00:00Z'],       // plausible: kept
        ['name' => 'solve_start', 'ts' => '2026-07-01T00:00:00Z'],      // 9 days back: floored
        ['name' => 'share_clicked', 'ts' => '2026-08-01T00:00:00Z'],    // future: ceiled to now
    ]))->assertStatus(202);

    $at = fn (string $name): string => AnalyticsEvent::query()->where('name', $name)->sole()->created_at?->toIso8601String() ?? '';

    expect($at('first_seen'))->toBe('2026-07-10T11:00:00+00:00')
        ->and($at('solve_start'))->toBe('2026-07-08T12:00:00+00:00')
        ->and($at('share_clicked'))->toBe('2026-07-10T12:00:00+00:00');
});

// --- abuse: schema-invalid payloads are rejected cheaply, before any write ---

test('an oversized batch of 26 is rejected with no rows written', function (): void {
    $events = array_map(
        fn (int $i): array => ['name' => 'solve_start', 'ts' => '2026-07-10T11:00:00Z'],
        range(1, 26),
    );

    $this->postJson('/api/v1/events', eventsPayload($events))
        ->assertStatus(422)
        ->assertValidResponse(422)
        ->assertJsonPath('error.code', 'validation_failed');

    expect(AnalyticsEvent::query()->count())->toBe(0);
});

test('a name outside the enum is rejected with no rows written', function (): void {
    $this->postJson('/api/v1/events', eventsPayload([
        ['name' => 'first_seen', 'ts' => '2026-07-10T11:00:00Z'],
        ['name' => 'page_view', 'ts' => '2026-07-10T11:00:01Z'],
    ]))->assertStatus(422)->assertValidResponse(422);

    expect(AnalyticsEvent::query()->count())->toBe(0);
});

test('the reserved rollup namespace is not reachable through the API', function (): void {
    foreach (['_rollup.solve_complete', '_system'] as $name) {
        $this->postJson('/api/v1/events', eventsPayload([
            ['name' => $name, 'ts' => '2026-07-10T11:00:00Z'],
        ]))->assertStatus(422);
    }

    expect(AnalyticsEvent::query()->count())->toBe(0);
});

test('unknown props for a known name are rejected with no rows written', function (): void {
    $this->postJson('/api/v1/events', eventsPayload([
        ['name' => 'hint_used', 'ts' => '2026-07-10T11:00:00Z', 'props' => ['stage' => 1, 'email' => 'x@example.test']],
    ]))->assertStatus(422)->assertValidResponse(422);

    expect(AnalyticsEvent::query()->count())->toBe(0);
});

test('mis-typed props are rejected with no rows written', function (): void {
    $cases = [
        ['name' => 'tutorial_step', 'props' => ['n' => 'three']],
        ['name' => 'hint_used', 'props' => ['stage' => 4]],
        ['name' => 'replay_watched', 'props' => ['fraction' => 1.5]],
        ['name' => 'account_created', 'props' => ['from_nudge' => 'yes']],
        ['name' => 'solve_complete', 'props' => ['puzzle_id' => 'p1', 'ms' => -5, 'hint_stages' => 0, 'undo_count' => 0, 'wrong_checks' => 0, 'first' => true]],
    ];

    foreach ($cases as $case) {
        $this->postJson('/api/v1/events', eventsPayload([
            [...$case, 'ts' => '2026-07-10T11:00:00Z'],
        ]))->assertStatus(422)->assertValidResponse(422);
    }

    expect(AnalyticsEvent::query()->count())->toBe(0);
});

test('missing required props are rejected with no rows written', function (): void {
    $this->postJson('/api/v1/events', eventsPayload([
        ['name' => 'solve_complete', 'ts' => '2026-07-10T11:00:00Z', 'props' => ['puzzle_id' => 'p1']],
    ]))->assertStatus(422)->assertValidResponse(422);

    expect(AnalyticsEvent::query()->count())->toBe(0);
});

test('huge props payloads are rejected with no rows written', function (): void {
    // 13 keys break the contract cap (maxProperties: 12) regardless of name.
    $bloat = array_combine(
        array_map(fn (int $i): string => "k{$i}", range(1, 13)),
        array_fill(0, 13, 1),
    );

    $this->postJson('/api/v1/events', eventsPayload([
        ['name' => 'first_seen', 'ts' => '2026-07-10T11:00:00Z', 'props' => $bloat],
    ]))->assertStatus(422)->assertValidResponse(422);

    // A megabyte-scale string hiding in a typed prop is rejected by type/cap.
    $this->postJson('/api/v1/events', eventsPayload([
        ['name' => 'solve_complete', 'ts' => '2026-07-10T11:00:00Z', 'props' => [
            'puzzle_id' => str_repeat('x', 100_000), 'ms' => 1, 'hint_stages' => 0,
            'undo_count' => 0, 'wrong_checks' => 0, 'first' => true,
        ]],
    ]))->assertStatus(422)->assertValidResponse(422);

    expect(AnalyticsEvent::query()->count())->toBe(0);
});

test('anon_id bounds and malformed ts are rejected with no rows written', function (): void {
    $this->postJson('/api/v1/events', eventsPayload(anonId: 'short'))
        ->assertStatus(422)->assertValidResponse(422);

    $this->postJson('/api/v1/events', eventsPayload(anonId: str_repeat('a', 65)))
        ->assertStatus(422)->assertValidResponse(422);

    $this->postJson('/api/v1/events', eventsPayload([
        ['name' => 'first_seen', 'ts' => 'yesterday'],
    ]))->assertStatus(422)->assertValidResponse(422);

    expect(AnalyticsEvent::query()->count())->toBe(0);
});

test('unknown fields are rejected (additionalProperties: false)', function (): void {
    $this->postJson('/api/v1/events', [...eventsPayload(), 'debug' => true])
        ->assertStatus(422)->assertValidResponse(422);

    $this->postJson('/api/v1/events', eventsPayload([
        ['name' => 'first_seen', 'ts' => '2026-07-10T11:00:00Z', 'ip' => '10.0.0.1'],
    ]))->assertStatus(422)->assertValidResponse(422);

    expect(AnalyticsEvent::query()->count())->toBe(0);
});

// --- abuse: over-rate ---

test('the 61st batch inside a minute is throttled per anon_id', function (): void {
    for ($i = 1; $i <= 60; $i++) {
        $this->postJson('/api/v1/events', eventsPayload())->assertStatus(202);
    }

    $this->postJson('/api/v1/events', eventsPayload())
        ->assertStatus(429)
        ->assertValidResponse(429)
        ->assertJsonPath('error.code', 'rate_limited');

    expect(AnalyticsEvent::query()->count())->toBe(60);

    // A different anon_id is not throttled — the key is the anon_id, not the IP.
    $this->postJson('/api/v1/events', eventsPayload(anonId: 'anon-other-crew-01'))
        ->assertStatus(202);
});
