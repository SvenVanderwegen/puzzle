<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Streaks\StreakRiskAlert;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Hourly streak-protection sweep (WS-21 brief): each run queues alerts for
 * the users whose local clock is in the configured evening hour and whose
 * streak dies unsolved at the coming UTC midnight. All selection logic lives
 * in Domain\Streaks\StreakRiskAlert.
 */
final class NotificationsStreakRisk extends Command
{
    protected $signature = 'notifications:streak-risk';

    protected $description = 'Queue streak-protection alert emails for streaks that die unsolved at the coming UTC midnight';

    public function handle(StreakRiskAlert $alerts): int
    {
        $queued = $alerts->dispatchDue(CarbonImmutable::now('UTC'));

        $this->info(sprintf('%d streak-risk alert(s) queued.', $queued));

        return self::SUCCESS;
    }
}
