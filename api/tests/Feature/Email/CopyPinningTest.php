<?php

declare(strict_types=1);

use App\Domain\Auth\Mail\MagicLinkMail;
use App\Domain\Streaks\Mail\StreakRiskMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * contracts/COPY.md is the only source of email copy (CLAUDE.md rule 7). The
 * api has no keyed-strings module, so the shipped subjects and bodies are
 * pinned here to the contract text: drift on either side fails these tests.
 */
function ws21CopyKey(string $key): string
{
    $md = file_get_contents(dirname(base_path()).'/contracts/COPY.md');
    assert(is_string($md));

    $hit = preg_match('/^- `'.preg_quote($key, '/').'` — (.+)$/mu', $md, $m);
    expect($hit)->toBe(1, "COPY.md key {$key} not found");

    return trim($m[1]);
}

function ws21StreakRiskMail(int $n = 6, int $hours = 5, int $incident = 142): StreakRiskMail
{
    return new StreakRiskMail(
        userId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        streakLength: $n,
        hoursLeft: $hours,
        incidentNumber: $incident,
        date: '2026-07-03',
        deadline: CarbonImmutable::parse('2026-07-04T00:00:00Z'),
    );
}

test('the magic-link subject is email.magic.subject verbatim', function (): void {
    $mail = new MagicLinkMail('token');

    expect($mail->envelope()->subject)->toBe(ws21CopyKey('email.magic.subject'));
});

test('the streak alert subject is email.streak.subject with {n}/{hours} filled', function (): void {
    $expected = strtr(ws21CopyKey('email.streak.subject'), ['{n}' => '6', '{hours}' => '5']);

    expect(ws21StreakRiskMail()->envelope()->subject)->toBe($expected);
});

test('the streak alert body carries email.streak.body with {incident} filled', function (): void {
    $copy = strtr(ws21CopyKey('email.streak.body'), ['{incident}' => '142']);

    // The template interleaves the play and unsubscribe links between the
    // incident line and the terminal dispatch signature (house template
    // convention), so the two copy segments are pinned in order instead of
    // as one contiguous string. Any wording drift still fails here.
    $signature = '— Burnfront dispatch';
    $sentence = Str::before($copy, ' '.$signature);
    $body = (string) preg_replace('/\s+/u', ' ', ws21StreakRiskMail()->render());

    $sentenceAt = mb_strpos($body, $sentence);
    $signatureAt = mb_strpos($body, $signature);

    expect($sentence)->not->toBe($copy) // the signature must still be part of the COPY.md text
        ->and($sentenceAt)->not->toBeFalse()
        ->and($signatureAt)->not->toBeFalse();

    assert(is_int($sentenceAt) && is_int($signatureAt));
    expect($sentenceAt)->toBeLessThan($signatureAt);
});
