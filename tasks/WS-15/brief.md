# WS-15: Landing page + /about + SEO

Lane: D · Deps: WS-01, WS-04 · Sessions: 1

## Scope
Blade landing at `/` (logged-out) per product §2, section order fixed: hero (headline
"Every board is provably fair.", live playable 5×5 with JSON inlined — static HTML first,
hydrates to interactive; solving it triggers the replay + "new one at midnight" card) ·
replay strip (DOM-animated, paused under reduced-motion) · three rule cards + the aha
half-card · provably-fair stamps with `/about` link · social proof (anonymous
solve_complete counter, cached 60s, rank-fallback under 500/day) · footer CTA. `/about`:
the three guarantees in plain language + the math story + press-kit anchor. `/rules`:
prototype rules + embedded walkthrough. SEO checklist (critique #26): robots.txt, noindex
on app routes, apex/www canonical, sitemap.xml incl. past-N dailies, custom 404 (covers
future daily dates), JSON-LD WebSite + VideoGame, OG/twitter meta.

## Inputs
`contracts/{COPY.md,design-tokens.json}`, WS-04 board component (hydration bundle),
WS-19 counter endpoint (stub acceptable behind flag).

## Outputs
Blade views + minimal deferred JS bundle + SEO artifacts.

## Acceptance
- [ ] Lighthouse: perf ≥ 95, SEO ≥ 95 on `/`; LCP ≤ 2.0s mobile emulation
- [ ] HTML ≤ 60KB gz; deferred JS ≤ 90KB gz incl. hero hydration (budgets in CI)
- [ ] Hero board playable with JS disabled? No — but renders complete + non-broken; with JS
      it solves + replays (e2e)
- [ ] Zero third-party requests; system fonts only
- [ ] SEO checklist items each asserted (feature tests where possible)

## Non-goals
No SPA duplication of landing content; no blog; no email capture.
