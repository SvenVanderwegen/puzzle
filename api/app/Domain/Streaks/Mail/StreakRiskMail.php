<?php

declare(strict_types=1);

namespace App\Domain\Streaks\Mail;

use App\Domain\Email\TransactionalMail;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\URL;

/**
 * The streak-protection alert (WS-21): sent at most once per UTC day, only on
 * days the streak would die unsolved at the coming UTC midnight (ADR-0002).
 *
 * Copy comes verbatim from contracts/COPY.md — email.streak.subject and
 * email.streak.body — with {n}/{hours}/{incident} substituted (pinned by
 * CopyPinningTest). Carries RFC 2369/8058 List-Unsubscribe headers plus an
 * in-body one-click unsubscribe link; both hit the same signed route, no
 * login required. Queue retries stop at the deadline itself: a warning that
 * arrives after midnight is misinformation.
 */
final class StreakRiskMail extends TransactionalMail
{
    /**
     * @param  string  $date  the at-risk UTC day, Y-m-d
     * @param  CarbonImmutable  $deadline  the UTC midnight ending that day
     */
    public function __construct(
        public readonly string $userId,
        public readonly int $streakLength,
        public readonly int $hoursLeft,
        public readonly int $incidentNumber,
        public readonly string $date,
        public readonly CarbonImmutable $deadline,
    ) {}

    public function retryUntil(): CarbonImmutable
    {
        return $this->deadline;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: sprintf(
            'Your %d-day streak has %d hours.',
            $this->streakLength,
            $this->hoursLeft,
        ));
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
        return new Content(text: 'mail.streak-risk', with: [
            'incident' => $this->incidentNumber,
            'playUrl' => $this->playUrl(),
            'unsubscribeUrl' => $this->unsubscribeUrl(),
        ]);
    }

    public function playUrl(): string
    {
        /** @var string $frontend */
        $frontend = config('app.frontend_url');

        return rtrim($frontend, '/').'/daily/'.$this->date;
    }

    public function unsubscribeUrl(): string
    {
        // Non-expiring on purpose: a mailed unsubscribe link must keep working.
        return URL::signedRoute('email.streak-alert.unsubscribe', ['userId' => $this->userId]);
    }
}
