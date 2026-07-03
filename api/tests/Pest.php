<?php

declare(strict_types=1);

use App\Models\DailyPuzzle;
use App\Models\Puzzle;
use App\Models\User;
use Database\Factories\PuzzleFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/**
 * A published daily on the given UTC date, backed by the burn-vector board in
 * PuzzleFactory (valid shading PuzzleFactory::VALID_SHADING).
 *
 * @param  array<string, mixed>  $dailyOverrides
 */
function seedDaily(string $date, array $dailyOverrides = []): DailyPuzzle
{
    $puzzle = Puzzle::factory()->create();

    /** @var DailyPuzzle $daily */
    $daily = DailyPuzzle::factory()->create([
        'date' => $date,
        'puzzle_id' => $puzzle->id,
        ...$dailyOverrides,
    ]);

    return $daily;
}

/**
 * A well-formed submitSolve payload for the fixture board; override per test.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function solvePayload(string $puzzleId, array $overrides = []): array
{
    return [
        'mode' => 'daily',
        'puzzle_id' => $puzzleId,
        'shaded' => PuzzleFactory::VALID_SHADING,
        'client_ms' => 60000,
        'started_at' => now('UTC')->subMinute()->toJSON(),
        'hints' => ['s1' => 0, 's2' => 0, 's3' => 0],
        'undo_count' => 0,
        ...$overrides,
    ];
}

/**
 * A gzipped replay + its ADR-0012 digest (sha256 over the UNCOMPRESSED JSON).
 *
 * @param  list<array{int, int, int}>  $events
 * @return array{replay: string, replay_sha256: string}
 */
function replayFixture(array $events): array
{
    $json = json_encode($events, JSON_THROW_ON_ERROR);
    $gzip = gzencode($json);

    assert(is_string($gzip));

    return [
        'replay' => base64_encode($gzip),
        'replay_sha256' => hash('sha256', $json),
    ];
}

function actingAsUser(): User
{
    /** @var User $user */
    $user = User::factory()->create();

    test()->actingAs($user);

    return $user;
}
