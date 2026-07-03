<?php

declare(strict_types=1);

namespace App\Domain\Auth\Mail;

use App\Domain\Email\TransactionalMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * GDPR export delivery: signed URL, 24-hour expiry, single download. Queued
 * with backoff (WS-21); the link outlives the retry window comfortably.
 */
final class ExportReadyMail extends TransactionalMail
{
    public function __construct(public readonly string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Burnfront data export is ready');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.export-ready', with: ['url' => $this->url]);
    }
}
