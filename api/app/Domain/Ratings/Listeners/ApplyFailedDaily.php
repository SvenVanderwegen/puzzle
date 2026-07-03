<?php

declare(strict_types=1);

namespace App\Domain\Ratings\Listeners;

use App\Domain\Ratings\Events\FailedDailyRecorded;
use App\Domain\Ratings\RatingService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Queued consumer of the streaks:rollover failed-daily hook (RATING.md §3:
 * s = 0.25, one per user per day max). Delivery is at-least-once — a rollover
 * re-run re-emits — and RatingService dedupes per (user_id, date) via the
 * deterministic client_solve_id of the synthetic audit-anchor solve row.
 */
final class ApplyFailedDaily implements ShouldQueue
{
    public function __construct(private readonly RatingService $ratings) {}

    public function handle(FailedDailyRecorded $event): void
    {
        $this->ratings->applyFailedDaily($event->userId, $event->date, $event->puzzleId);
    }
}
