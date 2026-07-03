<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Streaks\StreakRollover;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled right after UTC midnight (routes/console.php). Judges yesterday
 * for every streak holder (freeze auto-consumption / reset) and emits the
 * failed-daily rating hook (RATING.md §3). Idempotent per day; --date allows
 * re-running a specific rollover after an outage.
 */
class StreaksRollover extends Command
{
    protected $signature = 'streaks:rollover {--date= : Judge as of this UTC date (Y-m-d, defaults to today)}';

    protected $description = 'Apply UTC-midnight streak rollover: consume freezes, reset lapsed streaks, emit failed-daily rating hooks';

    public function handle(StreakRollover $rollover): int
    {
        /** @var string|null $override */
        $override = $this->option('date');
        $asOf = $override ?? CarbonImmutable::now('UTC')->format('Y-m-d');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) !== 1) {
            $this->error('The --date option must be a Y-m-d UTC date.');

            return self::FAILURE;
        }

        $result = $rollover->run($asOf);

        $this->info(sprintf(
            'Rollover for %s: %d judged, %d frozen, %d reset, %d failed-daily events.',
            $asOf,
            $result['judged'],
            $result['frozen'],
            $result['reset'],
            $result['failed_daily_events'],
        ));

        return self::SUCCESS;
    }
}
