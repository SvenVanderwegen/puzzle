# ADR-0027: WS-10 daily — unfurl OG image, per-page og:image, copy keys

Status: accepted · Date: 2026-07-04 · Deciders: lead agent (WS-10 integration)

## Context

WS-10 shipped the Daily Burn Order: the SPA play surface and the crawler-facing
Blade unfurl `GET /daily/{date}` (ADR-0009: Blade owns server-rendered HTML, the
SPA owns play). Three integration points touch shared surfaces: the unfurl needs
a per-page `og:image` (the shared landing layout hardcoded the landing card),
and the daily surface needs two strings the frozen catalog does not carry.

## Decision

1. **Per-page og:image.** The shared marketing layout
   (`resources/views/landing/layout.blade.php`) now renders
   `@yield('og-image', $baseUrl.'/og/landing.png')` — backward-compatible: the
   landing/about/rules pages keep the static card via the default; the dated
   `/daily/{date}` page overrides it. Landing OG tests are unchanged and green.

2. **Unfurl og:image source.** The dated page's card is the pipeline's
   pre-rendered spoiler-free PNG (grid, spark, clues — never the solution),
   served from the content CDN: the host of `content.cdn_url_template` plus
   `/og/{puzzle_id}.png`. It therefore tracks whatever bucket the boards
   themselves are served from, and resolves once WS-05 content is published
   (owner provisioning, WS-16). Future/unpublished/malformed/impossible dates
   404; the human "boot the SPA onto this board" hand-off is the WS-16/17
   shell-serving seam (mirrors WS-15's `/hub` redirect).

3. **Copy keys.** COPY.md gains, verbatim from the WS-10 quarantine:
   - `share.action` — "Share the burn signature" (## share; the control that
     triggers navigator.share / clipboard — the section had the card text and
     `share.copied` but no button label).
   - `daily.retry` — "Try the dispatch again" (## daily; the reload control when
     the content fetch fails and origin-fallback is not yet flipped).
   Quarantine dissolves back to empty; `StringKey` collapses to `CatalogKey`.

## Consequences

COPY.md amended in-range with this ADR (freeze rule). Consumers updated same
cycle: `strings.gen.ts` regenerated, landing artifact rebuilt (the catalog
change stales it). Note for future workstreams: the landing layout is now the
single place a page's OG image is chosen — set `@section('og-image', …)`.
