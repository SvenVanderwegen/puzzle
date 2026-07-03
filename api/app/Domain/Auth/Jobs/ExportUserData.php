<?php

declare(strict_types=1);

namespace App\Domain\Auth\Jobs;

use App\Domain\Auth\Mail\ExportReadyMail;
use App\Domain\Auth\UserExporter;
use App\Domain\Content\ExportFileStore;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Queued GDPR portability export: writes the JSON file, then emails a signed
 * URL (24-hour expiry; the download route enforces a live session and deletes
 * the file after the first download).
 */
final class ExportUserData implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public const LINK_TTL_HOURS = 24;

    public function __construct(public readonly string $userId) {}

    public function handle(UserExporter $exporter, ExportFileStore $files): void
    {
        /** @var User|null $user */
        $user = User::query()->find($this->userId);

        if ($user === null || $user->email === null || $user->anonymized_at !== null) {
            return;
        }

        $json = json_encode(
            $exporter->export($user),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $file = Str::lower((string) Str::ulid()).'.json';
        $files->store($this->userId, $file, $json);

        $url = URL::temporarySignedRoute(
            'exports.download',
            now()->addHours(self::LINK_TTL_HOURS),
            ['userId' => $this->userId, 'file' => $file],
        );

        Mail::to($user->email)->send(new ExportReadyMail($url));
    }
}
