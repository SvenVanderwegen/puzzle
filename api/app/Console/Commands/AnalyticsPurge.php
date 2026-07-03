<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\AnalyticsRetention;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The daily analytics retention pass (WS-19; docs/gdpr.md): frontend_errors
 * beyond 90 days deleted, events beyond 13 months aggregated into `_rollup.*`
 * rows then deleted. The narrower WS-06 commands (retention:purge-events,
 * retention:purge-frontend-errors) delegate to the same service and remain
 * for targeted runs; this is the scheduled entry point.
 */
final class AnalyticsPurge extends Command
{
    protected $signature = 'analytics:purge';

    protected $description = 'Apply analytics retention: aggregate-then-purge events (13 months), delete frontend errors (90 days)';

    public function handle(AnalyticsRetention $retention): int
    {
        $now = CarbonImmutable::now();

        $events = $retention->aggregateThenPurgeEvents($now);
        $errors = $retention->purgeFrontendErrors($now);

        $this->info(sprintf(
            'Events: wrote %d rollup row(s), deleted %d raw row(s). Frontend errors: deleted %d row(s).',
            $events['rollups'],
            $events['deleted'],
            $errors,
        ));

        return self::SUCCESS;
    }
}
