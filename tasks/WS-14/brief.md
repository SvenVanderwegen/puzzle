# WS-14: Accounts UI + GDPR self-service + legal routes

Lane: A · Deps: WS-09, WS-06 · Sessions: 1

## Scope
`/login` (magic-link request + consumed-link landing), `/me` (rating graph, streak, solve
history, distributions), `/settings`: sound toggle, reduced motion, hide-timer, high-
contrast theme, **export data** (request → emailed signed link) and **delete
account** (type-to-confirm; explains anonymization — aggregates survive). Blade legal
routes `/privacy`, `/terms`, `/imprint` rendering owner-approved copy; agent drafts first
versions into `docs/legal/` for owner + lawyer review (critique #29; Belgian imprint
requirements: name, address, email, BCE/VAT if applicable). CSP + zero-third-party assert
test lives here (report-only until WS-22 hardens it).

## Inputs
WS-09 shell, WS-06 endpoints, `contracts/COPY.md`, `docs/gdpr.md` (WS-19 authors; draft ok).

## Outputs
Account screens, legal page templates + drafts, CSP report-only wiring.

## Acceptance
- [ ] Full auth loop e2e (mailpit): request → consume → session → logout
- [ ] Delete e2e asserts anonymization semantics (solves survive user-less; profile gone)
- [ ] Export link: single-use + expiry behavior tested
- [ ] Zero third-party requests on every route (CSP report test)
- [ ] Legal drafts exist with TODO markers for owner-specific fields

## Non-goals
No social auth, no public profiles, no billing UI.
