<?php

declare(strict_types=1);

namespace App\Domain\Ratings\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * WS-07 -> WS-08 seam for the failed-daily outcome (RATING.md §3): a daily
 * with a puzzle_fetches start stamp left unsolved at UTC rollover scores
 * s = 0.25, applied by WS-08, one per user per day max. Emitted by
 * streaks:rollover; amnestied or unpublished dailies never emit.
 *
 * Delivery is at-least-once (a rollover re-run re-emits): the WS-08 listener
 * MUST be idempotent per (user_id, date) — e.g. skip when a rating_events row
 * already exists for this user and this daily's board on this date.
 */
final class FailedDailyRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly string $userId,
        public readonly string $date,
        public readonly string $puzzleId,
    ) {}
}
