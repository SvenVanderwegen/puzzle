# WS-10: Daily Burn Order + streak UI + share cards + unfurl

Lane: A · Deps: WS-09, WS-07, WS-05 fixtures · Sessions: 2

## Scope
Daily page: fetch CDN JSON (API fallback on failure), play, submit via api-client
(idempotent retry on reconnect — the single in-progress daily only), win replay → stats
card (time · percentile or rank-fallback · rating delta chip · streak flame +1 · clean-
contain check · share · tomorrow-tease with tier only). Streak flame states incl. freeze
"controlled burn" ring. Share: burn-signature emoji format per product §6 (one emoji per
minute colored by cells-ignited; ⏱ time; ✅ clean only if hint-free; 🔥 streak if ≥2; no
card on fail), client-generated, copy + native share. Laravel Blade route `/daily/{date}`:
OG meta + pipeline PNG for unfurl, immediately-playable board for visitors, past-date
banner, future dates 404, past-7-days playable without streak credit. Pre-cache tomorrow's
board at solve time. UTC countdown copy.

## Inputs
WS-09, WS-07 endpoints, WS-05 fixtures + OG PNGs, `contracts/COPY.md` share strings.

## Outputs
Daily feature, `DailyShareController` (Blade), e2e "fresh user solves today's daily".

## Acceptance
- [ ] Share text contains zero positional/solution information (test renders + asserts)
- [ ] Unfurl: OG tags + image correct for dated URLs (feature test)
- [ ] CDN-down simulation: fallback flag path serves the board; play unaffected
- [ ] Streak UI: freeze ring renders from fixture state; timezone-edge e2e passes
- [ ] Offline replay of a solved daily (PWA) in e2e

## Non-goals
No email (WS-21), no import/merge UI (WS-20), no archive beyond 7 days (entitlement-flagged).
