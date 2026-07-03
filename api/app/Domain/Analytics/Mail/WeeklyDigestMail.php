<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Mail;

use App\Domain\Analytics\WeeklyDigest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The weekly owner digest (ADR-0008: the only reporting surface). Plain text,
 * incident-report voice. Aggregate numbers only — no anon ids, no user ids,
 * no event rows leave the box.
 *
 * @phpstan-import-type Report from WeeklyDigest
 * @phpstan-import-type Ratio from WeeklyDigest
 */
final class WeeklyDigestMail extends Mailable
{
    /**
     * @param  Report  $report
     */
    public function __construct(public readonly array $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Burnfront weekly digest — week ending %s', $this->report['window_end']),
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.analytics-digest', with: [
            'report' => $this->report,
            'ratio' => self::ratio(...),
            'minutes' => self::minutes(...),
        ]);
    }

    /**
     * "60.0% (3 of 5)"; "n/a (empty cohort)" when nothing qualified.
     *
     * @param  Ratio  $ratio
     */
    public static function ratio(array $ratio): string
    {
        if ($ratio['denominator'] === 0) {
            return 'n/a (empty cohort)';
        }

        return sprintf(
            '%.1f%% (%d of %d)',
            $ratio['numerator'] * 100 / $ratio['denominator'],
            $ratio['numerator'],
            $ratio['denominator'],
        );
    }

    public static function minutes(?float $seconds): string
    {
        if ($seconds === null) {
            return 'n/a (no first solves)';
        }

        return sprintf('%.1f min', $seconds / 60);
    }
}
