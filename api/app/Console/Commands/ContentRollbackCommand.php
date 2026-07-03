<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Content\ContentImportException;
use App\Domain\Content\ContentRollback;
use Illuminate\Console\Command;

/**
 * content:rollback {version} — repoint the daily calendar to a previously
 * imported version, future dates only (T-48h immutability), one transaction.
 */
class ContentRollbackCommand extends Command
{
    protected $signature = 'content:rollback {version : A previously imported calendar content_version}';

    protected $description = 'Roll the future daily calendar back to a previously imported content version';

    public function handle(ContentRollback $rollback): int
    {
        /** @var string $version */
        $version = $this->argument('version');

        try {
            $result = $rollback->rollback($version);
        } catch (ContentImportException $e) {
            $this->error('Rollback refused: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Rolled the mutable calendar back to %s: %d days restored, %d days removed.',
            $result['content_version'],
            $result['restored'],
            $result['removed'],
        ));

        return self::SUCCESS;
    }
}
