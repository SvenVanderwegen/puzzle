<?php

declare(strict_types=1);

namespace App\Domain\Email;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;

/**
 * Base class for every user-facing mail (WS-21). ShouldQueue means Mail::send
 * and Mail::queue both push to the queue: no SMTP round-trip ever runs inside
 * a request or a solve/streak transaction, and an ESP outage degrades to
 * delayed mail, never to a failed API response. SendQueuedMailable copies
 * $tries and consults backoff()/retryUntil() from the mailable instance.
 */
abstract class TransactionalMail extends Mailable implements ShouldQueue
{
    /** Attempts before the queued send is marked failed. */
    public int $tries = 5;

    /**
     * Exponential backoff between attempts: 1m, 5m, 15m, 1h.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }
}
