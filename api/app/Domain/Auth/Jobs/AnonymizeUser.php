<?php

declare(strict_types=1);

namespace App\Domain\Auth\Jobs;

use App\Domain\Auth\UserAnonymizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued GDPR erasure (DELETE /me returns 202 immediately). Idempotent: the
 * anonymizer no-ops when anonymized_at is already set.
 */
final class AnonymizeUser implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public readonly string $userId) {}

    public function handle(UserAnonymizer $anonymizer): void
    {
        $anonymizer->anonymize($this->userId);
    }
}
