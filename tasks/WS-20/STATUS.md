# WS-20 STATUS — Anonymous → account merge

## Session 2026-07-03 (builder — both sessions' scope in one pass: API + web flow)

## Done
Commit: single commit on branch `worktree-agent-a3bb1b79ba5a5c5a8` (SHA in `git log -1`;
baseline was 87f2447).

### API — POST /me/import (contracts/openapi.yaml importLocalRecord)
- **Domain service** `api/app/Domain/Import/LocalRecordImporter.php` (+
  `ImportItemStatus` enum mirroring the frozen per-item status enum). One DB
  transaction per import; a `users` row lock serializes concurrent imports per
  user (lock order users → ratings → board_ratings preserved).
- **Per-item pipeline** (order): non-v7 UUID → `invalid` (keeps the reserved
  UUIDv8 failed-daily namespace structurally unclaimable, ADR-0021 parity with
  POST /solves) · known/in-batch `client_solve_id` → `duplicate` · future
  `solved_at` → `date_ineligible` · endless → stored `stats_only` · daily:
  missing date → `invalid`; future/unknown date → `board_unknown` (future
  boards never leak, GET /daily parity); `published_at > solved_at` →
  `date_ineligible`; BurnValidator re-check (+ cell-count) → `invalid` on
  failure; already-contained incident (any id) → `duplicate`; else stored →
  `credited`. Dropped items are never stored. `solution_sha256` corruption
  tripwire mirrored from the live path (ops log only).
- **Stored rows**: `imported=true`, `valid=true`, `suspect=false`,
  `official_ms=NULL` (no verifiable window), `received_at=now`. daily_stats is
  NEVER touched → percentile-ineligible by construction; WS-07's aggregate
  query already filters `imported`.
- **Streak merge**: only same-UTC-day solves count (archive solves never move
  the streak — live-rule parity) **and only dates within the trailing 7-day
  UTC window** — the window is what makes the cap hold ACROSS calls (see the
  live-verification finding below); newest consecutive run only, capped at
  **7 days**; range-union with the live streak when touching; disjoint-newer
  gaps judged with the exact rollover walk (`StreakService::walkGap` — may
  consume a freeze); disjoint-older runs only raise `best_len` (≤ 7); trailing
  gap to yesterday judged immediately so a stale import cannot resurrect a
  dead streak. `credited_days` = imported run days inside the final current
  range not previously covered (0..7).
- **Rating seeding (RATING.md §5)**: credited dailies (s3-free) replay §3
  outcome / §4 weight × **0.5** chronologically by claimed time, via new
  `RatingService::applyImportedSolve` (idempotent per solve_id under the same
  locks as every settlement; `IMPORTED_WEIGHT_FACTOR = 0.5`). Board side
  updates as an ordinary weight-1.0 opponent (bounded to one imported game
  per account per board by `solves_one_valid_daily`). Marked in
  `rating_events` by the halved weight + join to `solves.imported` (the
  frozen schema has no imported column on rating_events).
  `RatingRecompute` selects `solves.imported` and applies the same ×0.5 so
  the audit stream still replays bit-for-bit (test-pinned).
  Endless imports are never rated (no board to re-validate).
- **Controller/route/throttle**: `ImportController` (shape validation only),
  route in the sanctum group, `me-import` limiter 10/hour/user (429
  documented on the operation).
- **Tests**: `api/tests/Feature/Import/ImportTest.php` — 18 tests / 145
  assertions incl. all four brief acceptance boxes at feature level:
  fabricated-100-day attack (7 days, zero daily_stats rows, only weight-0.5
  events from RD 350, zero suspect/live rows) · idempotent re-import (zero new
  rows/events/games) · per-item drop codes · guest-3-dailies → streak 3.
  Plus: F3/F5 fixture exactness for the half-weight seed, recompute
  reproduction, reserved-namespace defense, in-batch dedupe, endless
  stats-only, s3-unrated, archive-no-streak, publish-time and future-time
  lies, no-leak future boards, throttle 429, batch shape 422s, and the
  **split-batch attack** (below). Spectator validates 200/401/429 against
  the frozen contract.

### Live verification (real server, not the test kernel)
Drove the real surface with curl against `php artisan serve` on a fresh
migrated Postgres DB (scripts kept at the scratchpad path in-session; the
repo recipe is `api/tests/e2e-auth.sh` extended with /me/import calls):
csrf-cookie → magic-link (log mailer) → consume → import. Observed: 3-day
guest log → `credited×3, credited_days 3, streak 3`; byte-identical
re-import → `duplicate×3, 0, 3`; /me/streak `current 3`; /me/rating seeded
`1544.78 / RD 280.7 / games 3 / calibrating` with rising sparkline;
/me/solves shows the 3 imported rows (`official_ms null, clean`); no-CSRF
POST → 419; unauthenticated → 401 envelope; empty items → 422 envelope.
**Finding fixed during verification**: the first build allowed a
split-batch attack — after a 3-day merge, a second batch of OLDER dates
unioned backward to `streak 10` (7 more per call, unbounded). Closed by
the trailing 7-day streak-eligibility window (older items stay credited
for stats/rating only); re-drive shows 3 → 7 (window-bounded union) → 7
(further old batches add 0). Pinned by the new feature test.

### Web — merge upload flow + summary (the WS-14 `data-ws="WS-20"` seam)
- **`state/localState.ts`**: new `solveLog` field (`SolveLogEntry` = the
  ImportItem wire data, camelCase), `SOLVE_LOG_LIMIT = 100` client-side cap
  (overflow keeps the NEWEST entries — streak credit only ever comes from the
  newest run; documented), `withLoggedSolve` (dedupes by clientSolveId),
  `withClearedSolveLog`, `appendSolveLog` (direct-storage append matching the
  endless surface's persistence pattern), strict structural sanitizer on load
  (malformed persisted entries are dropped, never uploaded).
- **`account/merge.ts`**: `toImportItems` (wire mapping + cap) and
  `uploadLocalRecord` — POSTs through the typed api-client only; 200 = final
  server ruling → caller clears the log; 429/absence of ruling → null → log
  kept; network/5xx throws to the caller.
- **`routes/LoginPage.tsx`** (ConsumeLanding): after the signed-in marker is
  stamped, the log uploads; on success the log clears and the hub toast is
  `account.merge.summary` with `{solves}` = credited + stats_only and
  `{days}` = the merged `streak.current`; empty log → zero import calls;
  merge failure → calm `auth.consumed` landing, log kept for the next
  sign-in; all other guest data intact byte-for-byte. `data-ws="WS-20"`
  marker removed (seam filled).
- **`chrome/flash.ts` / `AppChrome.tsx`**: the one-shot toast now carries
  optional ICU params (runtime-validated) so the summary interpolates.
- **`endless/EndlessPlay.tsx`**: guest containment appends an endless entry
  (uuidV7 from injected clock+rng, shadingToBits, clamped clientMs/hints)
  via `appendSolveLog`; signed-in solves never log (they submit live).
- **Nudges**: unchanged — all three (guest-note, streak-protect, guest chip)
  already point at /login with catalog copy that matches real merge behavior
  ("your record lives in this browser", "Protect it"); with this WS the
  promise is now real. No new copy keys needed (`account.merge.summary` is
  already in the ADR-0023 catalog) → proposed.ts untouched, COPY.md
  untouched, hero.js untouched.
- **Tests**: `account/merge.test.ts` (6), LoginPage merge describe (4: upload
  +clear+summary toast with interpolated "3 solves merged. 3-day streak
  protected.", empty-log skip, failure keeps log, zero-merged clears but
  lands calm), localState log suite (5), endless guest-log + signed-in-no-log
  assertions.

## Gates (final run, this session — all green)
- `php artisan test` — **240 passed (3380 assertions)** (baseline 222; +18)
- `vendor/bin/pint --test` — passed · `vendor/bin/phpstan analyse` — level 9,
  no errors · Spectator conformance on /me/import responses (in ImportTest)
- `pnpm typecheck` ✓ · `pnpm lint` ✓ · `pnpm test` ✓ — apps/web **325**
  (95.67% lines), game-core 143, ui-web 58, engine 52, api-client 17
- `pnpm format:check` ✓ · `pnpm hygiene` ✓ · `strings:check` ✓
- `budget:check` ✓ — initial JS **112.23 KB gz** ≤ 200 KB; zero third-party
- `budget:landing` ✓ (exit 0 — no catalog change, hero.js untouched)

## Remaining
- Verifier session executes the brief acceptance checklist (builders do not
  self-certify). The full guest→signup e2e through a real browser is WS-17's
  Playwright harness; the feature-level equivalents are listed under Done.
- WS-10 (daily play surface, not yet merged) must call
  `appendSolveLog(storage, {mode:'daily', date, …})` for GUEST daily solves —
  the log API is ready and documented in `state/localState.ts`; without it
  only endless guest history merges.
- Lead: audit the decisions below (esp. #2 board-side updates and #6 the
  streak-copy honesty note).

## Blockers
- None.

## Decisions made (lead: please audit)
1. **Rating seed = full two-sided settlement at user-weight ×0.5** (not a
   user-only update). RATING.md §5 says imported solves "replay §3/§4"; §4's
   weight applies to the user delta only, so the board updates as an ordinary
   weight-1.0 opponent (§2 self-calibration). Exposure is bounded: one
   imported game per account per board (`solves_one_valid_daily`), same as
   live archive play. The "mark" in rating_events = weight 0.5 + join to
   `solves.imported` (frozen schema has no imported column there).
2. **`RatingRecompute` touched (Domain/Ratings, outside the letter of my
   path list)**: without the ×0.5-when-imported rule the WS-08 "recompute
   reproduces live bit-for-bit" invariant would break the moment one import
   lands. WS-08's STATUS explicitly left this seam to WS-20. One select
   column + 4 lines; pinned by a test.
3. **`ratings.games` increments on imported seeds** (settle() unchanged):
   keeps recompute's per-event `games+1` exact. Consequence: 10+ imported
   solves end the "Calibrating n/10" window — accepted; they are real
   Glicko-2 games at half delta.
4. **Streak semantics beyond the brief's letter**: (a) streak credit
   additionally requires solved-on-its-own-UTC-day (mirrors "archive solves
   never move it"; the local client only counts same-day contains anyway);
   (b) **trailing 7-day eligibility window** — only dates ≥ today−6 can be
   streak days; without it the 7-day cap is per-call cosmetics (split
   batches stack backward without bound — found by driving the live
   server, see Live verification). A guest log merged promptly loses
   nothing; stale history merges as stats + rating; (c) disjoint/trailing
   gaps are judged with the existing rollover walk (freezes/amnesty/
   unpublished days behave identically to live play); (d) `date_ineligible`
   items (claimed solve before publish, or from the future) are dropped
   ENTIRELY — a provably impossible timestamp is fabrication, not data;
   (e) imported history can raise `best_len` (still ≤ 7 from any one run).
5. **v7-only `client_solve_id` per item** (contract only says format: uuid):
   parity with POST /solves' Idempotency-Key rule and the second fence for
   the reserved v8 failed-daily namespace; a non-v7 id drops the item
   (`invalid`), never the batch. Schema-level violations (non-uuid, >100
   items) 422 the request like every endpoint.
6. **Toast copy honesty**: `{days}` in `account.merge.summary` is the
   POST-merge `streak.current` (not `credited_days`) — "protected" describes
   the streak that now exists. When the server merged zero items, the plain
   `auth.consumed` toast shows instead of "0 solves merged" (log still
   clears — the ruling is final). NOTE for the lead: a guest with a local
   streak > 7 sees `streak.protect` with the real {n} but merge caps at 7;
   saying so at nudge-time needs a copy key that does not exist — left as a
   product/copy call.
7. **`official_ms = NULL`** on imported rows (no fetch anchor → no verifiable
   window); `client_ms` is kept as claimed data. Claimed `solved_at` is
   consumed by the eligibility/streak logic but not persisted (no schema slot;
   re-imports are duplicates anyway).
8. **Throttle 10/hour/user** for /me/import (contract documents 429 but no
   number; me-export precedent is 3/h — import gets headroom for multi-device
   sign-ins and one retry loop).
9. **Route + limiter files touched** (`routes/api.php`,
   `AppServiceProvider.php`): unavoidable plumbing to expose the controller;
   additive only.
10. **EndlessPlay writes the log via direct-storage `appendSolveLog`**, not
    the runtime store: the endless surface already persists LocalState
    directly (prefs.ts), and a store-snapshot write would clobber
    `creditEndlessSolve` (caught by test). Consume-landing reads via the
    store — exact on any real consume (magic links always open a fresh
    document); the theoretical same-SPA-session staleness is the same seam
    WS-11/14 already accepted.
11. **Log overflow policy**: keep the NEWEST 100 (client cap = contract
    maxItems). Rationale: streak credit only ever derives from the newest
    consecutive run; shedding the oldest entries loses at most old stats.
12. **Re-logging the same clientSolveId replaces the entry** (dedupe in
    `withLoggedSolve`) — defensive; entry ids are minted fresh per contain.

## Files touched
- `api/app/Domain/Import/{LocalRecordImporter,ImportItemStatus}.php` (new)
- `api/app/Http/Controllers/Api/V1/ImportController.php` (new)
- `api/app/Domain/Ratings/RatingService.php` (+`applyImportedSolve`,
  `IMPORTED_WEIGHT_FACTOR`), `api/app/Domain/Ratings/RatingRecompute.php`
  (imported-weight replay rule)
- `api/routes/api.php` (+route), `api/app/Providers/AppServiceProvider.php`
  (+`me-import` limiter)
- `api/tests/Feature/Import/ImportTest.php` (new, 18 tests)
- `apps/web/src/state/localState.ts` (+solveLog) + `localState.test.ts`
- `apps/web/src/account/merge.ts` + `merge.test.ts` (new)
- `apps/web/src/chrome/{flash.ts,AppChrome.tsx}` (toast params)
- `apps/web/src/routes/LoginPage.tsx` + `LoginPage.test.tsx` (the seam)
- `apps/web/src/endless/EndlessPlay.tsx` + `endless.test.tsx` (guest log)
- `tasks/WS-20/STATUS.md` (this file)

## Resume instructions
1. Environment: scratch Postgres 16 on `127.0.0.1:55432` (user postgres,
   trust; DB `burnfront_test`) — recipe in `api/tests/schema-conformance.sh`
   header; a cluster from this session may already run. `pnpm install` at
   root; `cd api && composer install` (git-source installs in this
   environment — tasks/WS-06/STATUS.md decisions 6/10), `cp .env.example
   .env && php artisan key:generate`.
2. Gates: root `pnpm typecheck && pnpm lint && pnpm test && pnpm
   format:check && pnpm hygiene`; `cd apps/web && pnpm strings:check && pnpm
   budget:check`; `cd api && php artisan test && vendor/bin/pint --test &&
   vendor/bin/phpstan analyse`.
3. Nothing in-flight; the branch is committed, all gates green. Next: a
   separate verifier session walks the brief acceptance checklist
   (`tasks/WS-20/brief.md`) against `api/tests/Feature/Import/ImportTest.php`
   and the LoginPage/endless web tests; then lead audit of Decisions #1–#6.
