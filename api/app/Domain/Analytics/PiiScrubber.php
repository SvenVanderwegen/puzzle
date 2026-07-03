<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/**
 * No-PII guarantee for the error beacon (ADR-0008, docs/gdpr.md): frontend
 * error strings are scrubbed BEFORE storage. Anything that looks like an
 * email address, a bearer credential, or a token query parameter (magic-link
 * URLs carry `?token=`) is replaced with a fixed placeholder. Scrubbing runs
 * before truncation so a replacement can never push a field past its cap.
 */
final class PiiScrubber
{
    private const string EMAIL_PLACEHOLDER = '[email]';

    private const string TOKEN_PLACEHOLDER = '[token]';

    public function scrub(string $text): string
    {
        $patterns = [
            // Email addresses (message, stack frames, URLs alike).
            '/[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~.-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+/u' => self::EMAIL_PLACEHOLDER,
            // Authorization header material quoted into an error string.
            '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i' => 'Bearer '.self::TOKEN_PLACEHOLDER,
            // token=... in routes/URLs (magic-link consume screens).
            '/([?&;]token=)[^&\s"\']+/i' => '$1'.self::TOKEN_PLACEHOLDER,
        ];

        $scrubbed = preg_replace(array_keys($patterns), array_values($patterns), $text);

        // preg_replace only fails on catastrophic backtracking; these patterns
        // are linear. Refuse to store the unscrubbed original regardless.
        return $scrubbed ?? '[unscrubbable]';
    }
}
