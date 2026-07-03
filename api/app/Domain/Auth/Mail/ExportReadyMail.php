<?php

declare(strict_types=1);

namespace App\Domain\Auth\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * GDPR export delivery: signed URL, 24-hour expiry, single download.
 */
final class ExportReadyMail extends Mailable
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
