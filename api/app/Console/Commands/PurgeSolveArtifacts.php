<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Solves\SolveStore;
use Illuminate\Console\Command;

/**
 * 90-day retention (docs/gdpr.md): replay blobs and ip/ua hashes are nulled;
 * the solve rows themselves are kept.
 */
final class PurgeSolveArtifacts extends Command
{
    public const RETENTION_DAYS = 90;

    protected $signature = 'retention:purge-solve-artifacts';

    protected $description = 'Null replay, ip_hash and ua_hash on solves older than 90 days';

    public function handle(SolveStore $solves): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);

        $count = $solves->purgeExpiredArtifacts($cutoff);

        $this->info(sprintf('Purged artifacts on %d solve(s) received before %s.', $count, $cutoff->toIso8601String()));

        return self::SUCCESS;
    }
}
