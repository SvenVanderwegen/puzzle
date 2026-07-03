# WS-08 STATUS

## Session 2026-07-03 (builder, follow-up pass) — lead re-verification fixes

## Done (follow-up)
- `dcc08e0` — reserved-namespace idempotency keys, GDPR lock race, hardening
  (all four lead items + two small ones):
  1. **D1 flaw (verifier-proven) fixed with a reserved namespace, not
     secrets**: POST /solves now accepts Idempotency-Key = **UUID version 7
     only** (`Str::isUuid($key, 7)`; contract description already said v7 and
     game-core emits v7) — v4, arbitrary uuid-shaped hex (the old-style
     sha-derived shape) and the anchor namespace are rejected 422.
  2. `RatingService::failedDailyKey` now emits **RFC 9562 UUID version 8**
     (version nibble forced to 8, RFC variant 10xx), still deterministically
     derived from (user_id, date) so rollover re-runs stay idempotent; the
     (user_id, client_solve_id) unique backstop is unchanged. The pre-claim
     attack (posting the derived key before rollover to void the s=0.25
     penalty) and the anchor-leak replay are both structurally impossible and
     regression-tested.
  3. **Second fence**: `replayForUser` filters out
     `reject_reason='failed_daily'` rows — an anchor can never replay as a
     player submission even if the version fence were bypassed; ordinary
     invalid solves still replay (tested).
  4. **GDPR millisecond race closed**: the anonymized-user guard moved
     INSIDE the settlement transaction as `lockedActiveUser()` — it takes the
     users row lock, the same lock `UserAnonymizer` takes first, so an
     in-flight anonymization either blocks behind the settlement (its
     ratings-row delete then lands after our commit) or is already visible.
     Lock order everywhere: users → ratings → board_ratings.
  5. Unit dataset renamed: 'F3 endless clean, w = 0.5' → **'F3 half-delta
     (literal weight)'** (endless plumbing coverage lives in
     RatingUpdateTest; both kept).
  6. `tests/bench-solves.sh` generates v7 keys; re-run green
     (n=10: p50 29.0 ms, p95 48.5 ms, rating landed only after drain).

  New tests (`tests/Feature/Solves/IdempotencyKeyNamespaceTest.php`):
  - "the solve endpoint accepts only UUIDv7 idempotency keys" (v4 rejected,
    reserved v8 anchor rejected, old-style raw-hash shape rejected, v7 → 201)
  - "failedDailyKey emits the reserved v8 namespace, deterministically"
  - "a failed-daily anchor cannot pre-empt or leak through the pre-rollover
    claim" (the exact verifier attack; rollover still books the penalty)
  - "replayForUser never surfaces a failed-daily bookkeeping row (second
    fence)" (and ordinary invalid solves still replay)

  Gates re-run on final code: `php artisan test` **139 passed (2475
  assertions)** · `pint --test` passed · `phpstan` level 9 no errors ·
  `bash scripts/hygiene.sh` exit 0.

  Note: contracts/ untouched per the lead's instruction (ADR-0021 carries the
  RATING.md failed-daily-games and db-schema comment erratas at merge).

## Session 2026-07-03 (builder)

## Done
- `126aaac` — WS-08: Fire Rating service — Glicko-2, queued listeners,
  recompute, ADR-0020 riders (the full brief scope; details below).

Delivered, all verified in-session:

- **Glicko-2 engine** (`api/app/Domain/Ratings/Glicko2.php` + `Glicko2State`,
  pure, zero I/O): Glickman steps 1–8, τ 0.5, ε 1e-6, scale 173.7178, full
  precision; §4 weight rule `μ' = μ + w(μ_glicko2 − μ)` with RD'/σ' always
  full. `tests/Unit/Glicko2FixturesTest.php` reproduces **all seven RATING.md
  §6 fixtures F0–F6 exactly** — asserted via `number_format` string equality
  at 4 decimals (ratings/RD) and 6 decimals (σ):
  F0 1464.0507/151.5165/0.059996 · F1 1637.6094/269.4299/0.059999 ·
  F2 1478.8473/269.4299/0.059999 · F3 1568.8047/269.4299/0.059999 ·
  F4 1472.5281/273.7811/0.059999 · F5 1352.3300/187.2294/0.060000 ·
  F6 1621.9090/80.2978/0.059999. Plus an exact-half-delta property for w=0.5.
- **Outcome function** (`Outcome.php`, §3): s = max(0.5, 1 − 0.15·min(s1,1)
  − 0.15·s2); failed daily 0.25; weights daily/pack 1.0, endless 0.5 (§4).
- **Queued listeners** (`Domain/Ratings/Listeners/{ApplyRatableSolve,
  ApplyFailedDaily}.php`, ShouldQueue, wired via `Event::listen` in
  AppServiceProvider): consume the WS-07 seam events; all §3 exclusions
  re-checked defensively (invalid/suspect/imported/s3>0 ⇒ no-op, tested);
  anonymized users no-op (a GDPR-erased ratings row must not resurrect).
  Update pipeline per §5: user side (outcome, weight) → board side same game
  (s_board = 1 − s_user, weight 1.0, RD floor 50) → `rating_events` audit row
  with before/after both sides + score + weight → games/attempts += 1.
- **Board priors** (§2): seeded on first rated solve from
  `base(tier) + 4 × grade_score` (lookout 1000 / crew 1300 / hotshot 1550),
  RD 200. Endless boards priced from `endless_spec.deduction_steps` clamped
  to tier bounds observed in the puzzles table at runtime (Decisions #4).
- **GET /me/rating**: live values, `sparkline` = last 30 post-solve ratings
  from rating_events oldest-first, `calibrating` = games < 10.
  Spectator-validated (`tests/Feature/Ratings/MyRatingTest.php`); the /me
  embed picks the sparkline up too (same Rating schema).
- **`ratings:recompute {--user=}`** (`RatingRecompute` + console command):
  deterministic replay of rating_events in id order; outcome/weight recomputed
  from the joined solve rows, board chains re-seeded from puzzles rows,
  endless priors taken from the recorded `board_before`. Float4-exact
  (Decisions #3). Property test simulates a June of mixed solves (3 users ×
  10 dailies, all modes, hints, failed dailies, duplicate deliveries, skipped
  solves) — recompute equals live to 6 dp on every ratings and board_ratings
  row (in practice bit-identical); `--user` rewrites exactly that user.
- **ADR-0020 riders**: (a) `replay_sha256` now `required_with:replay` in the
  submitSolve validation (+ tests: replay without digest → 422; digest alone
  still 201); (b) `SolveSubmissionService::mapUniqueViolation` race branch
  covered by faking the QueryException (`tests/Feature/Solves/
  Adr0020RidersTest.php`): snapshot replay with 200, one-valid-daily → clean
  422, unrelated exception and phantom duplicate rethrown untouched.
- **Queue acceptance bench** (`api/tests/bench-solves.sh`, mirrors
  e2e-auth.sh): live `artisan serve` + database queue, 25 endless solves —
  **p50 26.2 ms, p95 34.5 ms (< 50 ms budget)**; `/me/rating` stayed games=0
  until `queue:work --stop-when-empty` drained the jobs table, then games=25
  with full sparkline. Afterwards `ratings:recompute` on that same live DB
  reproduced 1668.4124/162.25548/0.059988707/25 **bit-identically**.
- **Gates** (final run this session):
  - `php artisan test` — **135 passed (2442 assertions)**: 106 existing + 29
    new, incl. schema conformance, arch rules, 509-vector gate.
  - `vendor/bin/pint --test` — passed.
  - `vendor/bin/phpstan analyse` — level 9, no errors.
  - `bash scripts/hygiene.sh` (repo root) — exit 0.

## Remaining
- Verifier session executes the brief acceptance checklist (builders do not
  self-certify). All four boxes have direct coverage: fixtures →
  `Glicko2FixturesTest`; never-touch → `RatingUpdateTest` ("invalid, suspect,
  imported and stage-3…"); recompute property → `RecomputeTest`; queue bench →
  `tests/bench-solves.sh` (re-runnable, header has the recipe).
- WS-20 `/me/import` rating seeding (§5: replay §3/§4 at weight × 0.5, marked
  in rating_events) — the endpoint does not exist yet; `Outcome` and
  `RatingService` expose the §3/§4 pieces it will need. Imported solves are
  skipped today (dispatch-filtered and defense-in-depth).
- The task prompt described HEAD as "152 tests"; the actual merged baseline is
  **106** (verified twice before any change). Ledger records reality.

## Blockers
- None.

## Decisions made (lead: please audit)
1. **Failed-daily dedupe + audit storage**: the brief offered "rating_events
   with a null solve_id + unique expression" — impossible against the frozen
   schema (`rating_events.solve_id` is `NOT NULL REFERENCES solves(id)`).
   Chosen design: a **synthetic invalid solves row** as audit anchor
   (`reject_reason='failed_daily'`, empty shaded_bits, client_ms 0,
   valid=false) with a **deterministic uuid-shaped client_solve_id** =
   sha256("burnfront.failed-daily|user|date"), so the existing
   `(user_id, client_solve_id)` unique constraint is a DB-level dedupe
   backstop; the primary idempotency check runs under the user's locked
   ratings row. valid=false keeps it out of daily stats, percentiles, streaks
   and the one-valid-daily index; it is filtered out of `GET /me/solves`
   (SolveStore change, tested) but retained in the GDPR export (it is a
   record about the user).
2. **Solve-event dedupe**: the frozen schema has **no unique index on
   rating_events.solve_id** (adding one would fail schema conformance), so
   idempotency is an exists-check serialized by the user's ratings row lock.
   Lock order is always user → board; every rating writer takes the user lock
   first, so check-then-insert cannot race and chains cannot deadlock.
3. **float32 exactness (recompute = live)**: rating columns are PG `real`;
   the live chain reads f32-quantized state back between games. The replay
   therefore quantizes every post-game state through the same channel — a
   `?::float4` round-trip (`Float4::quantize`) — and live endless priors are
   pre-quantized the same way before use, making the recorded `board_before`
   the exact chain seed. Result: recompute is bit-identical to live (proved
   on a real non-transactional DB), comfortably inside the 6 dp acceptance.
4. **Endless boards**: no board_ratings row is possible (FK to puzzles), so
   the endless board is ephemeral — its post-game state exists only in the
   audit row; nothing else to persist (the board is one-shot by design).
   Tier inference for the §2 formula (spec leaves it open): the **easiest
   tier whose observed grade_score range reaches the claimed
   deduction_steps**, then clamp into that tier's range — conservative, so
   fabricated step counts buy the cheapest plausible board. Bounds come from
   the puzzles table at runtime when a tier has ≥ 10 rows
   (`BoardPriors::MIN_TIER_SAMPLE`); documented sparse fallbacks lookout
   [3,10], crew [10,22], hotshot [22,40], drawn from the
   `contracts/vectors/generate.v1.jsonl` deduction_steps distribution (6–38
   across 3x3..7x7) anchored on the factory's lookout grade 4. Recompute is
   immune to bound drift (it replays from the recorded prior).
5. **Failed daily increments `ratings.games` and board `attempts`**: it is a
   rated Glicko-2 game (calibration counts rated games; boards update "on
   every rated solve"). §5's games+=1 wording extended to the failed-daily
   game deliberately.
6. **`--user` recompute** still replays the full event stream — board chains
   are shared across users and the event rows do not store board RD — but
   writes only that user's ratings row; board rows are never written with
   `--user` (a partial board rewrite would be inconsistent). Unknown user →
   warning + nothing written.
7. **Missing deduction_steps on an endless solve** (cannot occur through
   WS-07 validation, ADR-0020): logged warning + solve left unrated, rather
   than a poison queue job.
8. **Listener wiring** via explicit `Event::listen` in AppServiceProvider —
   `Domain\Ratings\Listeners` is outside Laravel's `app/Listeners`
   auto-discovery path and implicit discovery of domain code was undesirable
   anyway.
9. **Late solves after a failed daily both count**: a user who fails the
   daily (s=0.25 at rollover) and later validly solves the archived board is
   rated again on the solve — RATING.md orders nothing else; the failed-daily
   outcome is final per §3 ("left unsolved at UTC rollover").
10. **bench-solves.sh committed** under `api/tests/` (same conventions as
    e2e-auth.sh) so the verifier can re-run the queue acceptance:
    p50 26.2 ms / p95 34.5 ms / max 63.8 ms over 25 requests, database queue,
    rating landed only after the drain.

## Files touched
- `api/app/Domain/Ratings/{Glicko2,Glicko2State,Outcome,BoardPriors,Float4,RatingService,RatingRecompute}.php` (new)
- `api/app/Domain/Ratings/Listeners/{ApplyRatableSolve,ApplyFailedDaily}.php` (new)
- `api/app/Console/Commands/RatingsRecompute.php` (new)
- `api/app/Domain/Ratings/RatingStore.php` (sparkline + summary shape; docblock)
- `api/app/Domain/Solves/SolveStore.php` (listFor hides failed-daily anchors)
- `api/app/Http/Controllers/Api/V1/SolveController.php` (ADR-0020 rider a: required_with)
- `api/app/Http/Controllers/Api/V1/RatingController.php` (docblock only)
- `api/app/Providers/AppServiceProvider.php` (listener wiring)
- `api/tests/Unit/Glicko2FixturesTest.php`,
  `api/tests/Feature/Ratings/{RatingUpdateTest,FailedDailyRatingTest,MyRatingTest,RecomputeTest}.php`,
  `api/tests/Feature/Solves/Adr0020RidersTest.php`, `api/tests/bench-solves.sh` (new)
- `tasks/WS-08/STATUS.md` (this file)

## Resume instructions
1. Scratch Postgres 16 on `127.0.0.1:55432` (user postgres, trust), databases
   `burnfront_test` (tests) and `burnfront_dev` (bench) — recipe in the
   header of `api/tests/schema-conformance.sh`. A cluster from this session
   may already be running (`pg_isready -h 127.0.0.1 -p 55432`).
2. `cd api && composer install` (this environment needs git-source installs —
   tasks/WS-06/STATUS.md decisions 6/10), `.env` from `.env.example` +
   `php artisan key:generate`; for the bench also set DB → burnfront_dev,
   `QUEUE_CONNECTION=database`, `SESSION_DRIVER=file`, `MAIL_MAILER=log`.
3. Gates: `php artisan test` (139 after the follow-up pass) ·
   `vendor/bin/pint --test` · `vendor/bin/phpstan analyse` ·
   `bash scripts/hygiene.sh` (repo root).
4. Bench: `php artisan migrate:fresh --force && php artisan serve --port=8000`
   then `bash tests/bench-solves.sh`; afterwards `php artisan
   ratings:recompute` must leave the ratings row bit-identical.
5. Next: a separate verifier session executes the brief acceptance checklist
   line by line (coverage map in Remaining above).
