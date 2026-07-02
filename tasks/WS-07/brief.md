# WS-07: Game API — daily, solves, streaks + freeze, content import

Lane: B · Deps: WS-06 · Sessions: 2

## Scope
Implement per `contracts/openapi.yaml`: `GET /api/v1/daily/{date}` (metadata + stats
embedded + CDN content_url; origin-served `puzzle` fallback body behind config flag —
critique #17), `POST /api/v1/daily/{date}/start` (writes `puzzle_fetches`),
`POST /api/v1/solves` (Idempotency-Key = client_solve_id; stored response snapshot replayed
on duplicates; `BurnValidator.php` ~30-line BFS re-check vs clues; sha256(shaded) vs
solution hash as corruption assert; `official_ms = min(client_ms, received_at−fetched_at)`
AND `client_ms ≥ replay duration` and ≥ perceptual floor `n_breaks × 250ms`, else
valid-but-`suspect`; endless mode validates `endless_spec` — ADR-0006), `GET /me/solves`,
`GET /me/streak`, `GET /me/rating` (stub until WS-08). Streaks: UTC (ADR-0002), current/
best/last_date, monthly freeze via `streaks:rollover` scheduled command auto-applying
`frozen_dates` (critique #12). Content commands: `content:import {manifest_url}` (verify
Ed25519 + hashes, transactional upsert, `content_imports` row) and `content:rollback
{version}` (critique #32). Submit-on-reconnect only — no `/solves/batch` (ADR-0010).

## Inputs
WS-06, `contracts/openapi.yaml`, `contracts/vectors/burn.v1.jsonl`, WS-05 fixture content.

## Outputs
Controllers + Domain services, `BurnValidator.php`, scheduled commands, feature tests.

## Acceptance
- [ ] PHP validator agrees with the full burn vector file (gate 4)
- [ ] Idempotency: same key twice → identical stored response, one row
- [ ] Partial unique index enforced: second valid daily solve rejected cleanly
- [ ] Clock tests: understated and overstated client_ms both clamped/flagged
- [ ] Streak tests: UTC edge (23:59→00:01), freeze consumption, rollover idempotent
- [ ] Import: bad signature refused; rollback restores prior calendar in one transaction
- [ ] Spectator validates every response against openapi.yaml

## Non-goals
No rating math (WS-08), no import UI, no leaderboards (ADR-0007).
