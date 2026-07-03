<?php

declare(strict_types=1);

namespace App\Domain\Ratings;

/**
 * Glicko-2 (Glickman, "Example of the Glicko-2 system", glicko.net, steps
 * 1-8) at FULL precision — no intermediate rounding (contracts/RATING.md §1;
 * the seven §6 fixtures are the acceptance tests for this class).
 *
 * The §4 mode weight applies to the rating delta only:
 * mu' = mu + w * (mu_glicko2 - mu); RD' and sigma' always take the full
 * update (ADR-0006).
 *
 * Pure math — no clock, no I/O, no state.
 */
final class Glicko2
{
    public const TAU = 0.5;

    public const EPSILON = 1e-6;

    public const SCALE = 173.7178;

    /**
     * One rating period. Burnfront plays one game per period (RATING.md §1),
     * but the paper's multi-game example (fixture F0) is supported.
     *
     * @param  list<array{rating: float, rd: float, score: float}>  $games
     */
    public function update(Glicko2State $player, array $games, float $weight = 1.0): Glicko2State
    {
        if ($games === []) {
            return $player;
        }

        // Step 2: convert to the Glicko-2 scale.
        $mu = ($player->rating - 1500.0) / self::SCALE;
        $phi = $player->rd / self::SCALE;

        // Step 3: estimated variance v; step 4 numerator: sum g(phi_j)(s_j - E).
        $vInverse = 0.0;
        $gain = 0.0;

        foreach ($games as $game) {
            $muJ = ($game['rating'] - 1500.0) / self::SCALE;
            $phiJ = $game['rd'] / self::SCALE;
            $g = self::g($phiJ);
            $e = self::e($mu, $muJ, $phiJ);
            $vInverse += $g * $g * $e * (1.0 - $e);
            $gain += $g * ($game['score'] - $e);
        }

        $v = 1.0 / $vInverse;

        // Step 4: estimated improvement delta.
        $delta = $v * $gain;

        // Step 5: new volatility.
        $sigma = $this->newVolatility($player->volatility, $delta, $phi, $v);

        // Step 6: pre-rating-period deviation.
        $phiStar = sqrt($phi * $phi + $sigma * $sigma);

        // Step 7: new deviation and rating.
        $phiPrime = 1.0 / sqrt(1.0 / ($phiStar * $phiStar) + 1.0 / $v);
        $muGlicko2 = $mu + $phiPrime * $phiPrime * $gain;

        // RATING.md §4: the weight scales the rating delta only.
        $muPrime = $mu + $weight * ($muGlicko2 - $mu);

        // Step 8: back to the Glicko scale.
        return new Glicko2State(
            self::SCALE * $muPrime + 1500.0,
            self::SCALE * $phiPrime,
            $sigma,
        );
    }

    /**
     * Step 5, the Illinois-variant regula falsi from the paper, iterated to
     * |B - A| <= epsilon.
     */
    private function newVolatility(float $sigma, float $delta, float $phi, float $v): float
    {
        $a = log($sigma * $sigma);

        $f = function (float $x) use ($a, $delta, $phi, $v): float {
            $ex = exp($x);
            $sum = $phi * $phi + $v + $ex;

            return ($ex * ($delta * $delta - $phi * $phi - $v - $ex)) / (2.0 * $sum * $sum)
                - ($x - $a) / (self::TAU * self::TAU);
        };

        $bigA = $a;

        if ($delta * $delta > $phi * $phi + $v) {
            $bigB = log($delta * $delta - $phi * $phi - $v);
        } else {
            $k = 1;

            while ($f($a - $k * self::TAU) < 0.0) {
                $k++;
            }

            $bigB = $a - $k * self::TAU;
        }

        $fA = $f($bigA);
        $fB = $f($bigB);

        while (abs($bigB - $bigA) > self::EPSILON) {
            $bigC = $bigA + ($bigA - $bigB) * $fA / ($fB - $fA);
            $fC = $f($bigC);

            if ($fC * $fB <= 0.0) {
                $bigA = $bigB;
                $fA = $fB;
            } else {
                $fA /= 2.0;
            }

            $bigB = $bigC;
            $fB = $fC;
        }

        return exp($bigA / 2.0);
    }

    private static function g(float $phi): float
    {
        return 1.0 / sqrt(1.0 + 3.0 * $phi * $phi / (M_PI * M_PI));
    }

    private static function e(float $mu, float $muJ, float $phiJ): float
    {
        return 1.0 / (1.0 + exp(-self::g($phiJ) * ($mu - $muJ)));
    }
}
