# WS-09: SPA shell + design system + hub

Lane: A · Deps: WS-04 · Sessions: 2

## Scope
`apps/web`: TanStack Router routes `/` (hub when session), `/daily/:date?`, `/play`
(endless), `/academy`, `/academy/:slug`, `/me`, `/settings`, `/login`; tokens→CSS custom
properties build step; night-incident-map chrome; hub lanes per product §3 (Daily lane,
Endless lane, Academy lane, Your record lane, muted Rush footer strip) and the **big Play
button decision table** (first-visit → First Shift; unstarted → daily; in-progress →
resume with elapsed; contained → endless at recommended tier). Keyed-strings module loading
`contracts/COPY.md`-sourced catalog (EN; NL later = file). API access ONLY via
`packages/api-client` generated from openapi.yaml (lint rule bans hand fetch). Route-change
focus management + aria-live announcements. PWA: precache shell; offline daily replay.
Anonymous-first: all state works from localStorage; Guest chip; the three nudge placements
(product §1) — no blocking modals.

## Inputs
WS-04 components, `contracts/{design-tokens.json,COPY.md,openapi.yaml}`.

## Outputs
`apps/web/src/*`, generated `packages/api-client` committed + CI-verified fresh.

## Acceptance
- [ ] Initial JS ≤ 200KB gz (budget in CI); Lighthouse a11y ≥ 95 on shell routes
- [ ] Play-button decision table covered by unit tests (all five states)
- [ ] Zero hand-written fetch paths (lint); zero raw hex (lint)
- [ ] Offline: shell + solved daily replay work with network disabled in e2e
- [ ] Route smoke e2e green

## Non-goals
Feature internals (WS-10..14), sound design, marketing landing (WS-15 — Blade, not SPA).
