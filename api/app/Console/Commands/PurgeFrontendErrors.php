<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FrontendError;
use Illuminate\Console\Command;

/**
 * 90-day retention (docs/gdpr.md): frontend_errors rows are deleted outright.
 */
final class PurgeFrontendErrors extends Command
{
    public const RETENTION_DAYS = 90;

    protected $signature = 'retention:purge-frontend-errors';

    protected $description = 'Delete frontend_errors rows older than 90 days';

    public function handle(): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);

        $count = FrontendError::query()->where('created_at', '<', $cutoff)->toBase()->delete();

        $this->info(sprintf('Deleted %d frontend error(s) filed before %s.', $count, $cutoff->toIso8601String()));

        return self::SUCCESS;
    }
}
