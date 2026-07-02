# WS-03: packages/game-core state machines

Lane: A · Deps: WS-02 · Sessions: 1

## Scope
Framework-agnostic play state: marks (break/dot/empty; drag-paint semantics; reverse
cycle), unlimited undo/redo, timer (pause on hidden tab), coach escalation state
(nudge→argument→resolution with rating consequences flags per RATING.md), win detection via
engine validate, replay driver, local persistence adapter (storage interface injected),
solve-record assembly producing the `SolveSubmission` shape (client_ms, hints_used,
undo_count, started_at, replay event log + sha256, Idempotency-Key uuid v7).

## Inputs
`packages/engine`, `contracts/openapi.yaml` (SolveSubmission), `contracts/RATING.md`.

## Outputs
`packages/game-core/src/*` + unit tests.

## Acceptance
- [ ] No DOM/React imports (dependency-cruiser boundary)
- [ ] Serialized solve payload validates against the OpenAPI schema (ajv test)
- [ ] Coverage ≥ 90%; undo/redo property test (random op sequences → invariants hold)
- [ ] Replay log reproduces the final board state when re-applied (round-trip test)

## Non-goals
No rendering, no network (api-client is consumed by apps/web, not here).
