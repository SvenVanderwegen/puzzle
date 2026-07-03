<?php

declare(strict_types=1);

namespace App\Domain\Streaks\Mail;

use App\Domain\Email\TransactionalMail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\URL;

/**
 * Opt-in confirmation (WS-21): queued when PATCH /me flips streak_alert_opt_in
 * false -> true. The address is already proven owned (magic-link-only auth,
 * ADR-0003); this mail is the consent paper trail and hands the recipient a
 * one-click, no-login off switch in case the toggle was not theirs.
 */
final class StreakAlertSubscribedMail extends TransactionalMail
{
    public function __construct(public readonly string $userId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Streak protection alerts are on.');
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.streak-alert-subscribed', with: [
            'unsubscribeUrl' => $this->unsubscribeUrl(),
        ]);
    }

    public function unsubscribeUrl(): string
    {
        return URL::signedRoute('email.streak-alert.unsubscribe', ['userId' => $this->userId]);
    }
}
