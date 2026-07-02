# WS-08: Fire Rating service (Glicko-2)

Lane: B · Deps: WS-07 · Sessions: 2

## Scope
Implement `contracts/RATING.md` exactly: Glicko-2 with boards-as-opponents; outcome from
solved/failed + time vs board par + hint stages (stage 1 free, stage 2 trims gain, stage 3
= unrated); endless at reduced weight (ADR-0006); board ratings seeded from pipeline grade
(NO nightly re-fit — ADR-0010); update as queued job triggered by valid solves;
`rating_events` audit rows (before/after both sides) enabling deterministic recompute;
`GET /me/rating` with sparkline buckets; first-10-solves calibration flag in the response.

## Inputs
`contracts/RATING.md` (formulas + fixtures), WS-07.

## Outputs
`app/Domain/Ratings/*`, queued job, feature + fixture tests.

## Acceptance
- [ ] Reproduces every RATING.md numeric fixture to 4 decimals
- [ ] Suspect/invalid/stage-3 solves never touch ratings (tests)
- [ ] Recompute-from-events equals live state after a simulated month (property test)
- [ ] Queue: solve endpoint stays <50ms p95 in a local bench; rating lands async

## Non-goals
No leaderboards, no anomaly detection (capture only), no UI.
