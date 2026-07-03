<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RaisesOpsAlerts;
use App\Models\DailyPuzzle;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * ops:content-runway (WS-18) — daily check that enough future dailies are
 * published. Runway counts CONSECUTIVE covered days starting tomorrow (UTC):
 * a gap ends the runway even if later dates are covered, because the first
 * hole is where players hit nothing. Alerts below 21 days; exactly 21 is
 * silent. 21 days keeps one generation-and-review cycle of slack (content
 * batches ship in multi-week calendars, WS-05).
 */
final class OpsContentRunway extends Command
{
    use RaisesOpsAlerts;

    private const int MIN_DAYS = 21;

    protected $signature = 'ops:content-runway
        {--min='.self::MIN_DAYS.' : Alert when fewer consecutive future days than this are covered (drills only; the scheduled run uses the default)}';

    protected $description = 'Alert when fewer than 21 consecutive future days of dailies are published. Scheduled daily.';

    public function handle(): int
    {
        $min = (int) $this->option('min');

        if ($min < 1) {
            $this->components->error('--min must be a positive number of days.');

            return self::INVALID;
        }

        $today = CarbonImmutable::now('UTC');

        /** @var list<string> $dates */
        $dates = DailyPuzzle::query()
            ->where('date', '>', $today->toDateString())
            ->orderBy('date')
            ->pluck('date')
            ->all();

        $runway = 0;

        foreach ($dates as $date) {
            if ($date !== $today->addDays($runway + 1)->toDateString()) {
                break;
            }

            $runway++;
        }

        $coveredThrough = $runway > 0 ? $today->addDays($runway)->toDateString() : 'nothing after today';

        if ($runway >= $min) {
            $this->components->info("content runway: {$runway} consecutive days published (through {$coveredThrough}).");

            return self::SUCCESS;
        }

        return $this->raiseOpsAlert(
            "content runway: {$runway} consecutive future days published (minimum {$min}, covered through {$coveredThrough}). Generate and publish the next calendar (RUNBOOK 3.4).",
            [
                'check' => 'ops:content-runway',
                'runway_days' => $runway,
                'min_days' => $min,
                'covered_through' => $coveredThrough,
            ],
        );
    }
}
