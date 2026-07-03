<?php

declare(strict_types=1);

namespace App\Domain\Auth\Mail;

use App\Domain\Email\TransactionalMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * GDPR erasure receipt (WS-21): queued by UserAnonymizer after the anonymize
 * transaction commits, addressed to the address captured before it was nulled.
 * Carries no personal data beyond the recipient address itself.
 */
final class DeletionConfirmedMail extends TransactionalMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Burnfront account is deleted.');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.deletion-confirmed', with: [
            'privacyUrl' => route('legal.privacy'),
        ]);
    }
}
