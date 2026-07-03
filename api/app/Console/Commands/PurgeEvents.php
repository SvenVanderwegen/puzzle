<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\AnalyticsRetention;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * 13-month retention for first-party analytics (docs/gdpr.md): rows are
 * aggregated, then deleted. WS-19 filled the WS-06 aggregation seam — the
 * real rollup lives in AnalyticsRetention (shared with analytics:purge, the
 * scheduled entry point); this targeted command remains for manual runs.
 */
final class PurgeEvents extends Command
{
    public const int RETENTION_MONTHS = AnalyticsRetention::EVENT_RETENTION_MONTHS;

    protected $signature = 'retention:purge-events';

    protected $description = 'Aggregate then delete events rows older than 13 months';

    public function handle(AnalyticsRetention $retention): int
    {
        $result = $retention->aggregateThenPurgeEvents(CarbonImmutable::now());

        $this->info(sprintf(
            'Wrote %d rollup row(s); deleted %d raw event(s) past the %d-month boundary.',
            $result['rollups'],
            $result['deleted'],
            self::RETENTION_MONTHS,
        ));

        return self::SUCCESS;
    }
}
