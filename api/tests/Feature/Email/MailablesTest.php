<?php

declare(strict_types=1);

use App\Domain\Auth\Mail\DeletionConfirmedMail;
use App\Domain\Auth\Mail\ExportReadyMail;
use App\Domain\Auth\Mail\MagicLinkMail;
use App\Domain\Email\TransactionalMail;
use App\Domain\Streaks\Mail\StreakAlertSubscribedMail;
use App\Domain\Streaks\Mail\StreakRiskMail;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;

function ws21RiskFixture(): StreakRiskMail
{
    return new StreakRiskMail(
        userId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        streakLength: 5,
        hoursLeft: 4,
        incidentNumber: 142,
        date: '2026-07-03',
        deadline: CarbonImmutable::parse('2026-07-04T00:00:00Z'),
    );
}

test('every user-facing mailable is queued with exponential backoff', function (): void {
    $mailables = [
        new MagicLinkMail('token'),
        new ExportReadyMail('https://example.test/exports/x'),
        new DeletionConfirmedMail,
        new StreakAlertSubscribedMail('01ARZ3NDEKTSV4RRFFQ69G5FAV'),
        ws21RiskFixture(),
    ];

    foreach ($mailables as $mail) {
        expect($mail)->toBeInstanceOf(TransactionalMail::class)
            ->and($mail)->toBeInstanceOf(ShouldQueue::class)
            ->and($mail->tries)->toBe(5);

        $previous = 0;
        foreach ($mail->backoff() as $seconds) {
            expect($seconds)->toBeGreaterThan($previous);
            $previous = $seconds;
        }
    }
});

test('magic-link retries stop when the token itself dies', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-03T12:00:00Z'));

    $mail = new MagicLinkMail('token');

    expect($mail->retryUntil()->toISOString())->toBe('2026-07-03T12:15:00.000000Z')
        ->and($mail->backoff())->toBe([10, 30, 60, 120]);
});

test('streak-risk retries stop at the deadline it warns about', function (): void {
    expect(ws21RiskFixture()->retryUntil()->toISOString())->toBe('2026-07-04T00:00:00.000000Z');
});

test('the streak mails carry one-click List-Unsubscribe headers; the magic link does not', function (): void {
    foreach ([ws21RiskFixture(), new StreakAlertSubscribedMail('01ARZ3NDEKTSV4RRFFQ69G5FAV')] as $mail) {
        $headers = $mail->headers();

        expect($headers->text['List-Unsubscribe'])->toBe('<'.$mail->unsubscribeUrl().'>')
            ->and($headers->text['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click');
    }

    // Pure transactional auth mail carries no unsubscribe surface at all.
    expect(method_exists(new MagicLinkMail('token'), 'headers'))->toBeFalse();
});

test('magic-link body snapshot', function (): void {
    config()->set('app.frontend_url', 'https://burnfront.com');

    $expected = <<<'TEXT'
    Your sign-in link is ready.

    https://burnfront.com/auth/consume?token=deadbeef

    The link works once and expires in 15 minutes. If you did not request it, no
    action is needed.

    — Burnfront dispatch
    TEXT;

    expect((new MagicLinkMail('deadbeef'))->render())->toBe($expected."\n");
});

test('export-ready body snapshot', function (): void {
    $expected = <<<'TEXT'
    Your data export is ready.

    https://example.test/exports/x

    The link works once and expires in 24 hours. You must be signed in to download.

    — Burnfront dispatch
    TEXT;

    expect((new ExportReadyMail('https://example.test/exports/x'))->render())->toBe($expected."\n");
});

test('streak-risk body snapshot', function (): void {
    config()->set('app.frontend_url', 'https://burnfront.com');
    $mail = ws21RiskFixture();

    $expected = <<<TEXT
    Incident #142 is still burning. Your streak ends at midnight UTC.

    Contain it: https://burnfront.com/daily/2026-07-03

    One click turns these alerts off: {$mail->unsubscribeUrl()}

    — Burnfront dispatch
    TEXT;

    expect($mail->render())->toBe($expected."\n");
});

test('streak-alert-subscribed body snapshot', function (): void {
    $mail = new StreakAlertSubscribedMail('01ARZ3NDEKTSV4RRFFQ69G5FAV');

    $expected = <<<TEXT
    Streak protection alerts are on for this account. One email, only on days
    your streak would end unsolved, sent near 20:00 your local time. The day
    itself still ends at midnight UTC.

    One click turns them off: {$mail->unsubscribeUrl()}

    — Burnfront dispatch
    TEXT;

    expect($mail->render())->toBe($expected."\n");
});

test('deletion-confirmed body snapshot', function (): void {
    $privacy = route('legal.privacy');

    $expected = <<<TEXT
    Deletion confirmed. This account and its identifying data are erased.
    Anonymous aggregate statistics survive without a link to you; the details
    are in the privacy policy: {$privacy}

    No further email will be sent to this address.

    — Burnfront dispatch
    TEXT;

    expect((new DeletionConfirmedMail)->render())->toBe($expected."\n");
});

test('every mail template is text-first: no HTML part at all', function (): void {
    config()->set('app.frontend_url', 'https://burnfront.com');

    $mailables = [
        new MagicLinkMail('token'),
        new ExportReadyMail('https://example.test/exports/x'),
        new DeletionConfirmedMail,
        new StreakAlertSubscribedMail('01ARZ3NDEKTSV4RRFFQ69G5FAV'),
        ws21RiskFixture(),
    ];

    foreach ($mailables as $mail) {
        $content = $mail->content();

        expect($content->text)->not->toBeNull()
            ->and($content->view)->toBeNull()
            ->and($content->html)->toBeNull()
            ->and($mail->render())->not->toContain('<html');
    }
});
