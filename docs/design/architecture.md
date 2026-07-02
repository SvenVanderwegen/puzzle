# Burnfront v1 — Technical Architecture

## 1. Repo strategy

**One monorepo, Laravel included.** Solo dev + AI-agent teams means the contract between FE/BE must be reviewable and changeable atomically: one PR touches `contracts/openapi.yaml`, the Laravel controller, and the generated TS client together. Cross-language test vectors (Python↔TS↔PHP) only work if all three consumers pin the same vector files at the same commit. Two repos would double CI, secrets, and agent-session setup for zero benefit at this scale. Composer and pnpm coexist fine; CI path-filters keep jobs fast.

**Frontend: Vite + React SPA, no Next.js, no Inertia.** Reasoning:
- The only SSR needs are (a) landing page SEO and (b) share-link unfurling. Unfurl bots read OG meta tags, not JavaScript — plain Laravel Blade routes satisfy both. Running a Next server (or Inertia's Node SSR sidecar) violates the locked "no Node on the server" decision and duplicates routing/auth for nothing.
- Inertia couples the game UI to Laravel routing, which poisons the phase-2 Expo reuse story. A pure SPA over a REST API is exactly the shape the mobile app will consume.
- React (19) because game-core is framework-agnostic anyway, and React knowledge transfers 1:1 to Expo RN in phase 2.

Routing split on burnfront.com (single origin, so Sanctum cookie auth is trivial):
- `/`, `/rules`, `/d/{date}` (share page with OG tags + pre-rendered card image) → **Laravel Blade**, cached.
- `/play/*` → serves the built SPA `index.html`; hashed assets from `public/build` via Cloudflare.
- `/api/v1/*` → JSON API.
- `content.burnfront.com` → static puzzle JSON on R2/CDN (see §5).

**Tooling:** pnpm workspaces + Turborepo (task graph + local/CI caching; near-zero config, worth it even at 3 packages), Vite + `vite-plugin-pwa` (offline daily), Vitest, Playwright. TS 5.x strict everywhere.

```
burnfront/
├─ apps/
│  └─ web/                    # Vite+React SPA (hub, daily, endless, academy, coach)
├─ packages/
│  ├─ engine/                 # pure TS, ZERO deps: rules, BFS, uniqueness, deduction,
│  │                          #   generator, grader, puzzle codes, replay. Injected RNG only.
│  ├─ game-core/              # framework-agnostic state machines: marks/undo/timer/coach/
│  │                          #   solve-session; depends on engine only
│  └─ api-client/             # GENERATED from contracts/openapi.yaml (openapi-typescript);
│                             #   never hand-edited
├─ api/                       # the Laravel app (composer root)
│  ├─ app/Domain/{Solves,Ratings,Streaks,Content,Auth}/   # module dirs, see §9
│  └─ database/migrations/
├─ pipeline/                  # Python: firebreak.py moves here; generate/grade/curate/sign
│  └─ tools/make_vectors.py   # emits contracts/vectors/*
├─ contracts/
│  ├─ openapi.yaml            # THE FE/BE handshake artifact
│  ├─ schemas/                # JSON Schema: puzzle artifact, manifest, calendar (§5)
│  └─ vectors/                # canonical cross-language test vectors (§6)
├─ docs/adr/                  # 0001-record-architecture-decisions.md, ...
├─ .github/workflows/ci.yml
├─ turbo.json  pnpm-workspace.yaml  CLAUDE.md
└─ (index.html prototype archived to docs/prototype/ once extracted)
```

## 2. Laravel API design

**Auth:** Sanctum stateful SPA mode (same-origin cookie + CSRF; no tokens in JS). Phase 2 mobile switches to Sanctum personal access tokens — same guards, no rework. Identity: **passwordless** — email magic link (Resend/Postmark) + Socialite Google and **Sign in with Apple at v1** (adding Apple later creates account-linking pain, and App Store rules will force it in phase 2 anyway). Table `auth_identities` decouples provider from user, so linking is a row insert. No passwords stored, ever — smaller GDPR/breach surface.

**Endpoints** (`/api/v1`, all JSON, versioned in path):

| Method/path | Purpose |
|---|---|
| `POST /auth/magic-link` | request login link (throttle 5/min/IP) |
| `POST /auth/magic-link/consume` | exchange token → session |
| `GET /auth/{google\|apple}/redirect`, `/callback` | Socialite |
| `POST /auth/logout` | |
| `GET /me` | profile, entitlements, streak summary, rating summary |
| `PATCH /me` | handle, display prefs |
| `DELETE /me` | GDPR erasure (queued job; 30-day soft window) |
| `GET /me/export` | GDPR portability (queued, emailed link) |
| `GET /daily/{date}` | `{date, puzzle_id, content_url, grade, stats:{solved_count, p50_ms}}` — points at CDN, no puzzle body |
| `POST /daily/{date}/start` | opens the solve window (records `fetched_at`, anti-cheat anchor) |
| `POST /solves` | submit solve (below) |
| `POST /solves/batch` | offline sync, ≤50 items, same semantics per item |
| `GET /me/solves?cursor=` | history |
| `GET /me/streak` | `{current, best, last_date, safe_until}` |
| `GET /me/rating` | Glicko-2 `{rating, rd, volatility, games}` + sparkline buckets |
| `GET /leaderboards/daily/{date}?scope=global&cursor=` | time-ordered valid solves |
| `GET /puzzles/{id}` | metadata proxy (mostly unused; clients hit CDN) |

**Solve submission:**

```
POST /api/v1/solves
Idempotency-Key: 018f3c4e-...   (client-generated UUIDv7, = client_solve_id)

{ "puzzle_id":"bf1-7x7-000123", "mode":"daily",
  "shaded":"0010100...(row-major bitstring)",
  "client_ms":187342, "hints_used":1, "undo_count":4,
  "started_at":"2026-07-02T08:11:03Z",
  "replay":"<base64 gzip event log>", "replay_sha256":"..." }

→ 201 { "solve_id":"...", "valid":true, "official_ms":187342,
        "streak":{"current":12}, "rating":{"pending":true},
        "daily":{"rank":841,"percentile":63} }
```

**Idempotency:** unique `(user_id, client_solve_id)`; on replay of the same key, return the stored original response (persist a `response_snapshot` jsonb on the solve). Additionally a partial unique index — one *valid daily* solve per `(user_id, puzzle_id)` — makes double-counting structurally impossible. Rating computation is a queued job (`pending:true` → client polls `/me/rating` or gets it next fetch), so submissions stay <50ms.

**Rate limits** (RateLimiter::for): auth 5/min/IP; `POST /solves` 30/min/user; batch 5/min; leaderboards 60/min/user; global 120/min/IP fallback.

## 3. Postgres schema (key columns only)

```sql
users            id ulid PK, email citext UNIQUE NULL, handle citext UNIQUE,
                 country char(2), plan text DEFAULT 'free', pro_until timestamptz NULL,
                 created_at, deleted_at NULL          -- entitlement = plan+pro_until now;
                                                      -- entitlements table when billing lands
auth_identities  id, user_id FK, provider text, provider_uid text,
                 UNIQUE(provider, provider_uid)
puzzles          id text PK ('bf1-7x7-000123'), spec jsonb, rows int2, cols int2,
                 n_breaks int2, grade_score numeric, grade_tier text,
                 solution_sha256 text, gen_version text, content_version text,
                 pack_id text NULL, imported_at
daily_puzzles    date date PK, puzzle_id FK UNIQUE, published_at, calendar_version text
puzzle_fetches   user_id FK, puzzle_id FK, fetched_at, PRIMARY KEY(user_id, puzzle_id)
                 -- written by POST /daily/{date}/start; the solve-time upper bound
solves           id bigint PK, user_id FK, puzzle_id FK NULL, mode text
                   CHECK (mode IN ('daily','pack','endless')),
                 client_solve_id uuid, shaded_bits bytea,
                 client_ms int, official_ms int, started_at timestamptz,
                 received_at timestamptz DEFAULT now(),
                 valid bool, reject_reason text NULL,
                 hints_used int2, undo_count int, replay bytea NULL,
                 replay_sha256 text NULL, ip_hash text, ua_hash text,
                 endless_spec jsonb NULL,             -- endless boards aren't in puzzles
                 response_snapshot jsonb,
                 UNIQUE(user_id, client_solve_id);
                 UNIQUE(user_id, puzzle_id) WHERE mode='daily' AND valid;
                 -- monthly range partitions from day one (cheap now, painful later)
ratings          user_id PK, rating real DEFAULT 1500, rd real DEFAULT 350,
                 volatility real DEFAULT 0.06, games int, updated_at
board_ratings    puzzle_id PK, rating real, rd real, volatility real,
                 attempts int, updated_at            -- seeded from grade_score, then live
rating_events    id, solve_id FK, user_id, puzzle_id, score real,  -- 0..1
                 user_before/after real, board_before/after real, created_at
                 -- full audit → deterministic recompute after cheat purges
streaks          user_id PK, current_len int, best_len int, last_daily_date date
                 -- cache; recomputable from solves
content_imports  id, content_version text, manifest_sha256 text, sig_ok bool, imported_at
```

Decisions embedded here: **daily day boundary is UTC** (one global board, documented in-app; no timezone streak disputes). **Endless is unrated** in v1 — client-generated boards can't be trusted for rating and PHP can't afford uniqueness checking; endless solves are logged with `endless_spec` for telemetry only. **Offline solves**: accepted via `/solves/batch` up to 48h late; daily streak credit requires a `puzzle_fetches` row stamped within that UTC day — proof they had the board in time.

## 4. Anti-cheat / validation

**Server verifies (PHP, ~30-line BFS, pure function in `app/Domain/Solves/BurnValidator.php`):** shaded count == `n_breaks`; spark and clue cells unshaded; BFS from spark over unshaded cells reaches every unshaded cell; every clue burns at exactly its minute. Result `valid` is authoritative — an invalid submission is stored (`valid=false, reject_reason`) but never counts. Cross-check `sha256(shaded_bits) == puzzles.solution_sha256` as a belt-and-suspenders assert (uniqueness guarantees they match; a mismatch means content corruption → alert).

**Times:** never trust `client_ms` alone. `official_ms = min(client_ms, received_at − puzzle_fetches.fetched_at)`, floored at a perceptual minimum (`n_breaks × 250ms`); below the floor → valid solve, flagged `suspect`, excluded from leaderboard. No `/start` call → solve valid but leaderboard-ineligible.

**Trusted (v1):** `hints_used`, `undo_count` self-reports — they only scale the rating score downward, so lying about them only helps honest-looking behavior marginally; replay audit closes this later.

**Stored for later anomaly detection:** compressed replay event log (`[t_ms, cell, mark]` tuples) + digest; `ip_hash`/`ua_hash` (SHA-256 with server pepper — pseudonymous, GDPR-lean); fetch→first-event latency. Enables: identical-replay-digest collisions across accounts, time-vs-grade z-scores per user, inhumanly uniform inter-event intervals, solve-time distribution drift per board. V1 ships the *data capture* and the hard validator only; detection jobs are a later queued-job feature, and `rating_events` makes post-purge rating recomputation deterministic.

## 5. Content pipeline contract

**Puzzle artifact** (`contracts/schemas/puzzle.v1.json` governs):

```json
{ "schema": "burnfront.puzzle/1",
  "id": "bf1-7x7-000123",
  "engine": { "gen_version": "py-1.4.0", "rules_version": 1 },
  "board": { "rows": 7, "cols": 7, "spark": [3,3], "breaks": 8,
             "clues": [ {"r":0,"c":2,"m":5} ] },
  "grade": { "tier": "tricky", "score": 62.4,
             "techniques": ["late-clue-pinch","count-endgame"] },
  "certificates": { "unique": true, "deduction_steps": 31, "witnessed": true },
  "solution_sha256": "…" }
```

**Pack manifest / daily calendar** (`burnfront.pack/1`, `burnfront.calendar/1`): calendar = `{content_version, from, to, days:[{date, puzzle, grade_tier}], files:{"puzzles/bf1-….json":"sha256…"}}`. The manifest carries the sha256 of every referenced file; **only the manifest is signed** — Ed25519, private key offline with the owner (pipeline uses PyNaCl; importer verifies with PHP `sodium_crypto_sign_verify_detached`; public key baked into the Laravel config). Chain of trust: signature → manifest → hashes → files.

**Where files live:** pipeline **code + schemas + small test fixtures** in git; generated artifacts are build outputs, NOT committed. Pipeline writes `dist/content/{content_version}/…`, uploads to Cloudflare R2, served at `content.burnfront.com/{content_version}/puzzles/{id}.json` with `Cache-Control: public, max-age=31536000, immutable` (versioned paths = trivially cacheable forever). Calendar files are per-month and also immutable per `content_version`.

**Import path:** `php artisan content:import {manifest_url}` → verify signature → verify file hashes → upsert `puzzles` + `daily_puzzles` in one transaction → write `content_imports`. Run manually per content drop; the DB row for a puzzle is metadata + validator inputs, the CDN JSON is what clients actually load. Dailies are published 60+ days ahead; a scheduled Nightwatch-monitored check alerts when runway < 21 days.

## 6. Testing & quality architecture

**Cross-language vectors** (the crown jewels, `contracts/vectors/`): generated only by `pipeline/tools/make_vectors.py` from firebreak.py (the authority), committed to git.
- `burn.v1.jsonl` — (board, shading) → full minute grid + validity verdict (~500 cases incl. adversarial: unreachable pockets, off-by-one clue minutes, spark-adjacent breaks).
- `deduction.v1.jsonl` — puzzle → certified step list (TS deduction solver must reproduce).
- `generate.v1.jsonl` — (seed, params) → board, for TS generator parity where PRNG is shared.

Consumers: pytest (self-consistency), Vitest in `packages/engine` (all three), Pest in `api/` (`burn.v1` only — that's all PHP implements). Rules drift between the three implementations becomes a red CI, not a player-facing bug.

**Layers:** Vitest unit (engine, game-core reducers), Pest unit + feature (validator, solve endpoint incl. idempotency-replay and clock-clamping tests, importer signature failure), Pest `arch()` tests for module boundaries, Playwright e2e (seeded staging build: signup via mailhog magic link → play daily → submit → streak increments → share page unfurls with correct OG tags).

**GitHub Actions** (`ci.yml`, path-filtered jobs): `lint-ts` (eslint + `tsc --noEmit`), `lint-php` (`pint --test` + Larastan), `test-ts` (turbo-cached Vitest), `test-php` (Pest w/ `postgres:16` service container), `test-python` (pytest + `firebreak.py --selftest`), `vectors-fresh` (regenerate vectors, `git diff --exit-code` — catches silent reference drift), `e2e` (Playwright, built SPA + `php artisan serve`), `build`. Caching: pnpm store, Composer, Turborepo, Playwright browsers. All required checks; merges to `main` only via PR.

**Migrations discipline:** merged migrations are immutable; expand/contract for any rename (add → backfill → switch reads → drop in a later release); CI runs `migrate:fresh` + `migrate` against the previous release's schema dump; destructive migrations require an ADR reference in the migration docblock.

## 7. Hosting/ops (solo dev, EU)

- **Compute:** Hetzner CPX31 (4 vCPU/8GB, Falkenstein — EU data residency for GDPR) ≈ €16/mo, provisioned by **Laravel Forge** ($13/mo). One box runs nginx + php-fpm, Redis (sessions/cache/queues + Horizon), scheduler cron, and Postgres 16.
- **Postgres on the same box for v1.** At these write volumes (a few solves/user/day) managed PG buys nothing but latency and €50/mo. Mitigate with discipline: nightly `pg_dump` to R2 (30-day retention) + hourly WAL-less incremental via `pg_dump --format=custom` of hot tables, plus weekly Hetzner box snapshots. Quarterly restore drill (calendar reminder — a backup you haven't restored is a hope). Move to managed EU PG (Aiven/Crunchy) at the 50k-DAU trigger, not before.
- **Static/CDN:** Cloudflare free tier — DNS, TLS (Full Strict; Forge/LE on origin), CDN for `content.burnfront.com` (R2 custom domain) and SPA assets. R2 ≈ €0–1/mo (no egress fees — right choice for puzzle JSON served to every client daily).
- **Email:** Postmark or Resend, ~$0–15/mo (magic links must not land in spam).
- **Deploy:** GitHub Actions builds SPA + runs CI → on `main` merge, Forge deploy hook: atomic release directory + symlink flip, `php artisan migrate --force`, Horizon terminate/restart, `config:cache`. Built SPA assets ship inside the release (single deploy unit — API and client can't skew). Staging: second Forge site on the same box, `staging.burnfront.com`, separate DB + `.env`, HTTP basic auth, auto-deploys `main`; prod deploys on tag.
- **Nightwatch:** agent per environment (`NIGHTWATCH_TOKEN` in each `.env`); watch exceptions, slow routes (`POST /solves` p95 alert at 200ms), queue lag, scheduled-task misses (content-runway check, backup job). Alerts → email.
- **Total: ≈ €35–50/mo** (+ €60/yr domains). Laravel Cloud is the fallback if box-tending ever exceeds ~2h/mo, at roughly 2–3× cost.

## 8. Scaling path

| Stage | First thing to break | Pre-planned response (design already permits) |
|---|---|---|
| 1k DAU | Nothing. | Daily puzzle bytes are on CDN; API handles only auth/solves (~2k writes/day). Do nothing. |
| 50k DAU | Morning-UTC daily spike: leaderboard reads + rating-job queue depth; PG contention on `solves`. | Leaderboards → Redis sorted sets (write-through on valid solve, PG stays source of truth). Rating already async — add workers. Split PG to managed EU instance; add one read replica for leaderboards/stats/exports. Second app box behind Hetzner LB (sessions already in Redis — stateless FPM). |
| 500k DAU | `solves` table size; Redis single node; share-card rendering; rating write hotset on the daily's `board_ratings` row. | Partitions already exist (monthly, from day one). Redis → managed/cluster. OG card images pre-rendered at content-import time, served from R2 (no runtime rendering). Batch the daily board's Glicko updates (accumulate → apply every N sec). CDN-cache `GET /daily/{date}` stats with 30s TTL. |

**Deliberately NOT built now:** microservices, Kubernetes, multi-region, GraphQL, event sourcing, websockets infra (Reverb arrives with phase-3 duels as an additive service on the same box), custom feature-flag service, real-time anomaly detection, billing (only the `plan`/`pro_until` columns exist so entitlements are a data change, not a schema change).

## 9. Maintainability rules for AI-agent-built code

- **PHP:** `declare(strict_types=1)` enforced, Pint (laravel preset) in CI, **Larastan level 9**. Domain logic in `app/Domain/{Solves,Ratings,Streaks,Content,Auth}` — controllers are thin adapters; Pest `arch()` tests enforce it (Domain must not reference `Illuminate\Http`; only `Content` touches R2; only `Ratings` writes `ratings`/`rating_events`).
- **TS:** `strict` + `noUncheckedIndexedAccess` + `exactOptionalPropertyTypes`; typescript-eslint `strictTypeChecked`; **dependency-cruiser in CI** enforcing: `engine` imports nothing (zero deps, no `Date.now`/`Math.random` — RNG and clock injected); `game-core` imports only `engine`; `apps/web` imports packages, never vice versa; `api-client` is generated-only (CI fails if hand-edited — regenerate and diff).
- **Contract-first:** `contracts/openapi.yaml` is the single FE/BE handshake. Rule for agents: *change the spec first, in its own commit*. FE consumes the generated `packages/api-client`; BE feature tests validate every response against the spec via **Spectator** — drift is a red build, not a runtime surprise.
- **ADRs:** MADR-style `docs/adr/NNNN-slug.md`. Required for anything crossing a package/module boundary or contradicting an existing ADR; the locked decisions in this document become ADRs 0001–0008 on day one. A decision that isn't an ADR doesn't exist — agents cite ADR numbers in PR descriptions.
- **Agent workstream isolation** maps to the directory boundaries: engine-agent (`packages/engine` + `pipeline/tools/make_vectors.py`), web-agent (`apps/web`, `packages/game-core`), api-agent (`api/`, `contracts/openapi.yaml`), pipeline-agent (`pipeline/`, `contracts/schemas/`). Each root gets its own `CLAUDE.md` stating local invariants (e.g. engine: "deterministic; vectors are law; changing behavior requires regenerating vectors from the Python reference, never hand-editing them"). Shared surfaces (`contracts/`) are the only sanctioned cross-stream files, keeping parallel agent sessions merge-conflict-free.
- **Process:** conventional commits; protected `main` with all CI checks required; generated files (`api-client`, vectors) verified-in-CI rather than trusted; `firebreak.py` stays the behavioral authority for the genre — any rules change lands there first, then propagates via vectors.