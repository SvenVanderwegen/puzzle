<?php

declare(strict_types=1);

// /privacy, /terms, /imprint (WS-14, product.md §1): static Blade legal
// pages. The shipped text is an agent draft — these tests pin the routes,
// the review markers (nothing launches with unfilled owner fields
// unnoticed), the GDPR self-service claims matching the real endpoints,
// the Belgian imprint fields, and the zero-third-party rule.

beforeEach(function (): void {
    config()->set('app.url', 'https://burnfront.com');
});

test('the three legal routes render', function (string $path, string $heading): void {
    $this->get($path)
        ->assertOk()
        ->assertSee($heading);
})->with([
    ['/privacy', 'Privacy'],
    ['/terms', 'Terms of service'],
    // assertSee HTML-escapes its argument, matching the rendered &amp;.
    ['/imprint', 'Imprint & contact'],
]);

test('each page carries its canonical URL', function (string $path): void {
    $this->get($path)
        ->assertSee('<link rel="canonical" href="https://burnfront.com'.$path.'">', false);
})->with(['/privacy', '/terms', '/imprint']);

test('drafts carry visible owner-review markers until the owner fills them', function (string $path): void {
    // Acceptance (WS-14): drafts exist with markers for owner-specific
    // fields. When the owner approves final copy, update docs/legal/ and
    // flip these assertions to assertDontSee.
    $this->get($path)
        ->assertSee('[owner review:')
        ->assertSee('Draft pending owner and legal review.');
})->with(['/privacy', '/terms', '/imprint']);

test('the privacy page states the self-service rights the API actually offers', function (): void {
    $this->get('/privacy')
        ->assertSee('Export my data (JSON)')
        ->assertSee('Delete my account')
        ->assertSee('works once and expires after 24 hours')
        ->assertSee('90 days')
        ->assertSee('purged after at most 13 months')
        ->assertSee('gegevensbeschermingsautoriteit.be');
});

test('the imprint lists the Belgian identification fields', function (): void {
    $this->get('/imprint')
        ->assertSee('Operator')
        ->assertSee('Registered address')
        ->assertSee('Email')
        ->assertSee('Company number (BCE/KBO)')
        ->assertSee('VAT');
});

test('the layout footer links to the legal pages from every legal page', function (string $path): void {
    $this->get($path)
        ->assertSee('href="/privacy"', false)
        ->assertSee('href="/terms"', false)
        ->assertSee('href="/imprint"', false);
})->with(['/privacy', '/terms', '/imprint']);

test('zero third-party requests on the legal pages', function (string $path): void {
    $html = $this->get($path)->getContent();

    assert(is_string($html));

    // Nothing fetchable points off-origin (absolute canonical/OG URLs are
    // metadata, not requests) — same rule as the landing (ADR-0008/0009).
    expect(preg_match('/src="https?:\/\//', $html))->toBe(0);
    expect($html)->not->toContain('@font-face')
        ->not->toContain('rel="stylesheet"')
        ->not->toContain('rel="preload"')
        ->not->toContain('<img')
        ->not->toContain('<iframe');
})->with(['/privacy', '/terms', '/imprint']);
