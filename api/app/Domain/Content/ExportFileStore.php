<?php

declare(strict_types=1);

namespace App\Domain\Content;

use Illuminate\Support\Facades\Storage;

/**
 * Storage gatekeeper for GDPR export files (the Content domain is the only code
 * allowed to touch Storage — arch-tested). Local disk in dev/tests; the R2 disk
 * arrives with the content-import work.
 */
final class ExportFileStore
{
    private const BASE = 'exports';

    public function store(string $userId, string $file, string $contents): void
    {
        Storage::disk($this->disk())->put($this->path($userId, $file), $contents);
    }

    public function exists(string $userId, string $file): bool
    {
        return Storage::disk($this->disk())->exists($this->path($userId, $file));
    }

    public function get(string $userId, string $file): ?string
    {
        return Storage::disk($this->disk())->get($this->path($userId, $file));
    }

    public function delete(string $userId, string $file): void
    {
        Storage::disk($this->disk())->delete($this->path($userId, $file));
    }

    private function path(string $userId, string $file): string
    {
        return self::BASE.'/'.$userId.'/'.$file;
    }

    private function disk(): string
    {
        /** @var string $disk */
        $disk = config('filesystems.default');

        return $disk;
    }
}
