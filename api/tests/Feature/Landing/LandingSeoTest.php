<?php

declare(strict_types=1);

beforeEach(function (): void {
    $this->travelTo('2026-07-10 12:00:00 UTC');
    config()->set('app.url', 'https://burnfront.com');
});

// ---- robots.txt (static file; the webserver serves it ahead of Laravel) ----

test('robots.txt disallows the app surface and names the sitemap', function (): void {
    $robots = file_get_contents(public_path('robots.txt'));

    assert(is_string($robots));

    expect($robots)->toContain('User-agent: *')
        ->toContain('Disallow: /play')
        ->toContain('Disallow: /me')
        ->toContain('Disallow: /settings')
        ->toContain('Disallow: /hub')
        ->toContain('Sitemap: https://burnfront.com/sitemap.xml');
    // The public pages stay crawlable: no blanket disallow.
    expect(preg_match('/^Disallow: \/$/m', $robots))->toBe(0);
});

// ---- canonical / meta / JSON-LD --------------------------------------------

test('every public page carries an apex canonical', function (): void {
    $this->get('/')->assertSee('<link rel="canonical" href="https://burnfront.com/">', false);
    $this->get('/about')->assertSee('<link rel="canonical" href="https://burnfront.com/about">', false);
    $this->get('/rules')->assertSee('<link rel="canonical" href="https://burnfront.com/rules">', false);
});

test('the landing carries the product §2 title, description, OG and twitter meta', function (): void {
    $this->get('/')
        ->assertSee('<title>Burnfront — the daily fire-containment logic puzzle</title>', false)
        ->assertSee('name="description" content="A genuinely new logic puzzle', false)
        ->assertSee('property="og:title"', false)
        ->assertSee('property="og:description"', false)
        ->assertSee('property="og:url" content="https://burnfront.com/"', false)
        ->assertSee('property="og:image" content="https://burnfront.com/og/landing.png"', false)
        ->assertSee('name="twitter:card" content="summary_large_image"', false)
        ->assertSee('<html lang="en">', false);
});

test('the landing embeds JSON-LD WebSite + VideoGame', function (): void {
    $html = $this->get('/')->getContent();

    assert(is_string($html));

    $ok = preg_match('/<script type="application\/ld\+json">(.+?)<\/script>/s', $html, $match);
    expect($ok)->toBe(1);

    $data = json_decode($match[1], true);

    assert(is_array($data) && is_array($data['@graph']));

    $types = array_column($data['@graph'], '@type');
    expect($types)->toContain('WebSite')->toContain('VideoGame');
});

// ---- sitemap.xml ------------------------------------------------------------

test('sitemap.xml lists the public pages plus the playable past week of dailies', function (): void {
    // Playable window: today back through today-7. Older and future: excluded.
    foreach (['2026-07-01', '2026-07-02', '2026-07-03', '2026-07-09', '2026-07-10', '2026-07-11'] as $date) {
        seedDaily($date);
    }

    $response = $this->get('/sitemap.xml')
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/xml; charset=utf-8');

    $xml = $response->getContent();

    assert(is_string($xml));

    expect($xml)->toContain('<loc>https://burnfront.com/</loc>')
        ->toContain('<loc>https://burnfront.com/about</loc>')
        ->toContain('<loc>https://burnfront.com/rules</loc>')
        ->toContain('<loc>https://burnfront.com/daily/2026-07-03</loc>')
        ->toContain('<loc>https://burnfront.com/daily/2026-07-09</loc>')
        ->toContain('<loc>https://burnfront.com/daily/2026-07-10</loc>')
        ->not->toContain('2026-07-11') // future dates 404 — never listed
        ->not->toContain('2026-07-02') // outside the playable week
        ->not->toContain('2026-07-01');

    expect($xml)->toContain('<lastmod>');
});

test('sitemap.xml works with an empty calendar', function (): void {
    $this->get('/sitemap.xml')
        ->assertStatus(200)
        ->assertSee('<loc>https://burnfront.com/rules</loc>', false);
});

// ---- custom 404 (covers future daily dates) ---------------------------------

test('unknown paths render the dispatcher 404', function (): void {
    $this->get('/no-such-page')
        ->assertStatus(404)
        ->assertSee('No incident at this address.')
        ->assertSee('name="robots" content="noindex"', false)
        ->assertSee('href="/daily"', false);
});

test('future daily dates 404 onto the same page (no web route exists for them)', function (): void {
    $this->get('/daily/2099-01-01')
        ->assertStatus(404)
        ->assertSee('No incident at this address.')
        ->assertSee('may not have been dispatched yet');
});

// ---- /about and /rules -------------------------------------------------------

test('GET /about tells the provably-fair story with a press-kit anchor', function (): void {
    $html = $this->get('/about')->assertStatus(200)->getContent();

    assert(is_string($html));

    expect($html)->toContain('id="press-kit"')
        ->toContain('The three guarantees')
        ->toContain('Unique')
        ->toContain('Guess-free')
        ->toContain('witnessed')
        ->toContain('How a board is made');
});

test('GET /rules shows the four COPY.md rules and points at the academy', function (): void {
    $html = $this->get('/rules')->assertStatus(200)->getContent();

    assert(is_string($html));

    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = (string) preg_replace('/\s+/u', ' ', $text);

    expect($text)->toContain(copyText('rules.1', ['n' => 'N']))
        ->toContain(copyText('rules.2'))
        ->toContain(copyText('rules.3'))
        ->toContain(copyText('rules.4'))
        ->toContain(copyText('rules.note.distance'))
        ->toContain(copyText('rules.note.aha'))
        ->toContain(copyText('rules.note.wavefront'))
        ->toContain(copyText('rules.note.witnessed'));

    expect($html)->toContain('href="/academy"');
});

// ---- noindex on app routes ---------------------------------------------------
// The SPA shell (apps/web index.html) owns the meta for /play, /me, /settings
// and /hub; robots.txt above already disallows crawling them. Adding the
// noindex tag to the shell is WS-16/17 wiring — documented in STATUS.md, not
// asserted here (out of this brief's paths).
