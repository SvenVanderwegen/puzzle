<?php

declare(strict_types=1);

namespace App\Domain\Auth\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Carries the raw sign-in token (never persisted). Subject key: email.magic.subject
 * (contracts/COPY.md). Production templates and the ESP are WS-21.
 */
final class MagicLinkMail extends Mailable
{
    public function __construct(public readonly string $token) {}

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
