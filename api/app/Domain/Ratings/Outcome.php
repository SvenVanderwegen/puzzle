<?php

declare(strict_types=1);

namespace App\Domain\Ratings;

/**
 * RATING.md §3 outcome function and §4 mode weights. Hints decide, time never
 * does (v1). Stage-3 hints, suspect, invalid and imported solves never reach
 * this code — they are filtered before dispatch and again in RatingService.
 */
final class Outcome
{
    /**
     * A daily started (puzzle_fetches stamp) but left unsolved at UTC
     * rollover (RATING.md §3).
     */
    public const FAILED_DAILY_SCORE = 0.25;

    public const WEIGHT_ENDLESS = 0.5;

    /**
     * s = max(0.5, 1.0 - 0.15 * min(hints_s1, 1) - 0.15 * hints_s2):
     * only the first stage-1 hint counts; every stage-2 hint trims 0.15 more,
     * floored at 0.5.
     */
    public static function solveScore(int $hintsS1, int $hintsS2): float
    {
        return max(0.5, 1.0 - 0.15 * min($hintsS1, 1) - 0.15 * $hintsS2);
    }

    /**
     * w(daily) = w(pack) = 1.0; w(endless) = 0.5 (ADR-0006). Applies to the
     * user's rating delta only — the board side always updates at 1.0.
     */
    public static function weightFor(string $mode): float
    {
        return $mode === 'endless' ? self::WEIGHT_ENDLESS : 1.0;
    }
}
