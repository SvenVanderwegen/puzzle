<?php

declare(strict_types=1);

namespace App\Domain\Ratings\Listeners;

use App\Domain\Ratings\Events\RatableSolveRecorded;
use App\Domain\Ratings\RatingService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Queued consumer of the WS-07 seam (see the RatableSolveRecorded docblock):
 * the solve endpoint only announces; the Glicko-2 update lands async.
 * Delivery is at-least-once — RatingService dedupes per solve_id under the
 * user's ratings row lock.
 */
final class ApplyRatableSolve implements ShouldQueue
{
    public function __construct(private readonly RatingService $ratings) {}

    public function handle(RatableSolveRecorded $event): void
    {
        $this->ratings->applyRatableSolve($event->solveId);
    }
}
