# WS-07 STATUS

## Session 2026-07-03 (builder)

## Done
- `354cf26` — WS-07: game API — daily, solves, streaks + freeze, content import
  (the full brief scope; details below).

Delivered, all verified in-session:

- **BurnValidator** (`api/app/Domain/Solves/BurnValidator.php` + `Board`,
  `BurnVerdict`, `BurnVerdictReason`): pure-PHP BFS mirroring
  `reference/firebreak.py` `_burn_verdict`/`_flat_times`. Frozen check order
  (spark_shaded → clue_shaded → wrong_break_count → unreachable_cell →
  clue_time_mismatch → ok, row-major). Pest gate
  `tests/Unit/BurnValidatorVectorsTest.php` runs **all 509** vectors from
  `contracts/vectors/burn.v1.jsonl` and asserts valid + reason + the full
  `times` array per case. **509/509 pass.**
- **GET /api/v1/daily/{date}** (public): metadata + embedded `stats`
  (solved_count, p50_ms from `daily_stats`), `content_url` from the
  `CONTENT_CDN_URL_TEMPLATE` config template, `amnesty` flag; `puzzle` board
  embedded only when `CONTENT_ORIGIN_FALLBACK` is on (critique #17); 404 for
  unpublished, future and impossible dates.
- **POST /api/v1/daily/{date}/start** (auth): idempotent first-stamp-wins
  insert into `puzzle_fetches`; first stamp also increments
  `daily_stats.started_count`; 404 rules identical to GET.
- **POST /api/v1/solves** (auth, throttle 30/min/user):
  - Idempotency-Key header (client UUID) = `client_solve_id`; duplicates replay
    the stored `response_snapshot` with **200**, one row ever (unique-violation
    race path covered too).
  - Server-side validity via BurnValidator; invalid shadings are **stored and
    returned 201 with valid=false + reason** (only malformed requests 422).
  - Corruption tripwire: BFS-valid shading whose sha256 ≠
    `puzzles.solution_sha256` → `Log::critical` (never user-visible).
  - `official_ms = min(client_ms, received_at − fetched_at)`; valid-but-suspect
    on: overstated client_ms (clamped), missing daily fetch anchor, official <
    `n_breaks × 250` ms floor, official < replay duration (max t_ms).
  - Replay integrity per ADR-0012: base64 → gunzip → sha256 over UNCOMPRESSED
    JSON vs `replay_sha256`; mismatch/undecodable → 422, nothing stored.
  - Endless (ADR-0006): `endless_spec` shape-validated (`Board::fromArray`,
    additionalProperties false, bounds) then BFS-validated; stored in
    `solves.endless_spec` as board + `deduction_steps`; `puzzle_id` prohibited;
    `deduction_steps` required.
  - Daily aggregates updated transactionally (`daily_stats` row locked,
    solved_count, p50 via `percentile_cont`) for valid+non-suspect+non-imported
    solves; response `daily.rank`/`percentile`/`solved_count` (null rank and
    percentile for suspect = percentile-ineligible).
  - Second valid daily solve (fresh key) → clean 422 (pre-check + partial
    unique index `solves_one_valid_daily` catch).
- **Streaks** (`api/app/Domain/Streaks/StreakService.php` + `StreakRollover`):
  UTC day math (ADR-0002). Credit on first valid solve of the **current UTC
  day's** daily (archive solves never credit). Coverage walk: frozen_dates ∪
  amnestied ∪ unpublished days pass; otherwise one freeze per calendar month is
  auto-consumed (`freeze_available_at` → first of next month, day appended to
  `frozen_dates`); anything else resets. The same walk runs in
  `streaks:rollover` (scheduled 00:05 UTC) AND defensively at credit time, so a
  missed scheduler day cannot corrupt state; rollover re-runs are no-ops
  (judged days are in frozen_dates). `safe_until` = exact UTC instant the
  streak dies, simulating freeze earn/consume across month boundaries; in
  `/me`, `/me/streak` and solve responses.
- **WS-08 seam (rollover rating hook)** — see Decisions #1.
- **content:import {manifest_url_or_path}**: detached Ed25519 signature over
  the exact manifest bytes (sodium; public key path from config) → per-file
  sha256 → doc validation (schema const, id pattern, certificates
  unique/witnessed, board shape) → one transaction: upsert `puzzles`, upsert
  `daily_puzzles` (dates ascending, `incident_number` = max+1 for new dates,
  existing dates keep theirs, repointing blocked inside T-48h) →
  `content_imports` row. Bad signature → exit 1 + audit row `sig_ok=false`,
  nothing else written; bad file hash → exit 1, nothing written. Calendar AND
  pack manifests supported. Verified calendar manifests archived to local
  storage.
- **content:rollback {version}**: restores the archived manifest's calendar for
  **future, still-mutable (T-48h) dates only**, in one transaction
  (delete-then-insert so `puzzle_id` uniqueness can't collide; incident
  numbers preserved; v2-only days removed; immutable days untouched).
- **GET /me/solves** (cursor pagination, newest-first, daily date +
  incident_number + clean flag), **GET /me/streak**, **GET /me/rating**
  (stored-values stub, defaults games=0 calibrating=true — WS-08 fills math),
  **GET /api/v1/health** (`ok` + `tomorrow_published`).
- **Gates** (final run this session):
  - `php artisan test` — **106 passed (2193 assertions)**: WS-06's 46 + 60 new,
    incl. the 509-vector gate, Spectator on every documented response (two
    documented exceptions, Decisions #2), arch rules, schema conformance.
  - `vendor/bin/pint --test` — passed.
  - `vendor/bin/phpstan analyse` — level 9, no errors.
  - `bash scripts/hygiene.sh` (repo root) — exit 0.
  - Live smoke outside the harness: scratch DB → `migrate` →
    `content:import` of a sodium-signed fixture calendar (exit 0, incident #1)
    → `streaks:rollover` (exit 0) → `artisan serve` → curl: `/health` ok,
    `/daily/{today}` 200 with correct shape, future date 404, `/solves`
    unauth 401.

## Remaining
- Verifier session must execute the brief acceptance checklist (builders do not
  self-certify).
- WS-08: consume the two seam events (Decisions #1) and replace the
  `/me/rating` stub math.
- WS-05: align the pipeline's manifest signing with the formats accepted here
  (raw/hex/base64 Ed25519 key + detached `.sig` over exact manifest bytes,
  Decisions #5) and the `content_url` template convention (Decisions #6).
- `content:import` over HTTP(S) uses `file_get_contents` (no allowlisted HTTP
  client dependency); fine for R2/CDN pulls on-box, revisit if redirects/auth
  are ever needed.

## Blockers
- None.

## Decisions made (lead: please audit)
1. **WS-08 seam = two plain events** in `app/Domain/Ratings/Events/`:
   `RatableSolveRecorded {solveId}` (dispatched post-commit for valid,
   non-suspect, non-imported, no-stage-3 solves; `rating_pending=true` in the
   response iff dispatched) and `FailedDailyRecorded {userId, date, puzzleId}`
   (dispatched by `streaks:rollover` for users with a `puzzle_fetches` stamp
   and no valid solve of yesterday's board; amnestied/unpublished dailies and
   anonymized users never emit). Rationale: only `Domain\Ratings` may write
   rating tables (arch rule), so WS-07 announces and WS-08 registers queued
   listeners. Delivery is at-least-once (a rollover re-run re-emits): the
   listeners MUST be idempotent — dedupe keys documented in the event
   docblocks (`rating_events.solve_id`; (user_id, date)).
2. **Spectator cannot compile `prefixItems`** (the contract's `Position`
   schema; OpenAPI 3.1) — its opis/cebe engine throws
   "prefixItems[0] must contain a valid json schema", surfacing as a 500 on
   any request/response that carries that subtree. Affects exactly two
   variants: the origin-fallback `puzzle` embed of GET /daily and endless-mode
   POST /solves *requests*. Those tests disable Spectator and assert structure
   manually (`assertExactJsonStructure`); every other response keeps
   `assertValidResponse`. The contract was NOT changed. WS-16 may want a
   tooling note; a Spectator upgrade/patch would remove the exception.
3. **Streak semantics pinned** (brief said "increment on first valid daily
   solve of the day"): credit only when the solve's daily date == current UTC
   date — a reconnect submission landing after midnight validates and counts
   for stats but not the streak (rollover already judged that day; the freeze
   walk is authoritative). Frozen days preserve chain length, they do not add
   to it. Unpublished days never break streaks (content outage ≠ player
   fault). Freeze model with the frozen schema's two columns: available iff
   `freeze_available_at` is NULL or ≤ the missed day; consuming sets it to the
   first day of the following month ("one earned per calendar month").
4. **Suspect flags** beyond the contract's two clamps: a daily solve with no
   `puzzle_fetches` anchor is valid-but-suspect (window unverifiable — the
   real client always stamps `/start` first); suspect solves still credit the
   streak (RATING.md only excludes them from rating/percentiles).
5. **Signature/key formats** (WS-05 unmerged): `.sig` = sibling file
   `<manifest>.sig`, detached Ed25519 over exact manifest bytes per
   calendar.v1.json's $comment; key and signature accepted as raw bytes, hex,
   or base64 (auto-detected by length). Test fixtures are constructed
   in-test with sodium keypairs; fixture boards reuse burn-vector burn-0001 so
   validator behavior in tests is vector-anchored (`PuzzleFactory::BOARD`).
6. **content_url derivation**: no URL column exists in the frozen schema, so
   it is templated — `CONTENT_CDN_URL_TEMPLATE` (default
   `https://content.burnfront.com/puzzles/{id}.json`, `{version}` also
   substitutable). WS-05/WS-18 own the real CDN layout; changing the layout is
   a config change, not code.
7. **Rollback source of truth**: `content_imports` stores only the manifest
   hash, so import archives the verified manifest bytes to local storage
   (`content/manifests/{version}.json`) and `content:rollback` reads from
   there; rolling back to a never-imported version is refused. T-48h
   immutability enforced on BOTH import repointing and rollback (mutable iff
   the date's UTC start is ≥ 48h away).
8. **Pack manifests** have no `content_version`; audit rows and puzzle rows
   record the pseudo-version `pack:{id}`.
9. **Failed-hash refusals write no audit row** (only signature failures do,
   `sig_ok=false`): the schema has no column to distinguish hash failure from
   success, and a valid-signature/bad-file state is an ops incident surfaced
   by the non-zero exit.
10. **Percentile formula**: rank = 1 + count(eligible with smaller
    official_ms); percentile = floor(100 × (n − rank) / n) — "faster than X%
    of today's crews" incl. self in n; first solver = rank 1, percentile 0
    (UI rank-fallback copy owns small fields, decisions.md).
11. **daily_stats.histogram left NULL** — no consumer in scope (WS-19 owns
    aggregation); solved_count/started_count/p50_ms are maintained.
12. **streaks.frozen_dates cast**: added `App\Models\Casts\PgDateArray`
    (Postgres date[] ↔ list<Y-m-d strings>) to the WS-06 Streak model; the
    GDPR export now emits it as a JSON list (was a raw `{...}` literal).
13. **Endless `deduction_steps` required** for endless submissions (422
    otherwise): openapi marks it optional-shaped but RATING.md §4 cannot rate
    an endless solve without it; storing spec+steps together in
    `endless_spec` per the schema comment.

## Files touched
- `api/app/Domain/Solves/{Board,BurnValidator,BurnVerdict,BurnVerdictReason,InvalidBoardSpec,SolveSubmissionService}.php` (new)
- `api/app/Domain/Solves/SolveStore.php` (added `listFor` cursor pagination)
- `api/app/Domain/Streaks/{StreakService,StreakRollover}.php` (new); `StreakStore.php` (summary delegates to StreakService)
- `api/app/Domain/Ratings/Events/{RatableSolveRecorded,FailedDailyRecorded}.php` (new — the WS-08 seam)
- `api/app/Domain/Content/{ContentImporter,ContentRollback,ContentImportException}.php` (new)
- `api/app/Console/Commands/{ContentImportCommand,ContentRollbackCommand,StreaksRollover}.php` (new)
- `api/app/Http/Controllers/Api/V1/{DailyController,SolveController,StreakController,RatingController,HealthController}.php` (new)
- `api/app/Models/{Puzzle,DailyPuzzle,DailyStat,ContentImport}.php`, `api/app/Models/Casts/PgDateArray.php` (new); `Streak.php` (cast)
- `api/config/burnfront.php` (new), `api/.env.example` (content block)
- `api/routes/api.php` (new routes), `api/routes/console.php` (rollover schedule), `api/app/Providers/AppServiceProvider.php` (solves limiter)
- `api/database/factories/{PuzzleFactory,DailyPuzzleFactory}.php` (new)
- `api/tests/Unit/BurnValidatorVectorsTest.php`, `api/tests/Feature/{Daily/DailyEndpointTest,HealthTest,Solves/SubmitSolveTest,Solves/MySolvesTest,Streaks/StreakTest,Content/ContentCommandsTest}.php` (new), `api/tests/Pest.php` (fixture helpers)
- `tasks/WS-07/STATUS.md` (this file)

## Resume instructions
1. Postgres 16 on `127.0.0.1:55432` (user postgres, trust), database
   `burnfront_test` — recipe in `api/tests/schema-conformance.sh` header. A
   cluster from this session may already be running.
2. `cd api && composer install` (this environment needs git-source installs —
   see tasks/WS-06/STATUS.md decision 6/10; normal environments use the lock
   dists), copy `.env.example` → `.env`, `php artisan key:generate`.
3. Gates: `php artisan test` (106) · `vendor/bin/pint --test` ·
   `vendor/bin/phpstan analyse` · `bash scripts/hygiene.sh` (repo root).
4. Next: a separate verifier session executes the brief acceptance checklist
   line by line (all six boxes have direct test coverage; map is in the test
   file names above).
