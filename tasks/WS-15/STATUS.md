# WS-15 STATUS — Landing page + /about + SEO

## Session 2026-07-03 (builder)

## Done
Commit: `8e7d3aa` — WS-15: landing page + /about + /rules + SEO surface (all
work in this worktree branch.)

- **`/` landing (Blade, logged out)** — product §2 sections in the fixed order
  (asserted by test): hero (h1 `app.tagline`, product sub-copy, CTA `/daily`,
  "60-second rules" → `/rules`, live Lookout 5×5) · replay strip (solved 7×7)
  · three rule cards + aha half-card (COPY `rules.1/2/4`, `rules.note.aha`,
  verbatim-checked against contracts/COPY.md) · provably-fair stamps +
  "How we prove it →" `/about` · social proof (server-rendered from
  `daily_stats`, 60s cache, sub-500 rank fallback per COPY
  `daily.rankFallback`, live count behind the WS-19 stub flag) · footer CTA
  ("The fire starts at midnight."). Authenticated GET `/` → redirect `/hub`.
- **Hero board**: vector `gen-0014` (5×5, 4 breaks, max minute 10) committed
  as `api/resources/landing/hero.json`, JSON inlined into the HTML
  (`#bf-hero-board`), rendered as static HTML server-side
  (`partials/static-board`), hydrated by the ONE deferred module into the
  ui-web `<Board>`; solving swaps to `<BurnReplay>` + the "new one at
  midnight" card; invalid full shading shows `play.wrong`.
- **Replay strip**: vector `gen-0049` (7×7, 12 breaks, max minute 18) +
  precomputed burnRamp colors committed as `strip.json`; server renders the
  final burnt frame (complete with JS off); `hero.js` DOM-animates it
  minute-by-minute at the frozen motion-token pacing, only while on screen
  (IntersectionObserver); `prefers-reduced-motion` → paused + step button.
- **Hydration bundle**: `apps/web/src/landing/hero.tsx` entry built by
  `apps/web/vite.landing.config.ts` into ONE committed ES module
  `api/resources/landing/hero.js`, served by `GET /landing/hero.js`
  (immutable + content-hash `?v=`). Logic split into tested modules
  (`boardJson.ts`, `strip.ts`, `HeroApp.tsx`); `landing.test.tsx` covers
  parse/solve/replay-card/wrong-line/strip pacing (130 apps/web tests total).
- **Shared CSS**: `board.css` = ui-web `tokensCssText() + uiWebCss`
  (generated from contracts/design-tokens.json), inlined as critical CSS by
  Blade — static render and hydrated board share one stylesheet; landing
  layout CSS uses `var(--bf-*)` only (no-raw-hex tripwire test).
- **`/about`**: three guarantees in plain language + generation-math story
  condensed from docs/GENRE.md + `#press-kit` anchor. **`/rules`**: the four
  COPY rules + reading-the-numbers notes (verbatim-checked) + `/academy` link.
- **SEO (critique #26)**: robots.txt (disallow /play /me /settings /hub +
  Sitemap line) · apex canonicals on all three pages (config `app.url`) ·
  `sitemap.xml` (SitemapController: /, /about, /rules + dailies from
  `daily_puzzles` within today−7..today; future dates never listed) · custom
  dispatcher-voice `errors/404.blade.php` (noindex; covers future daily
  dates — no web route exists for them) · JSON-LD WebSite + VideoGame ·
  OG/twitter meta with static `/og/landing.png` placeholder path (PNG lands
  with WS-05).
- **Tests**: 35 new Pest feature tests in `api/tests/Feature/Landing/`
  (page/order/copy-conformance/social-proof/caching/SEO/sitemap/404/budgets/
  artifact checkers). Artifact checkers re-derive hero.json/strip.json from
  `contracts/vectors/generate.v1.jsonl` and board.css from
  `contracts/design-tokens.json` — a hand edit or stale regeneration fails
  the PHP suite ("vectors are law" holds for the committed copies).
- **Budgets measured** (`pnpm --filter @burnfront/web budget:landing`, also
  asserted in LandingBudgetTest):
  - HTML `/`: 33,365 B raw → **5.4 KB gz** (budget 60 KB) — /about, /rules
    also asserted under budget.
  - hero.js: 225,095 B raw → **69.65 KB gz** (budget 90 KB, one module).
  - board.css inlined: 6,629 B raw (1.83 KB gz share).
- **Lighthouse** (@lhci/cli 0.15.1, bundled Lighthouse, Chromium 1194,
  mobile emulation + simulated throttling, against `php artisan serve`):
  - Raw artisan serve (no gzip — dev-server limitation): **perf 97 · a11y
    100 · best-practices 100 · SEO 100**, LCP 2.0–2.1s, CLS 0, TBT 0ms.
  - Through a local gzip front (what nginx does in prod), 3 runs: **perf 100
    · a11y 100 · bp 100 · SEO 100**, LCP 0.7s / 0.7s / 1.2s, CLS 0.
  - The CI-owned Lighthouse gate (playbook §5 gate 7) is WS-16/17 harness
    work; numbers above are this session's measured evidence.
- **Browser smoke** (puppeteer-core + repo Chromium, headless): hydration
  mounts 25 gridcells; clicking the 4 solution cells → burn replay →
  CONTAINED stamp → midnight card; strip animates once scrolled into view;
  reduced-motion page shows the step button, stays paused, steps 18→0→1;
  zero third-party requests; zero console/page errors.

## Remaining
- WS-16/17: CI wiring for `budget:landing` + Lighthouse gate; serve `/hub`
  (and the SPA app routes) from the SPA shell; add
  `<meta name="robots" content="noindex">` to the SPA shell
  (apps/web/index.html — out of this brief's paths, documented below).
- WS-05: real OG PNG at `public/og/landing.png` (placeholder path shipped).
- WS-19: flip `landing.live_counter` (see Decisions #5) when the anonymous
  counter feeds `daily_stats`.
- Legal pages `/privacy` `/terms` `/imprint` are product-spec'd footer links
  and currently 404 (separate workstream).
- Blade `/daily/{date}` unfurl shells (ADR-0009 lists them) are NOT in this
  brief; sitemap already emits those URLs — they 404 until that lands.
- Formal e2e journey ("hero solves + replays") belongs to the WS-17
  Playwright harness; an equivalent puppeteer smoke ran green this session.

## Blockers
- None.

## Decisions made (lead: please audit)
1. **Committed build artifacts under `api/resources/landing/`** (hero.js,
   board.css, hero.json, strip.json), generated only by
   `apps/web/scripts/build-landing.mjs`. Rationale: the constraint allows
   only robots.txt in `api/public/`, resources/ is web-served through a
   controller route with immutable caching, and freshness is double-gated
   (`budget:landing` rebuild+byte-diff on the JS side; LandingAssetsTest
   re-derivation from contracts/ on the PHP side — same pattern as WS-09's
   committed strings.gen.ts).
2. **Dedicated vite config** (`apps/web/vite.landing.config.ts`) instead of
   extending the SPA config: the SPA build carries vite-plugin-pwa +
   index.html templating that must not leak into the marketing page, and
   ADR-0009 budgets the landing bundle separately. Output is ONE ES module
   (`inlineDynamicImports`), es2022, no separate engine chunk — the mission
   pinned "ONE deferred module ≤90KB gz TOTAL"; the engine share is a small
   fraction but not separately measurable in a single-file build.
3. **apps/web/package.json edits beyond the entry**: added *workspace* deps
   `@burnfront/engine` + `@burnfront/game-core` (landing entry imports them;
   ui-web already depends on both; no external dependency added —
   DEPENDENCIES.md untouched; pnpm-lock.yaml only gained the importer
   links), two scripts (`build:landing`, `budget:landing`), and a
   vitest-coverage exclusion for the DOM-bootstrap entry `hero.tsx`
   (mirrors the existing `main.tsx` exclusion).
4. **Authenticated `/` redirects to `/hub`** (product §1 names `/hub` as the
   hub alias). Redirecting to `/` again would loop; the SPA shell must own
   `/hub` at deploy time (WS-16/17). robots.txt therefore also disallows
   `/hub` (mission listed /play /me /settings; /hub is the same app surface).
5. **Social-proof stub flag** = `config('landing.live_counter')`, default
   false, no config file added (controllers-only constraint). Tests toggle it
   via `config()->set()`; WS-19 wires a real config entry/env when the
   anonymous counter lands. Below 500 solves (or flag off / no daily) the
   rank fallback renders; rank = solved_count + 1.
6. **Landing copy without COPY.md keys**: the marketing lines specified
   verbatim in product.md §2 (hero sub, stamp sentences, captions, 404 copy,
   page titles/descriptions) render directly in the Blade views; the one
   client-rendered line lives quarantined in
   `apps/web/src/landing/copy.ts` (`landing.hero.solved` — "That's the
   game. A new one drops every midnight →"). Proposal for the lead: an ADR
   adding `landing.*` keys to COPY.md if these should be catalog-managed.
7. **noindex on app routes**: NOT implemented here — the SPA shell
   (apps/web/index.html) is outside this brief's paths. robots.txt disallow
   already blocks crawling; WS-16/17 should add the meta tag to the shell.
8. **robots.txt hardcodes** `https://burnfront.com/sitemap.xml` (a static
   file cannot interpolate APP_URL); the sitemap/canonicals themselves use
   `config('app.url')`.
9. **Sitemap window**: `daily_puzzles.date` in [today−7, today] UTC — the
   playable "late contain" week plus today; future dates excluded (they 404).
10. **404 page pulls the shared critical CSS** via
    `LandingController::boardCss()` (static) because Laravel renders error
    views without a controller round-trip; the view stays standalone.
11. **Cache-Control note**: Symfony normalizes the hero.js header to
    `immutable, max-age=31536000, public` (alphabetical) — test asserts the
    normalized form.
12. **Lighthouse evidence vs gate**: `artisan serve` sends no
    Content-Encoding, which alone costs ~3 perf points and holds LCP at
    ~2.0s; through a gzip front the page scores 100 with LCP 0.7–1.2s. The
    enforced CI gate belongs to WS-16/17 with the real server config.

## Files touched
- `api/app/Http/Controllers/LandingController.php` (new)
- `api/app/Http/Controllers/SitemapController.php` (new)
- `api/routes/web.php` (landing/about/rules/hero.js/sitemap routes)
- `api/public/robots.txt` (rewritten)
- `api/resources/views/landing/{layout,index,about,rules}.blade.php` (new)
- `api/resources/views/landing/partials/{critical-css,static-board,strip-board}.blade.php` (new)
- `api/resources/views/errors/404.blade.php` (new)
- `api/resources/landing/{hero.js,board.css,hero.json,strip.json}` (new, GENERATED — regenerate via `pnpm --filter @burnfront/web build:landing`)
- `api/tests/Feature/Landing/{LandingPageTest,LandingSeoTest,LandingBudgetTest,LandingAssetsTest}.php` (new)
- `apps/web/src/landing/{hero.tsx,HeroApp.tsx,boardJson.ts,strip.ts,copy.ts,boardCss.ts,landing.test.tsx}` (new)
- `apps/web/vite.landing.config.ts`, `apps/web/scripts/build-landing.mjs` (new)
- `apps/web/package.json` (workspace deps + scripts), `apps/web/vitest.config.ts` (coverage exclusion)
- `pnpm-lock.yaml` (workspace importer links only)
- `tasks/WS-15/STATUS.md` (this file)

## Gates (all run this session, all green)
- `php artisan test` — 141 passed (2,370 assertions; 106 baseline + 35 new)
- `vendor/bin/pint --test` — pass · `vendor/bin/phpstan analyse` — level 9, no errors
- `pnpm -r typecheck` / `pnpm -r lint` / `pnpm -r test` (apps/web: 130 tests, 95.6% lines) — pass
- `pnpm format:check` · `pnpm hygiene` · `pnpm --filter @burnfront/web strings:check` — pass
- `pnpm --filter @burnfront/web budget:landing` — fresh, hero.js 69.65 KB gz ≤ 90 KB
- `pnpm --filter @burnfront/web budget:check` — SPA initial 94.48 KB gz ≤ 200 KB (unaffected)

## Resume instructions
1. Postgres 16 on `127.0.0.1:55432` (user postgres, trust), test DB
   `burnfront_test` (recipe: api/tests/schema-conformance.sh header).
2. `cd api && composer install && cp .env.example .env && php artisan
   key:generate` (this environment needs git-source installs — see
   tasks/WS-06/STATUS.md).
3. `pnpm install`, then gates as listed above. To view locally: point .env
   at a migrated dev DB, seed one `daily_puzzles` row + `daily_stats` row,
   `php artisan serve`, open `/`.
4. Regenerating the landing artifacts after a contracts or ui-web change:
   `pnpm --filter @burnfront/web build:landing` and commit the diff under
   `api/resources/landing/`.
5. Next: a separate verifier session executes the brief acceptance checklist
   (budgets → LandingBudgetTest + budget:landing; SEO items → LandingSeoTest;
   no-JS render + solve/replay → static-board partial tests + the puppeteer
   smoke recipe above; Lighthouse numbers in Done).
