<?php

declare(strict_types=1);

use App\Domain\Ratings\RatingService;
use App\Models\DailyPuzzle;
use App\Models\Puzzle;
use App\Models\Solve;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Brief acceptance: recompute-from-events equals live state after a simulated
// month (property test). The live chain passes through float32 columns
// between games; the replay must reproduce it to 6 decimals — in practice
// bit-for-bit, via the same float4 quantization.

/**
 * @return array<string, array<string, mixed>>
 */
function ratingTablesSnapshot(): array
{
    $snapshot = [];

    foreach (DB::table('ratings')->orderBy('user_id')->get() as $row) {
        $snapshot['user:'.$row->user_id] = (array) $row;
    }

    foreach (DB::table('board_ratings')->orderBy('puzzle_id')->get() as $row) {
        $snapshot['board:'.$row->puzzle_id] = (array) $row;
    }

    return $snapshot;
}

function assertSnapshotsEqualTo6dp(array $expected, array $actual): void
{
    expect(array_keys($actual))->toBe(array_keys($expected));

    foreach ($expected as $key => $row) {
        foreach (['rating', 'rd', 'volatility'] as $column) {
            expect(abs((float) $actual[$key][$column] - (float) $row[$column]))
                ->toBeLessThan(1e-6, "{$key}.{$column} drifted beyond 6 decimals");
        }

        foreach (['games', 'attempts'] as $column) {
            if (array_key_exists($column, $row)) {
                expect((int) $actual[$key][$column])->toBe((int) $row[$column], "{$key}.{$column} differs");
            }
        }
    }
}

test('a simulated month of mixed solves recomputes to the live state to 6 decimals', function (): void {
    $this->travelTo('2026-06-01 12:00:00 UTC');

    $service = app(RatingService::class);

    /** @var list<User> $users */
    $users = User::factory()->count(3)->create()->all();

    // A June calendar: ten dailies across the three tiers.
    $tiers = [
        ['lookout', 4], ['lookout', 7], ['crew', 12], ['crew', 18], ['hotshot', 24],
        ['lookout', 5], ['crew', 15], ['hotshot', 30], ['crew', 20], ['lookout', 6],
    ];
    $dailies = [];

    foreach ($tiers as $day => [$tier, $score]) {
        $puzzle = Puzzle::factory()->create(['grade_tier' => $tier, 'grade_score' => $score]);
        $dailies[] = DailyPuzzle::factory()->create([
            'date' => sprintf('2026-06-%02d', $day + 1),
            'puzzle_id' => $puzzle->id,
        ]);
    }

    $packPuzzle = Puzzle::factory()->create(['grade_tier' => 'crew', 'grade_score' => 16, 'pack_id' => 'starter']);

    $makeSolve = function (User $user, ?string $puzzleId, string $mode, array $overrides = []): Solve {
        return Solve::factory()->create([
            'user_id' => $user->id,
            'puzzle_id' => $puzzleId,
            'mode' => $mode,
            'endless_spec' => $mode === 'endless'
                ? ['rows' => 3, 'cols' => 3, 'spark' => [2, 0], 'breaks' => 2,
                    'clues' => [['r' => 2, 'c' => 2, 'm' => 6]], 'deduction_steps' => 6 + ($overrides['hints_s2'] ?? 0)]
                : null,
            ...$overrides,
        ]);
    };

    // The month: interleaved users, all modes, hints, failed dailies,
    // duplicate deliveries and skipped (suspect/imported/stage-3) solves.
    foreach ($dailies as $day => $daily) {
        $this->travelTo(sprintf('2026-06-%02d 18:00:00 UTC', $day + 1));

        foreach ($users as $i => $user) {
            $pattern = ($day + $i) % 4;

            if ($pattern === 0) {
                // Clean daily solve.
                $solve = $makeSolve($user, $daily->puzzle_id, 'daily');
                $service->applyRatableSolve($solve->id);
            } elseif ($pattern === 1) {
                // Hinted daily.
                $solve = $makeSolve($user, $daily->puzzle_id, 'daily', ['hints_s1' => 1, 'hints_s2' => $day % 3]);
                $service->applyRatableSolve($solve->id);
                // Duplicate delivery (at-least-once seam).
                $service->applyRatableSolve($solve->id);
            } elseif ($pattern === 2) {
                // Failed daily at rollover.
                $service->applyFailedDaily($user->id, $daily->date, $daily->puzzle_id);
                // Rollover re-run.
                $service->applyFailedDaily($user->id, $daily->date, $daily->puzzle_id);
            } else {
                // Endless evening plus a solve that must never rate.
                $solve = $makeSolve($user, null, 'endless', ['hints_s2' => $day % 2]);
                $service->applyRatableSolve($solve->id);

                $skipped = $makeSolve($user, $daily->puzzle_id, 'daily', ['suspect' => true]);
                $service->applyRatableSolve($skipped->id);
            }
        }
    }

    // A pack solve closes the month.
    $solve = $makeSolve($users[0], $packPuzzle->id, 'pack', ['hints_s1' => 2]);
    $service->applyRatableSolve($solve->id);

    $live = ratingTablesSnapshot();
    expect(DB::table('rating_events')->count())->toBeGreaterThan(20)
        ->and(count($live))->toBeGreaterThan(10);

    // Corrupt everything, then replay the audit stream.
    DB::table('ratings')->update(['rating' => 1.0, 'rd' => 1.0, 'volatility' => 1.0, 'games' => 0]);
    DB::table('board_ratings')->update(['rating' => 1.0, 'rd' => 1.0, 'volatility' => 1.0, 'attempts' => 0]);

    $this->artisan('ratings:recompute')->assertExitCode(0);

    assertSnapshotsEqualTo6dp($live, ratingTablesSnapshot());
});

test('ratings:recompute --user rewrites exactly that user', function (): void {
    $this->travelTo('2026-06-01 12:00:00 UTC');

    $service = app(RatingService::class);
    [$alice, $bob] = User::factory()->count(2)->create()->all();

    $puzzle = Puzzle::factory()->create(['grade_tier' => 'crew', 'grade_score' => 27]);
    DailyPuzzle::factory()->create(['date' => '2026-06-01', 'puzzle_id' => $puzzle->id]);

    foreach ([$alice, $bob] as $user) {
        $solve = Solve::factory()->create([
            'user_id' => $user->id,
            'puzzle_id' => $puzzle->id,
            'mode' => 'daily',
            'endless_spec' => null,
        ]);
        $service->applyRatableSolve($solve->id);
    }

    $live = ratingTablesSnapshot();

    // Corrupt both users and the board; ask for Alice only.
    DB::table('ratings')->update(['rating' => 1.0, 'games' => 0]);
    DB::table('board_ratings')->update(['rating' => 1.0]);

    $this->artisan('ratings:recompute', ['--user' => $alice->id])->assertExitCode(0);

    $after = ratingTablesSnapshot();

    expect(abs((float) $after['user:'.$alice->id]['rating'] - (float) $live['user:'.$alice->id]['rating']))->toBeLessThan(1e-6)
        ->and((int) $after['user:'.$alice->id]['games'])->toBe(1)
        ->and((float) $after['user:'.$bob->id]['rating'])->toBe(1.0)   // untouched
        ->and((float) $after['board:'.$puzzle->id]['rating'])->toBe(1.0); // boards never written with --user

    // A full recompute repairs the rest.
    $this->artisan('ratings:recompute')->assertExitCode(0);
    assertSnapshotsEqualTo6dp($live, ratingTablesSnapshot());

    // Unknown user: replayed but nothing written.
    $this->artisan('ratings:recompute', ['--user' => 'no-such-user'])
        ->expectsOutputToContain('No rating events are attributed')
        ->assertExitCode(0);
});
