<?php

declare(strict_types=1);

namespace App\Domain\Ratings\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * WS-07 -> WS-08 seam. Dispatched after commit for every solve eligible for a
 * Glicko-2 update (RATING.md §5): valid, non-suspect, non-imported, no stage-3
 * hint. Nothing outside Domain\Ratings writes rating tables (arch rule), so
 * WS-07 only announces; WS-08 registers a QUEUED listener that loads the solve
 * row, computes §3 outcome / §4 weight, updates ratings + board_ratings and
 * appends the rating_events audit row.
 *
 * Delivery is at-least-once: the listener MUST be idempotent per solve_id
 * (rating_events.solve_id is the natural dedupe key).
 */
final class RatableSolveRecorded
{
    use Dispatchable;

    public function __construct(public readonly int $solveId) {}
}
