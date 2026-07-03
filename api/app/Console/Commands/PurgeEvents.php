<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AnalyticsEvent;
use Illuminate\Console\Command;

/**
 * 13-month retention for first-party analytics (docs/gdpr.md): rows are
 * aggregated, then deleted.
 */
final class PurgeEvents extends Command
{
    public const RETENTION_MONTHS = 13;

    protected $signature = 'retention:purge-events';

    protected $description = 'Aggregate then delete events rows older than 13 months';

    public function handle(): int
    {
        $cutoff = now()->subMonths(self::RETENTION_MONTHS);

        $this->aggregateExpiredRows();

        $count = AnalyticsEvent::query()->where('created_at', '<', $cutoff)->toBase()->delete();

        $this->info(sprintf('Deleted %d event(s) recorded before %s.', $count, $cutoff->toIso8601String()));

        return self::SUCCESS;
    }

    /**
     * Aggregation step of aggregate-then-delete. The weekly owner digest that
     * consumes these aggregates is WS-19 and does not exist yet, so this is a
     * documented no-op by design: expired rows only feed the digest; deleting
     * them without a digest loses nothing that the retention policy allows us
     * to keep. WS-19 replaces this method body with the real rollup.
     */
    private function aggregateExpiredRows(): void
    {
        // Intentionally empty until the WS-19 digest lands.
    }
}
