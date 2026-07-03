<?php

declare(strict_types=1);

// ADR-0009 landing budgets, asserted where the artifacts live:
//   HTML ≤ 60KB gz (including critical CSS + the inlined hero puzzle)
//   deferred JS ≤ 90KB gz TOTAL (the one module, resources/landing/hero.js)
// scripts/build-landing.mjs (`pnpm budget:landing`) measures the same numbers
// at build time; this keeps the gate in the PHP suite too.

const HTML_BUDGET_BYTES = 60 * 1024;
const JS_BUDGET_BYTES = 90 * 1024;

beforeEach(function (): void {
    $this->travelTo('2026-07-10 12:00:00 UTC');
});

test('landing HTML is at most 60KB gzipped', function (): void {
    seedDaily('2026-07-10', ['incident_number' => 142]);

    $html = $this->get('/')->assertStatus(200)->getContent();

    assert(is_string($html));

    $gz = gzencode($html, 9);

    assert(is_string($gz));

    expect(strlen($gz))->toBeLessThanOrEqual(HTML_BUDGET_BYTES);
});

test('the deferred hydration module is at most 90KB gzipped', function (): void {
    $js = file_get_contents(resource_path('landing/hero.js'));

    assert(is_string($js));

    $gz = gzencode($js, 9);

    assert(is_string($gz));

    expect(strlen($gz))->toBeLessThanOrEqual(JS_BUDGET_BYTES);
});

test('/landing/hero.js serves the committed module with immutable caching', function (): void {
    $expected = file_get_contents(resource_path('landing/hero.js'));

    $response = $this->get('/landing/hero.js')
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'text/javascript; charset=utf-8')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');

    expect($response->getContent())->toBe($expected);
});

test('about and rules stay inside the HTML budget too', function (): void {
    foreach (['/about', '/rules'] as $path) {
        $html = $this->get($path)->assertStatus(200)->getContent();

        assert(is_string($html));

        $gz = gzencode($html, 9);

        assert(is_string($gz));

        expect(strlen($gz))->toBeLessThanOrEqual(HTML_BUDGET_BYTES);
    }
});
