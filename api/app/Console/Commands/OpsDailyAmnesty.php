<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DailyPuzzle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ops:daily-amnesty (WS-18, critique #16) — the executable step of the
 * RUNBOOK pull-a-daily procedure (§7). Sets daily_puzzles.amnesty for one
 * date. Everything downstream already honors the flag (WS-07): the streak
 * walk treats the day as covered without consuming a freeze, streaks:rollover
 * emits no failed-daily rating events for it, the streak-risk mail sweep
 * skips it, and GET /daily/{date} reports amnesty=true to clients.
 */
final class OpsDailyAmnesty extends Command
{
    protected $signature = 'ops:daily-amnesty
        {date : UTC date of the pulled daily (YYYY-MM-DD)}
        {--revoke : Clear the flag instead of setting it}';

    protected $description = 'Set (or revoke) the streak-amnesty flag on a published daily (RUNBOOK pull-a-daily).';

    public function handle(): int
    {
        $date = (string) $this->argument('date');

        if (! $this->isDate($date)) {
            $this->components->error("'{$date}' is not a valid YYYY-MM-DD date.");

            return self::INVALID;
        }

        /** @var DailyPuzzle|null $daily */
        $daily = DailyPuzzle::query()->find($date);

        if ($daily === null) {
            $this->components->error("no published daily for {$date}; nothing to flag.");

            return self::FAILURE;
        }

        $amnesty = ! (bool) $this->option('revoke');
        $daily->amnesty = $amnesty;
        $daily->save();

        Log::channel('ops')->notice(
            $amnesty ? "amnesty set for daily {$date}" : "amnesty revoked for daily {$date}",
            ['date' => $date, 'incident_number' => $daily->incident_number, 'amnesty' => $amnesty],
        );

        $this->components->info($amnesty
            ? "amnesty set for {$date} (incident #{$daily->incident_number}). The date no longer breaks streaks or consumes freezes; GET /daily/{$date} now reports amnesty=true."
            : "amnesty revoked for {$date} (incident #{$daily->incident_number}). The date counts normally again.");

        return self::SUCCESS;
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        [$y, $m, $d] = array_map(intval(...), explode('-', $value));

        return checkdate($m, $d, $y);
    }
}
