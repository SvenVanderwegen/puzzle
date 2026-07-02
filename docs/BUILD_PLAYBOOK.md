# Burnfront Build Playbook — v1 (web launch on burnfront.com)

The operating manual for building Burnfront with orchestrated AI agent sessions.
Decisions: `docs/decisions.md` (authoritative) · Background: `docs/design/*` (not
authoritative) · Repo law for agents: `CLAUDE.md` · Task briefs: `tasks/WS-XX/brief.md`.

**V1 =** landing page + game hub (Daily Burn Order, Endless, Academy 7 lessons, Coach) +
accounts (magic link) + streaks with monthly freeze + Fire Rating (Glicko-2,
boards-as-opponents) + spoiler-free share cards + first-party analytics — free, EN-only,
GDPR-clean, zero third-party requests.

---

## 1. Canonical repo tree (post WS-00)

```
burnfront/
├─ apps/web/                  # Vite + React SPA (TanStack Router, vite-plugin-pwa)
├─ packages/engine/           # pure TS, ZERO runtime deps (CI-enforced); RNG/clock injected
├─ packages/game-core/        # framework-agnostic state machines; imports engine only
├─ packages/ui-web/           # board + burn-replay React components
├─ packages/api-client/       # GENERATED from contracts/openapi.yaml; never hand-edited
├─ api/                       # Laravel 12 app: Sanctum SPA auth, app/Domain/{Auth,Solves,
│                             #   Ratings,Streaks,Content}, thin controllers, Blade public pages
├─ pipeline/                  # Python: generate → grade v2 → curate → sign → emit content
│  └─ tools/make_vectors.py   # sole producer of contracts/vectors/
├─ contracts/                 # FROZEN after ADR-0011:
│  ├─ openapi.yaml            #   the FE/BE handshake (api-client is generated from it)
│  ├─ engine-api.d.ts         #   exact public engine surface
│  ├─ schemas/                #   puzzle.v1 / pack.v1 / calendar.v1 JSON Schemas
│  ├─ db-schema.sql           #   table baseline (migrations must diff-match)
│  ├─ design-tokens.json      #   colors/spacing/type/motion (night incident map)
│  ├─ COPY.md                 #   voice guide + canonical strings (keyed; EN)
│  ├─ RATING.md               #   Glicko-2 params, outcome function, endless weight, fixtures
│  ├─ DEPENDENCIES.md         #   dependency allowlist
│  └─ vectors/                #   ~500 burn cases + generation seeds + deduction certificates
├─ tasks/WS-XX/{brief.md,STATUS.md}   # agent ledger (one dir per workstream)
├─ docs/{BUILD_PLAYBOOK.md,decisions.md,gdpr.md,RUNBOOK.md,adr/,design/}
├─ e2e/                       # Playwright journeys + Lighthouse budgets
├─ reference/                 # frozen prototype: firebreak.py, index.html (read-only)
└─ CLAUDE.md  CODEMAP.md  turbo.json  pnpm-workspace.yaml  .github/workflows/
```

Import DAG (dependency-cruiser + Pest `arch()` enforced): `apps/web → packages/* → engine`;
engine imports nothing; `api/` Domain modules never reference `Illuminate\Http`; only
`Content` touches R2; only `Ratings` writes rating tables.

## 2. System flows (what the pieces do)

- **Content:** pipeline emits `dist/content/{version}/…` (immutable, Ed25519-signed
  manifest; private key offline with owner) → upload to R2 → `content.burnfront.com` with
  `immutable` cache headers → `php artisan content:import {manifest}` verifies signature +
  hashes, upserts `puzzles`/`daily_puzzles` transactionally. Dailies published 60+ days
  ahead; published dates immutable from T-48h; `content:rollback {version}` repoints in one
  transaction. Runway alert < 21 days; tomorrow-exists alert at T-2h.
- **Play:** client fetches daily JSON from CDN (API fallback body behind a flag if CDN
  down) → `POST /daily/{date}/start` stamps `puzzle_fetches` → solve locally (engine
  validates without ever downloading the answer — uniqueness makes that sound) →
  `POST /solves` with Idempotency-Key: PHP `BurnValidator` (~30-line BFS) re-checks against
  clues; `official_ms = min(client_ms, received_at − fetched_at)` and must be
  `≥ replay duration`, else flagged `suspect` (valid but percentile-ineligible).
- **Progression:** valid solve → queued Glicko-2 update vs the board's rating (seeded from
  pipeline grade; endless solves rated at reduced weight per RATING.md; stage-3 hint ⇒
  unrated) → streak (UTC; freeze 1/month auto-applied by `streaks:rollover`) → percentile
  from `daily_stats` aggregates. No named leaderboards in v1.
- **Anonymous-first:** everything playable without an account (localStorage state). Signup
  pitch = "protect your streak" (primary nudge post-solve on streak day 3). On signup,
  `POST /me/import` re-validates local solves server-side; streak credit capped at 7 days;
  `imported=true` solves are percentile-ineligible; rating seeded at high RD.
- **Privacy:** no third-party requests at all. First-party `events` + `errors` endpoints;
  weekly KPI digest to owner. Delete = anonymize (aggregates survive); export = signed URL,
  24h, single-use; replays/ip-hashes purged at 90 days; events aggregated then purged at
  13 months. Processor inventory in `docs/gdpr.md`.

## 3. Workstreams

Format: deps ⇒ what must be merged first. Full details in each `tasks/WS-XX/brief.md`.

| WS | Name | Deps | Sessions |
|---|---|---|---|
| 00 | Repo restructure + toolchain + skeleton CI | — | 1 |
| 01 | Contract pack + freeze ADR | 00 | 3 |
| 02 | Engine extraction to `packages/engine` (vector parity) | 01 | 2 |
| 03 | `packages/game-core` state machines | 02 | 1 |
| 04 | `packages/ui-web` board + burn replay | 03 | 2 |
| 05 | Content pipeline productionization + grading v2 | 01 | 2 |
| 06 | Laravel scaffold + magic-link auth + GDPR base | 01 | 2 |
| 07 | Game API: daily, solves, streaks + freeze, content import | 06 | 2 |
| 08 | Fire Rating service (Glicko-2) | 07 | 2 |
| 09 | SPA shell + design system + hub | 04 | 2 |
| 10 | Daily page + streak UI + share cards + unfurl | 09, 07, 05 | 2 |
| 11 | Endless mode (Web Worker generation) | 09 | 1 |
| 12 | Academy (7 lessons) | 09, 05 | 2 |
| 13 | Coach (3-stage explainable hints) | 12 | 1 |
| 14 | Accounts UI + GDPR self-service + legal routes | 09, 06 | 1 |
| 15 | Landing page + /about + SEO | 01, 04 | 1 |
| 16 | CI/CD full gates + infra (Forge/CPX31/R2) | 00 (skeleton), 06/09 (deploy legs) | 2 |
| 17 | E2E suite + Lighthouse budgets | 10..15 | 1 |
| 18 | Ops: Nightwatch, backups, runbooks, alerts | 16 | 1 |
| 19 | First-party analytics + error beacon + weekly digest | 06 | 1 |
| 20 | Anonymous→account merge (`POST /me/import` + web flow) | 07 | 2 |
| 21 | Transactional email (EU ESP; streak-risk alert) | 06 | 1 |
| 22 | Security review (auth/solves/import/events + CSP/headers) | P4 (all API surface merged) | 1 |

**Parallel lanes after WS-01** (max 4 concurrent worktrees — integration cost beats lane
count beyond that):
Lane A frontend: 02→03→04→09→{10, 11, 12→13, 14} · Lane B backend: 06→07→{08, 20, 21} ·
Lane C content: 05 · Lane D independent: 15, 16, 18, 19.
**Critical path:** 00→01→02→03→04→09→10→17 (~13 sessions). Riskiest node is WS-02 (Python↔TS
parity): schedule it first; nothing downstream of it starts until vectors are green.
**Totals:** ~48 build + ~8 lead-integration + ~7 rework buffer ≈ **60 sessions**.

## 4. Contract freeze

WS-01 authors every file in `contracts/` **from `docs/decisions.md` only**, the lead checks
cross-consistency (solve payload ↔ db-schema ↔ RATING inputs ↔ engine API), the owner
approves, and `docs/adr/0011-contract-freeze.md` lands. From then on CI blocks any diff
touching `contracts/` unless the branch adds an `adr/NNNN-*.md` and carries the
`contract-change` label; consumers are updated in the same integration cycle.

## 5. Quality gates (every merge, in order, fail fast)

1. Typecheck — TS `strict` (+`noUncheckedIndexedAccess`), no `any`; PHP Larastan level 9.
2. Lint/format — ESLint flat + Prettier; Pint `--test`.
3. Unit/feature — Vitest + Pest; coverage floors: engine 95%, game-core 90%, others 70%.
4. **Cross-language vectors** — TS engine (and PHP validator when touched) vs
   `contracts/vectors/`. Any mismatch auto-blocks. No exceptions, no overrides.
5. Contract conformance — `packages/api-client` regenerates diff-clean; Spectator validates
   Laravel responses against `openapi.yaml` in feature tests.
6. Playwright smoke — the 5 journeys against a local full stack (compose: Postgres +
   Laravel + built SPA + mailpit).
7. Lighthouse budgets + axe — landing ≤90KB deferred JS / LCP ≤2.0s; SPA ≤200KB initial;
   a11y ≥95; zero serious axe violations.
8. Adversarial review — a separate reviewer session runs `/code-review` (high effort) with
   the brief's acceptance criteria pasted in, actively trying to falsify each one by
   executing the code. CONFIRMED findings → back to author.
9. Hygiene sweep — no `console.log`/`dd()`/`var_dump`/`TODO`/`.only`; gitleaks; engine
   `dependencies` still `{}`; lockfile diff matches `contracts/DEPENDENCIES.md`.

**Escalation:** the same gate failing **twice** on a branch halts the workstream. Author
writes `FAILED` to STATUS.md (symptom, hypothesis, attempts). Lead triages into exactly one:
(a) spec wrong → ADR + contract amendment; (b) task too big → split the brief;
(c) approach wrong → new brief with an explicit "do not X" constraint. Never a third blind
retry.

## 6. Session protocol

**Owner starts a session** by pasting:

```
Lead: run workstream WS-10.
Brief: tasks/WS-10/brief.md   Status: tasks/WS-10/STATUS.md (resume from it)
Contracts are FROZEN read-only. Work in worktree branch ws-10.
Done = brief acceptance criteria + playbook §5 gates. Gate fails twice → stop, write FAILED.
Do not touch: contracts/, reference/, other tasks/, workflows, dependencies.
```

**Ledger:** agents append machine-parsable STATUS.md sections at session end (`Done` with
SHAs / `Remaining` / `Blockers` / `Decisions made` / `Files touched` / `Resume
instructions`). A fresh agent must be able to resume from the repo alone — chat is not a
handoff medium.

**Lead integration session** every 3–4 completed workstreams: merge branches in DAG order,
re-running gates 1–6 after *each* merge; mechanical conflicts resolved by the lead, semantic
ones spawned to a fix-up subagent; update `CODEMAP.md`; log to `tasks/INTEGRATION-LOG.md`.
Rejected branches go back with a fresh brief — the lead never silently fixes them.

**Verification separation:** builders never sign off their own acceptance criteria; a
verifier session executes each criterion literally and reports pass/fail per line.

## 7. Schedule (owner supervising ~1–2 h/day ≈ 1–2 sessions/weekday)

| Phase | Weeks | Content | Owner actions |
|---|---|---|---|
| P0 Foundations | 1 | WS-00, WS-01 | Register burnfront.com/.app/.io **now**; GitHub repo + Actions; Hetzner/Forge/Cloudflare/R2; ESP account; Nightwatch project |
| P1 Core parity | 2–3 | WS-02/03 ∥ WS-06, WS-05 | Approve contract freeze |
| P2 Platform | 3–5 | WS-04, 07, 08, 09, 16 | Forge server provisioning w/ WS-16 runbook |
| P3 Features | 5–7 | WS-10..15, 20, 21 | Review agent-drafted privacy/terms/imprint (lawyer skim recommended) |
| P4 Hardening | 7–8 | WS-17, 18, 19, 22; 365-daily content top-up; staging soak | Restore-drill sign-off |
| **P4.5 Playtest** | 9–10 | Wave 1 (n=5 moderated) + wave 2 (n=15 unmoderated); calibration loop run twice | Recruit 20 testers; run wave-1 calls |
| P5 Launch | 11–12 | Prod deploy, DNS cutover, prod smoke, share-card check | Press the deploy button; HN/PH/Reddit posts per PLAN.md §5 |

**Go/no-go gate (end of P4.5):** activation ≥ 55% (first visit → first contained board),
tutorial completion ≥ 70%, ≥ 8/15 wave-2 testers return on 3+ distinct days, and **zero**
credible "felt like guessing" reports (that one is the brand promise — a report is a
pipeline bug, not an opinion).

**KPIs post-launch:** activation ≥ 55% and median time-to-first-solve < 8 min ·
D1 ≥ 40% / D7 ≥ 15% · daily completion ≥ 60% (Mon > 75%, Sat ~45% by design). Secondary:
day-3 account conversion, share copies per contain ≥ 8%, hint stages per solve, abandon
heatmap.

## 8. The first three sessions

1. **WS-00** — restructure to the canonical tree (git mv, history preserved), pnpm/turbo/
   TS/ESLint/Vitest scaffolding, skeleton CI (gates 1–3, 9), `CODEMAP.md`. Same day, owner
   registers the domains.
2. **WS-01a** — behavioral contracts: `--emit-vectors` added to the Python reference (the
   only permitted `reference/` edit, ADR-noted); ~500 burn cases + generation seeds +
   deduction certificates into `contracts/vectors/`; `engine-api.d.ts`; puzzle/pack/calendar
   JSON Schemas + one hand-validated sample daily.
3. **WS-01b** — platform contracts: `openapi.yaml`, `db-schema.sql`, `RATING.md` (endless
   weight + numeric fixtures), `design-tokens.json` (extracted from the prototype CSS),
   `COPY.md`, `DEPENDENCIES.md`; lead cross-consistency review; owner approval;
   `adr/0011-contract-freeze.md`; CI contracts-guard. Lanes A and B open the next day.
