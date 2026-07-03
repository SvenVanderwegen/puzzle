# WS-17: E2E suite + performance budgets

Lane: D · Deps: WS-10..15 merged · Sessions: 1

## Scope
Playwright project with the 5 journeys: (1) land → hero demo solve; (2) register via
mailpit magic link → solve today's daily → streak +1 → share text asserted spoiler-free;
(3) endless: generate → solve → rated submission; (4) academy lesson 1 complete → daily
handoff; (5) coach-assisted solve → unrated flag asserted. Axe scan per route. Lighthouse
CI (`lighthouserc.json`) with BOTH budgets (ADR-0009). Wire as required checks; full suite
< 5 min via sharding.

## Inputs
Merged features on the compose stack (PG + Laravel + built SPA + mailpit).

## Outputs
`e2e/*`, budgets file, CI wiring.

## Acceptance
- [ ] All journeys green on the compose stack in CI
- [ ] Budgets enforced as hard failures (prove with a deliberate regression branch)
- [ ] Suite < 5 min; flake rate 0 over 10 consecutive CI runs

## Non-goals
No cross-browser matrix in v1 (Chromium; WebKit smoke only), no load testing.

## Deferred items assigned at integration (lead)
- Follow-the-/hub-redirect e2e; axe scans; offline e2e (WS-15 integration).
- Cold-browser magic-link loop against mailpit: request → email → consume →
  signed-in hub; covers the ADR-0024 CSRF bootstrap end-to-end (WS-14).
- Delete-account e2e proving server-side anonymization + local guest state
  preserved (WS-14).
