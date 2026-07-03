<?php

declare(strict_types=1);

use App\Models\DailyStat;

beforeEach(function (): void {
    $this->travelTo('2026-07-10 12:00:00 UTC');
    config()->set('app.url', 'https://burnfront.com');
});

/** The canonical EN value of a COPY.md key (contracts are law, ADR-0011). */
function copyValue(string $key): string
{
    $copy = file_get_contents(dirname(base_path()).'/contracts/COPY.md');

    assert(is_string($copy));

    $ok = preg_match('/^- `'.preg_quote($key, '/').'` — (.+)$/m', $copy, $match);

    assert($ok === 1);

    return trim($match[1]);
}

/**
 * COPY.md value with bold markers stripped and `{braces}` interpolated.
 *
 * @param  array<string, string|int>  $params
 */
function copyText(string $key, array $params = []): string
{
    $text = str_replace('**', '', copyValue($key));

    foreach ($params as $name => $value) {
        $text = str_replace('{'.$name.'}', (string) $value, $text);
    }

    return $text;
}

/** The rendered page as whitespace-normalized plain text. */
function pageText(string $html): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);

    assert(is_string($text));

    return $text;
}

test('GET / renders the landing for logged-out visitors', function (): void {
    $html = $this->get('/')->assertStatus(200)->getContent();

    assert(is_string($html));

    expect(pageText($html))->toContain('Every board is provably fair.')
        ->toContain("Play today's Burn Order")
        ->toContain('60-second rules');
});

test('GET / redirects a live session to the SPA hub (same route, server decides)', function (): void {
    actingAsUser();

    $this->get('/')->assertRedirect('/hub');
});

test('the product §2 section order is fixed: hero, replay, rules, fair, crews, midnight', function (): void {
    $html = $this->get('/')->assertStatus(200)->getContent();

    assert(is_string($html));

    $positions = array_map(
        function (string $id) use ($html): int {
            $at = strpos($html, 'id="'.$id.'"');
            expect($at)->not->toBeFalse("section #{$id} missing");

            assert(is_int($at));

            return $at;
        },
        ['hero', 'replay', 'rules', 'fair', 'crews', 'midnight'],
    );

    $sorted = $positions;
    sort($sorted);
    expect($positions)->toBe($sorted);
});

test('the hero board JSON is inlined verbatim from the committed fixture', function (): void {
    $html = $this->get('/')->getContent();

    assert(is_string($html));

    $ok = preg_match('/<script type="application\/json" id="bf-hero-board">(.+?)<\/script>/s', $html, $match);
    expect($ok)->toBe(1);

    $inline = json_decode($match[1], true);
    $fixture = json_decode((string) file_get_contents(resource_path('landing/hero.json')), true);

    assert(is_array($fixture));

    expect($inline)->toBe($fixture['board']);
});

test('the static hero board renders complete without JS: spark, clues, breaks chip', function (): void {
    $this->get('/')
        ->assertSee('★')
        ->assertSee('data-bf-hero-mount', false)
        ->assertSee('Breaks 0/4')
        // gen-0014's clues: minutes 4, 6, 10 as printed glyphs.
        ->assertSee('bf-cell--clue', false)
        ->assertSee('bf-cell--spark', false);
});

test('exactly one deferred module is referenced, with a content-hash version', function (): void {
    $html = $this->get('/')->getContent();

    assert(is_string($html));

    $count = preg_match_all('/<script type="module" src="\/landing\/hero\.js\?v=[0-9a-f]{12}"><\/script>/', $html);
    expect($count)->toBe(1);
    // No other external scripts of any kind.
    expect(preg_match_all('/<script[^>]*src=/', $html))->toBe(1);
});

test('the replay strip is server-rendered with inline data and a hidden step button', function (): void {
    $html = $this->get('/')->getContent();

    assert(is_string($html));

    expect($html)->toContain('data-bf-strip')
        ->toContain('data-bf-strip-minute')
        ->toContain('data-m="0"')
        ->toContain('--bf-burn-bg:')
        ->toContain('The payoff: watch your answer burn, minute by minute.');
    // The step button ships hidden; hero.js reveals it under reduced motion
    // (COPY.md replay.nextMinute).
    expect(preg_match('/<button[^>]*data-bf-strip-step[^>]*hidden[^>]*>\s*Next minute\s*<\/button>/s', $html))->toBe(1);
});

test('rule cards + the aha half-card reuse COPY.md rules.* verbatim', function (): void {
    $html = $this->get('/')->getContent();

    assert(is_string($html));

    $text = pageText($html);

    expect($text)->toContain(copyText('rules.1', ['n' => 4]))
        ->toContain(copyText('rules.2'))
        ->toContain(copyText('rules.4'))
        ->toContain(copyText('rules.note.aha'));
});

test('the provably-fair stamps link to /about', function (): void {
    $this->get('/')
        ->assertSee('Provably fair.')
        ->assertSee('Unique')
        ->assertSee('Guess-free')
        ->assertSee('Every break earns its place')
        ->assertSee('How we prove it →')
        ->assertSee('href="/about"', false);
});

test('zero third-party requests and system fonts only', function (): void {
    $html = $this->get('/')->getContent();

    assert(is_string($html));

    // Nothing fetchable points off-origin: every src is a relative path
    // (the absolute canonical/OG URLs are metadata, not requests).
    expect(preg_match('/src="https?:\/\//', $html))->toBe(0);
    // No webfonts, no external stylesheets/preloads, no images, no iframes.
    expect($html)->not->toContain('@font-face')
        ->not->toContain('rel="stylesheet"')
        ->not->toContain('rel="preload"')
        ->not->toContain('<img')
        ->not->toContain('<iframe');
});

test('social proof falls back to a rank below 500 solves (COPY.md daily.rankFallback)', function (): void {
    seedDaily('2026-07-10', ['incident_number' => 142]);
    DailyStat::query()->create(['date' => '2026-07-10', 'solved_count' => 214, 'started_count' => 300]);

    $html = $this->get('/')->getContent();

    assert(is_string($html));

    expect(pageText($html))->toContain(copyText('daily.rankFallback', ['rank' => 215]));
});

test('social proof shows the live counter once the flag is on and solves reach 500', function (): void {
    config()->set('landing.live_counter', true);
    seedDaily('2026-07-10', ['incident_number' => 142]);
    DailyStat::query()->create(['date' => '2026-07-10', 'solved_count' => 12408, 'started_count' => 20000]);

    $html = $this->get('/')->getContent();

    assert(is_string($html));

    // COPY.md daily.solvedBy, plural branch, ICU # rendered with separators.
    expect(pageText($html))->toContain('12,408 crews have contained Incident #142.');
});

test('the live counter stays a rank while the WS-19 stub flag is off', function (): void {
    seedDaily('2026-07-10', ['incident_number' => 142]);
    DailyStat::query()->create(['date' => '2026-07-10', 'solved_count' => 12408, 'started_count' => 20000]);

    $html = $this->get('/')->getContent();

    assert(is_string($html));

    expect(pageText($html))->toContain(copyText('daily.rankFallback', ['rank' => 12409]));
});

test('social proof works with no daily published: first rank, no crash', function (): void {
    $html = $this->get('/')->assertStatus(200)->getContent();

    assert(is_string($html));

    expect(pageText($html))->toContain(copyText('daily.rankFallback', ['rank' => 1]));
});

test('the counter is cached for 60 seconds', function (): void {
    seedDaily('2026-07-10', ['incident_number' => 142]);
    DailyStat::query()->create(['date' => '2026-07-10', 'solved_count' => 10, 'started_count' => 12]);

    $first = $this->get('/')->getContent();

    assert(is_string($first));

    expect(pageText($first))->toContain(copyText('daily.rankFallback', ['rank' => 11]));

    DailyStat::query()->whereKey('2026-07-10')->update(['solved_count' => 400]);

    $second = $this->get('/')->getContent();

    assert(is_string($second));

    // Still the cached number, not #401.
    expect(pageText($second))->toContain(copyText('daily.rankFallback', ['rank' => 11]));
});

test('the footer CTA closes the page', function (): void {
    $this->get('/')
        ->assertSee('The fire starts at midnight.')
        ->assertSee('href="/daily"', false);
});
