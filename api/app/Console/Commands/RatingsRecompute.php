<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ratings\RatingRecompute;
use Illuminate\Console\Command;

/**
 * Deterministic recovery path for the rating tables (RATING.md §5): replays
 * the rating_events audit stream in id order and rewrites ratings /
 * board_ratings. With --user, the full stream is still replayed (board chains
 * are shared) but only that user's ratings row is written.
 */
class RatingsRecompute extends Command
{
    protected $signature = 'ratings:recompute {--user= : Write only this user id (the full event stream is still replayed)}';

    protected $description = 'Replay rating_events deterministically into ratings and board_ratings';

    public function handle(RatingRecompute $recompute): int
    {
        /** @var string|null $user */
        $user = $this->option('user');
        $user = $user === '' ? null : $user;

        $result = $recompute->run($user);

        if ($user !== null && $result['users'] === 0) {
            $this->warn(sprintf('No rating events are attributed to user %s; nothing written.', $user));
        }

        $this->info(sprintf(
            'Replayed %d rating event(s): wrote %d user rating(s) and %d board rating(s).',
            $result['events'],
            $result['users'],
            $result['boards'],
        ));

        return self::SUCCESS;
    }
}
