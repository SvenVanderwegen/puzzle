<?php

declare(strict_types=1);

namespace App\Domain\Auth\Mail;

use App\Domain\Auth\MagicLinkService;
use App\Domain\Email\TransactionalMail;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Carries the raw sign-in token (never persisted). Queued (WS-21): the request
 * returns its constant 202 without an SMTP round-trip, and transient ESP
 * failures retry with backoff — but never past the token's own 15-minute TTL
 * (ADR-0003), because a link that arrives dead is worse than none.
 *
 * Subject: contracts/COPY.md email.magic.subject (pinned by CopyPinningTest).
 * Deliberately no List-Unsubscribe headers — this is pure transactional auth
 * mail, sent only on explicit request.
 */
final class MagicLinkMail extends TransactionalMail
{
    /** Captured at issue time so queue retries stop when the token dies. */
    public readonly CarbonImmutable $tokenExpiresAt;

    public function __construct(public readonly string $token)
    {
        $this->tokenExpiresAt = CarbonImmutable::now('UTC')->addMinutes(MagicLinkService::TTL_MINUTES);
    }

    /**
     * Tight backoff (seconds): the link is only useful for 15 minutes.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function retryUntil(): CarbonImmutable
    {
        return $this->tokenExpiresAt;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Burnfront sign-in link');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.magic-link', with: ['url' => $this->url()]);
    }

    public function url(): string
    {
        /** @var string $frontend */
        $frontend = config('app.frontend_url');

        return rtrim($frontend, '/').'/auth/consume?token='.$this->token;
    }
}
