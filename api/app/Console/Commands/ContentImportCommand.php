<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Content\ContentImporter;
use App\Domain\Content\ContentImportException;
use Illuminate\Console\Command;

/**
 * content:import {manifest} — verify the detached Ed25519 signature and every
 * file sha256, then transactionally upsert puzzles (+ daily calendar for
 * calendar manifests) and record the content_imports audit row. Refusals exit
 * non-zero and write nothing (except the sig_ok=false audit row).
 */
class ContentImportCommand extends Command
{
    protected $signature = 'content:import {manifest : Manifest URL or local path (a .sig sibling must exist)}';

    protected $description = 'Import signed burnfront content (calendar or pack manifest)';

    public function handle(ContentImporter $importer): int
    {
        /** @var string $manifest */
        $manifest = $this->argument('manifest');

        try {
            $result = $importer->import($manifest);
        } catch (ContentImportException $e) {
            $this->error('Import refused: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Imported %s %s: %d puzzles, %d calendar days.',
            $result['kind'],
            $result['content_version'],
            $result['puzzles'],
            $result['days'],
        ));

        return self::SUCCESS;
    }
}
