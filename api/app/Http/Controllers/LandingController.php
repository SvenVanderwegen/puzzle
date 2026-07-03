<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailyPuzzle;
use App\Models\DailyStat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use RuntimeException;

/**
 * The public marketing surface (WS-15, ADR-0009: Blade owns `/`).
 *
 * `/` is the logged-out landing per docs/design/product.md §2 (section order
 * fixed); a live session redirects to the SPA hub. `/about` and `/rules` are
 * the indexable explainer pages. The hero board + replay strip render as
 * static HTML from the committed fixtures under resources/landing/ (vector
 * instances gen-0014/gen-0049, regenerated only by
 * `pnpm --filter @burnfront/web build:landing`; vectors are law) and hydrate
 * through ONE deferred module, hero.js (≤90KB gz, ADR-0009).
 */
final class LandingController extends Controller
{
    /**
     * Below this many solves the counter reads as a rank instead — counts
     * feel small, ranks feel early-adopter (product.md §2.5).
     */
    private const int COUNTER_MIN_SOLVES = 500;

    /** Social-proof numbers are cached this long (product.md §2.5). */
    private const int COUNTER_TTL_SECONDS = 60;

    public function index(Request $request): RedirectResponse|View
    {
        // Same route, server decides (product.md §1): a session cookie means
        // the SPA hub owns the screen. `/hub` is the hub's canonical alias;
        // the SPA shell serves it (deploy wiring: WS-16/17).
        if ($request->user() !== null) {
            return redirect('/hub');
        }

        $hero = $this->fixture('hero.json');
        $strip = $this->fixture('strip.json');

        return view('landing.index', [
            'boardCss' => self::boardCss(),
            'hero' => $hero,
            'strip' => $strip,
            'social' => $this->socialProof(),
            'heroModuleVersion' => $this->heroModuleVersion(),
            'baseUrl' => $this->baseUrl(),
            'jsonLd' => $this->jsonLd(),
        ]);
    }

    /**
     * JSON-LD WebSite + VideoGame for `/` (critique #26). schema.org is a
     * vocabulary identifier, not a request — the page stays zero-third-party.
     */
    private function jsonLd(): string
    {
        $base = $this->baseUrl();
        $description = 'A genuinely new logic puzzle: deduce the firebreaks from the fire\'s arrival times. One provably unique solution daily. No guessing, ever.';

        $graph = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'WebSite',
                    'name' => 'Burnfront',
                    'url' => $base.'/',
                    'description' => $description,
                ],
                [
                    '@type' => 'VideoGame',
                    'name' => 'Burnfront',
                    'url' => $base.'/',
                    'description' => $description,
                    'genre' => 'Logic puzzle',
                    'playMode' => 'SinglePlayer',
                    'applicationCategory' => 'Game',
                    'gamePlatform' => 'Web browser',
                    'operatingSystem' => 'Any',
                ],
            ],
        ];

        $json = json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR);

        return $json;
    }

    public function about(): View
    {
        return view('landing.about', [
            'boardCss' => self::boardCss(),
            'baseUrl' => $this->baseUrl(),
        ]);
    }

    public function rules(): View
    {
        return view('landing.rules', [
            'boardCss' => self::boardCss(),
            'baseUrl' => $this->baseUrl(),
        ]);
    }

    /**
     * Serves the committed hydration bundle (resources/landing/hero.js).
     * Blade references it with a content-hash query string, so the long
     * immutable cache lifetime is safe.
     */
    public function heroModule(): Response
    {
        $path = resource_path('landing/hero.js');

        abort_unless(is_file($path), 404);

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('resources/landing/hero.js is unreadable.');
        }

        return new Response($contents, 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * The inlined critical CSS shared with ui-web: design-token variables +
     * the board/replay component rules, generated from
     * contracts/design-tokens.json by scripts/build-landing.mjs (checked for
     * freshness by budget:landing and LandingAssetsTest). Static so the 404
     * error view can reuse it outside a controller round-trip.
     */
    public static function boardCss(): string
    {
        $css = file_get_contents(resource_path('landing/board.css'));

        if ($css === false) {
            throw new RuntimeException('resources/landing/board.css is missing — run pnpm --filter @burnfront/web build:landing.');
        }

        return $css;
    }

    /** Content hash for the hero.js cache-busting query string. */
    private function heroModuleVersion(): string
    {
        $path = resource_path('landing/hero.js');
        $hash = is_file($path) ? sha1_file($path) : false;

        return $hash === false ? '0' : substr($hash, 0, 12);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        $raw = file_get_contents(resource_path('landing/'.$name));

        if ($raw === false) {
            throw new RuntimeException("resources/landing/{$name} is missing — run pnpm --filter @burnfront/web build:landing.");
        }

        $data = json_decode($raw, true);

        if (! is_array($data)) {
            throw new RuntimeException("resources/landing/{$name} is not a JSON object.");
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Today's anonymous solve counter (daily_stats, WS-07 aggregates), cached
     * 60s. The live count only shows once WS-19's anonymous counter feeds it
     * AND the day has ≥500 solves — until then the rank fallback runs
     * (product.md §2.5). WS-19 flips `landing.live_counter` on.
     *
     * @return array{mode: 'count'|'rank', count: int, rank: int, incident: int|null}
     */
    private function socialProof(): array
    {
        $date = now('UTC')->toDateString();

        /** @var array{mode: 'count'|'rank', count: int, rank: int, incident: int|null} */
        return Cache::remember(
            'landing.social-proof.'.$date,
            self::COUNTER_TTL_SECONDS,
            function () use ($date): array {
                $daily = DailyPuzzle::query()->find($date);
                $stat = DailyStat::query()->find($date);
                $count = $stat->solved_count ?? 0;
                $incident = $daily?->incident_number;

                $live = config('landing.live_counter', false) === true;
                $mode = $live && $incident !== null && $count >= self::COUNTER_MIN_SOLVES
                    ? 'count'
                    : 'rank';

                return [
                    'mode' => $mode,
                    'count' => $count,
                    'rank' => $count + 1,
                    'incident' => $incident,
                ];
            },
        );
    }

    /** Apex origin for canonicals/OG (config app.url; apex is canonical). */
    private function baseUrl(): string
    {
        $url = config('app.url');

        return rtrim(is_string($url) ? $url : '', '/');
    }
}
