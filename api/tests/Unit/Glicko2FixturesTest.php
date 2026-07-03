<?php

declare(strict_types=1);

use App\Domain\Ratings\Glicko2;
use App\Domain\Ratings\Glicko2State;
use App\Domain\Ratings\Outcome;

// contracts/RATING.md §6 — the seven normative fixtures. Ratings and RD must
// reproduce to 4 decimals and sigma to 6, at full precision (no intermediate
// rounding; the fixtures, not the paper's rounded example, are normative).

dataset('rating fixtures', [
    'F0 Glickman paper check' => [
        [1500.0, 200.0, 0.06],
        [[1400.0, 30.0, 1.0], [1550.0, 100.0, 0.0], [1700.0, 300.0, 0.0]],
        1.0,
        ['1464.0507', '151.5165', '0.059996'],
    ],
    'F1 clean daily solve' => [
        [1500.0, 350.0, 0.06],
        [[1408.0, 200.0, 1.0]],
        1.0,
        ['1637.6094', '269.4299', '0.059999'],
    ],
    'F2 hinted daily (1xs1 + 2xs2, s = 0.55)' => [
        [1500.0, 350.0, 0.06],
        [[1408.0, 200.0, 0.55]],
        1.0,
        ['1478.8473', '269.4299', '0.059999'],
    ],
    // Endless-mode plumbing for F3 is covered end-to-end in
    // Feature/Ratings/RatingUpdateTest; here the weight is passed literally.
    'F3 half-delta (literal weight)' => [
        [1500.0, 350.0, 0.06],
        [[1408.0, 200.0, 1.0]],
        0.5,
        ['1568.8047', '269.4299', '0.059999'],
    ],
    'F4 failed daily, s = 0.25' => [
        [1500.0, 350.0, 0.06],
        [[1650.0, 200.0, 0.25]],
        1.0,
        ['1472.5281', '273.7811', '0.059999'],
    ],
    'F5 board side of F1 (s_board = 0.0)' => [
        [1408.0, 200.0, 0.06],
        [[1500.0, 350.0, 0.0]],
        1.0,
        ['1352.3300', '187.2294', '0.060000'],
    ],
    'F6 strong user vs easy board' => [
        [1620.0, 80.0, 0.06],
        [[1080.0, 150.0, 1.0]],
        1.0,
        ['1621.9090', '80.2978', '0.059999'],
    ],
]);

test('the Glicko-2 engine reproduces the RATING.md fixture', function (array $player, array $games, float $weight, array $expected): void {
    $engine = new Glicko2;

    $result = $engine->update(
        new Glicko2State($player[0], $player[1], $player[2]),
        array_map(
            fn (array $game): array => ['rating' => $game[0], 'rd' => $game[1], 'score' => $game[2]],
            $games,
        ),
        $weight,
    );

    expect(number_format($result->rating, 4, '.', ''))->toBe($expected[0])
        ->and(number_format($result->rd, 4, '.', ''))->toBe($expected[1])
        ->and(number_format($result->volatility, 6, '.', ''))->toBe($expected[2]);
})->with('rating fixtures');

test('the §4 weight scales exactly the rating delta, never RD or volatility', function (): void {
    $engine = new Glicko2;
    $start = new Glicko2State(1500.0, 350.0, 0.06);
    $game = [['rating' => 1408.0, 'rd' => 200.0, 'score' => 1.0]];

    $full = $engine->update($start, $game);
    $half = $engine->update($start, $game, 0.5);

    expect($half->rating - 1500.0)->toEqualWithDelta(($full->rating - 1500.0) / 2.0, 1e-9)
        ->and($half->rd)->toBe($full->rd)
        ->and($half->volatility)->toBe($full->volatility);
});

test('the §3 outcome function: hints decide, floored at 0.5, first s1 only', function (): void {
    expect(Outcome::solveScore(0, 0))->toBe(1.0)
        ->and(Outcome::solveScore(1, 0))->toEqualWithDelta(0.85, 1e-12)
        ->and(Outcome::solveScore(5, 0))->toEqualWithDelta(0.85, 1e-12)
        ->and(Outcome::solveScore(1, 1))->toEqualWithDelta(0.70, 1e-12)
        ->and(Outcome::solveScore(1, 2))->toEqualWithDelta(0.55, 1e-12)
        ->and(Outcome::solveScore(0, 4))->toBe(0.5)
        ->and(Outcome::solveScore(1, 30))->toBe(0.5);
});

test('the §4 mode weights', function (): void {
    expect(Outcome::weightFor('daily'))->toBe(1.0)
        ->and(Outcome::weightFor('pack'))->toBe(1.0)
        ->and(Outcome::weightFor('endless'))->toBe(0.5);
});
