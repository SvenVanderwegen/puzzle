<?php

declare(strict_types=1);

/**
 * GET /daily/{date} — the crawler-facing unfurl (WS-10). Asserts the Open
 * Graph card, spoiler-free image source, and the seal on future/unknown dates.
 */
beforeEach(function (): void {
    config()->set('app.url', 'https://burnfront.com'); // apex canonical, as LandingSeoTest
    $this->travelTo('2026-07-10 12:00:00 UTC');
});

test('a published incident renders its unfurl card', function (): void {
    seedDaily('2026-07-10', ['incident_number' => 42]);

    $this->get('/daily/2026-07-10')
        ->assertOk()
        ->assertSee('<title>Incident #42 — Burnfront</title>', false)
        ->assertSee('<link rel="canonical" href="https://burnfront.com/daily/2026-07-10">', false)
        ->assertSee('property="og:title" content="Incident #42 — Burnfront"', false)
        ->assertSee('Lookout 3×3', false)
        ->assertSee('60-second rules', false);
});

test('the og:image is the spoiler-free pipeline card on the content CDN', function (): void {
    $daily = seedDaily('2026-07-10', ['incident_number' => 42]);
    $expected = 'https://content.burnfront.com/og/'.$daily->puzzle_id.'.png';

    $this->get('/daily/2026-07-10')
        ->assertOk()
        ->assertSee('property="og:image" content="'.$expected.'"', false);
});

test('a future incident is sealed until midnight UTC', function (): void {
    seedDaily('2026-07-11'); // tomorrow relative to the frozen clock

    $this->get('/daily/2026-07-11')->assertNotFound();
});

test('an unpublished date 404s even in the past', function (): void {
    // No seedDaily for this date.
    $this->get('/daily/2026-07-04')->assertNotFound();
});

test('a malformed date never reaches the handler', function (): void {
    $this->get('/daily/not-a-date')->assertNotFound();
    $this->get('/daily/2026-7-1')->assertNotFound();
});

test('an impossible but date-shaped value 404s', function (): void {
    $this->get('/daily/2026-13-45')->assertNotFound();
});

test('a past incident carries the "today is live" banner; today does not', function (): void {
    seedDaily('2026-07-08', ['incident_number' => 40]); // two days ago
    seedDaily('2026-07-10', ['incident_number' => 42]); // today

    $this->get('/daily/2026-07-08')
        ->assertOk()
        ->assertSee("This is Wednesday's incident", false);

    $this->get('/daily/2026-07-10')
        ->assertOk()
        ->assertDontSee('incident. Today\'s Burn Order is live', false)
        ->assertSee("Today's Burn Order.", false);
});
