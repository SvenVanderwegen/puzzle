<?php

declare(strict_types=1);

use App\Domain\Solves\SolveSubmissionService;
use App\Models\Solve;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spectator\Spectator;

// ADR-0020 riders (WS-08 scope): (a) replay_sha256 is required whenever a
// replay is present — enforced in validation, not just at digest-check time;
// (b) the SolveSubmissionService::mapUniqueViolation race branch, which only
// fires when two requests pass the pre-check simultaneously, exercised by
// faking the QueryException the losing insert would raise.

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    $this->travelTo('2026-07-10 12:00:00 UTC');
});

test('a replay without its digest is rejected with 422 (ADR-0020)', function (): void {
    actingAsUser();
    $daily = seedDaily('2026-07-10');
    $this->postJson('/api/v1/daily/2026-07-10/start')->assertStatus(204);
    $this->travel(2)->minutes();

    $replay = replayFixture([[0, 4, 1], [42000, 7, 1]]);
    unset($replay['replay_sha256']);

    $this->withHeader('Idempotency-Key', (string) Str::uuid7())
        ->postJson('/api/v1/solves', solvePayload($daily->puzzle_id, $replay))
        ->assertStatus(422)
        ->assertValidResponse(422)
        ->assertJsonPath('error.code', 'validation_failed');

    $this->assertDatabaseCount('solves', 0);
});

test('a digest without a replay remains schema-legal (structure unchanged by the errata)', function (): void {
    actingAsUser();
    $daily = seedDaily('2026-07-10');
    $this->postJson('/api/v1/daily/2026-07-10/start')->assertStatus(204);
    $this->travel(2)->minutes();

    $this->withHeader('Idempotency-Key', (string) Str::uuid7())
        ->postJson('/api/v1/solves', solvePayload($daily->puzzle_id, [
            'replay_sha256' => hash('sha256', 'irrelevant'),
        ]))
        ->assertStatus(201)
        ->assertValidResponse(201)
        ->assertJsonPath('valid', true);
});

function fakeUniqueViolation(string $constraint): QueryException
{
    return new QueryException(
        'pgsql',
        'insert into "solves" (...) values (...)',
        [],
        new PDOException(
            'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "'.$constraint.'"',
        ),
    );
}

/**
 * @return array{status: int, body: array<string, mixed>}
 */
function invokeMapUniqueViolation(QueryException $exception, string $userId, string $clientSolveId): array
{
    $method = new ReflectionMethod(SolveSubmissionService::class, 'mapUniqueViolation');

    /** @var array{status: int, body: array<string, mixed>} */
    return $method->invoke(app(SolveSubmissionService::class), $exception, $userId, $clientSolveId);
}

test('a lost client_solve_id race replays the stored snapshot with 200', function (): void {
    $user = User::factory()->create();
    $key = (string) Str::uuid7();
    $snapshot = ['solve_id' => '41', 'valid' => true, 'reason' => 'ok', 'suspect' => false];

    Solve::factory()->create([
        'user_id' => $user->id,
        'client_solve_id' => $key,
        'response_snapshot' => $snapshot,
    ]);

    $result = invokeMapUniqueViolation(fakeUniqueViolation('solves_user_client_unique'), $user->id, $key);

    expect($result['status'])->toBe(200)
        ->and($result['body'])->toEqual($snapshot);
});

test('a lost one-valid-daily race maps to the clean 422', function (): void {
    $user = User::factory()->create();

    expect(fn (): array => invokeMapUniqueViolation(fakeUniqueViolation('solves_one_valid_daily'), $user->id, (string) Str::uuid7()))
        ->toThrow(ValidationException::class, 'already contained');
});

test('unrelated query exceptions and phantom duplicates are rethrown untouched', function (): void {
    $user = User::factory()->create();

    // Not a unique-violation we own.
    $deadlock = new QueryException('pgsql', 'update "solves" ...', [], new PDOException('SQLSTATE[40P01]: deadlock detected'));

    try {
        invokeMapUniqueViolation($deadlock, $user->id, (string) Str::uuid7());
        $this->fail('The deadlock should have been rethrown.');
    } catch (QueryException $caught) {
        expect($caught)->toBe($deadlock);
    }

    // The constraint name matches but no stored row exists to replay: the
    // exception must surface rather than fabricate a response.
    $phantom = fakeUniqueViolation('solves_user_client_unique');

    try {
        invokeMapUniqueViolation($phantom, $user->id, (string) Str::uuid7());
        $this->fail('The phantom duplicate should have been rethrown.');
    } catch (QueryException $caught) {
        expect($caught)->toBe($phantom);
    }
});
