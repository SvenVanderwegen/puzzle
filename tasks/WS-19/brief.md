# WS-19: First-party analytics + error beacon + weekly digest

Lane: D · Deps: WS-06 · Sessions: 1

## Scope
Per ADR-0008: `events` migration + `POST /api/v1/events` (batched ≤ 25, schema-validated
payloads, rate limit 60/min/session, anonymous session id — persistent localStorage id,
disclosed; no IP stored). Event catalog (product §8): first_seen, tutorial_step{n},
solve_complete{puzzle_id, ms, hint_stages, undo_count, wrong_checks, first},
board_abandoned{ms, marks_placed, last_action_ms}, hint_used{stage}, replay_watched
{fraction}, share_clicked, account_created{from_nudge}. `POST /api/v1/errors` frontend
beacon (sampled, rate-limited, message+stack+route, no PII). Weekly `analytics:digest`
command emailing owner: activation, median time-to-first-solve, D1/D7, daily completion by
weekday, hint stages per solve, day-3 conversion, share rate, top frontend errors. Counter
endpoint for the landing social proof (anonymous solve_complete count, 60s cache).
`docs/gdpr.md`: processor inventory, retention schedule, anon-id posture, Art. 30 record
(one page).

## Inputs
WS-06, `contracts/openapi.yaml` (events/errors paths), PLAN.md KPI list, ADR-0008.

## Outputs
Migrations, controllers, digest command, `docs/gdpr.md`, landing counter endpoint.

## Acceptance
- [ ] Zero external requests confirmed (CSP test unchanged)
- [ ] Abuse tests: over-rate, oversized batch, schema-invalid payloads all rejected cheaply
- [ ] Digest renders with fixture data; D1/D7 math verified against a hand-computed cohort
- [ ] Retention purges (13-month aggregate-then-purge; 90-day error rows) tested
- [ ] `docs/gdpr.md` complete: processors (Hetzner, Cloudflare/DPF, ESP), retention table,
      anonymization semantics, breach note

## Non-goals
No dashboards, no third-party analytics ever, no cookies.
