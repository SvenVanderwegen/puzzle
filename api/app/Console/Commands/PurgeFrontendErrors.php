<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\AnalyticsRetention;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * 90-day retention (docs/gdpr.md): frontend_errors rows are deleted outright.
 * Delegates to AnalyticsRetention (shared with analytics:purge, the scheduled
 * entry point); this targeted command remains for manual runs.
 */
final class PurgeFrontendErrors extends Command
{
    public const int RETENTION_DAYS = AnalyticsRetention::ERROR_RETENTION_DAYS;

    protected $signature = 'retention:purge-frontend-errors';

    protected $description = 'Delete frontend_errors rows older than 90 days';

    public function handle(AnalyticsRetention $retention): int
    {
        $count = $retention->purgeFrontendErrors(CarbonImmutable::now());

        $this->info(sprintf('Deleted %d frontend error(s) past the %d-day boundary.', $count, self::RETENTION_DAYS));

        return self::SUCCESS;
    }
}
