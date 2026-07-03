<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RaisesOpsAlerts;
use App\Models\DailyPuzzle;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * ops:content-freshness (WS-18, critique #17) — scheduled 22:00 UTC, i.e.
 * T-2h before tomorrow's daily goes live at 00:00 UTC. A daily_puzzles row
 * for tomorrow exists if and only if a signed calendar covering that date
 * was imported and published (content:import verifies the CDN files first,
 * so the row also implies the board is on the CDN). No row at T-2h means
 * tomorrow's players find nothing — that is an incident, not a warning.
 */
final class OpsContentFreshness extends Command
{
    use RaisesOpsAlerts;

    protected $signature = 'ops:content-freshness';

    protected $description = 'Alert when tomorrow (UTC) has no imported and published daily. Scheduled 22:00 UTC (T-2h).';

    public function handle(): int
    {
        $tomorrow = CarbonImmutable::now('UTC')->addDay()->toDateString();

        if (DailyPuzzle::query()->whereKey($tomorrow)->exists()) {
            $this->components->info("content freshness: the daily for {$tomorrow} is imported and published.");

            return self::SUCCESS;
        }

        return $this->raiseOpsAlert(
            "content freshness: no published daily for {$tomorrow} at T-2h. Import a signed calendar covering {$tomorrow} before 00:00 UTC (RUNBOOK 3.4).",
            ['check' => 'ops:content-freshness', 'date' => $tomorrow],
        );
    }
}
