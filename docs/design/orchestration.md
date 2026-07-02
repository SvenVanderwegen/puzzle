# Burnfront v1 — AI-Agent Orchestration Build Plan

Repo today: `firebreak.py` (reference engine), `index.html` (full prototype), `README.md`, `PLAN.md`. Target: burnfront.com web launch, Laravel+Postgres backend, TS monorepo frontend, offline Python content pipeline. This document designs the *build process*: workstreams, contracts, gates, session protocol, guardrails, schedule.

One framework decision this plan makes explicit (forced by "No Node on the server"): **apps/web is a Vite + React SPA** (TanStack Router, vite-plugin-pwa), built to static assets and deployed into Laravel's `public/` — one origin, Sanctum cookie auth, no CORS. Share-link unfurling is handled by Laravel routes emitting OG meta + pipeline-pregenerated card PNGs, not SSR.

---

## 1. Work breakdown — 20 workstreams

Repo layout the workstreams build toward:

```
/specs            frozen contracts (§3)          /packages/engine     pure TS, zero deps
/adr              architecture decision records  /packages/game-core  state machines
/tasks/WS-XX      brief.md + STATUS.md ledger    /packages/ui-web     DOM board component
/pipeline         Python: generate→grade→sign    /apps/web            Vite SPA hub
/content          built JSON+PNG (CI artifact)   /services/api        Laravel app
/reference        firebreak.py, index.html (frozen prototype, read-only)
```

Format per workstream: **Scope / Inputs / Outputs / Acceptance / Sessions** (1 session ≈ one focused Claude Code run producing a mergeable branch).

**WS-00 Repo restructure & toolchain** — Move `firebreak.py` + `index.html` to `/reference` (frozen, read-only from now on); scaffold pnpm workspace, TS 5.x strict, ESLint flat + Prettier, Vitest; empty package skeletons; root `CLAUDE.md` with agent rules (§6); `tasks/` + `specs/` + `adr/` dirs. *Inputs:* this plan. *Outputs:* the tree above, `pnpm-workspace.yaml`, `CLAUDE.md`, `CODEMAP.md`. *Acceptance:* `pnpm -r typecheck && pnpm -r lint && pnpm -r test` green on empty skeletons; `git log` preserves history of moved files. **1 session.**

**WS-01 Contract pack** — Author every frozen artifact in §3. *Inputs:* `reference/index.html` (existing engine surface + visual design), `reference/firebreak.py`, PLAN.md. *Outputs:* `specs/engine-api.d.ts`, `specs/puzzle-content.schema.json`, `specs/openapi.yaml`, `specs/db-schema.sql`, `specs/design-tokens.json`, `specs/RATING.md`, `specs/COPY.md`, `specs/DEPENDENCIES.md`, `specs/vectors/*.json` (parity vectors emitted by a new `firebreak.py --emit-vectors` flag: 50 seeds × {board, solution, clue grid, grade, deduction-step count}). *Acceptance:* schema validates sample content; `openapi.yaml` lints (`redocly lint`); vectors regenerate deterministically from seeds; owner sign-off recorded in `adr/0001-contract-freeze.md`. **2 sessions.**

**WS-02 Engine extraction (`packages/engine`)** — Port generator/uniqueness-oracle/deduction-oracle/witness-repair/grader/replay/puzzle-codes out of `index.html` into typed modules; zero runtime deps (CI-enforced). *Inputs:* `specs/engine-api.d.ts`, `specs/vectors/`. *Outputs:* `packages/engine/src/*`, `crosscheck.test.ts`. *Acceptance:* public API exactly matches the frozen `.d.ts` (`tsc` against it); all 50 vectors byte-identical to Python; 5×5 generation < 50ms in Node; `dependencies: {}`. **2 sessions.**

**WS-03 `packages/game-core`** — Framework-agnostic play state: marks/undo/redo, timer, coach escalation state (nudge→argument→resolution), win detection, replay driver, local persistence adapter, solve-record assembly (payload matching `openapi.yaml`'s `SolveSubmission`). *Inputs:* engine API, `specs/openapi.yaml`. *Outputs:* `packages/game-core/src/*` + unit tests. *Acceptance:* 90%+ line coverage on state machines; no DOM/React imports (lint boundary rule); serialized solve payload validates against OpenAPI schema. **1 session.**

**WS-04 `packages/ui-web` board** — Extract the prototype's DOM board + burn-replay animation + tap/long-press marking into a React component bound to game-core; reduced-motion step replay. *Inputs:* game-core, `specs/design-tokens.json`, `reference/index.html`. *Outputs:* `<Board>`, `<ReplayControls>`, `<CluePill>` etc. + Vitest DOM tests. *Acceptance:* pixel-comparable to prototype on a fixture puzzle (Playwright screenshot diff ≤ 2%); keyboard + screen-reader cell announcements per COPY.md. **1–2 sessions.**

**WS-05 Content pipeline productionization** — Restructure `firebreak.py` into `pipeline/` package: `generate → grade (v2 tiered-rules) → curate → sign (minisign/Ed25519) → emit`. Emits `content/v1/index.json`, `dailies/YYYY/MM.json`, `packs/academy-*.json`, spoiler-free share-card PNGs `cards/YYYY-MM-DD.png`, and a `puzzles.csv` seed for the DB registry. *Inputs:* `specs/puzzle-content.schema.json`, RATING.md (initial board-rating priors from grade). *Outputs:* `pipeline/`, `make content` target, 90 days of dailies + academy pack committed as fixture. *Acceptance:* every emitted puzzle passes uniqueness + deduction-only + witness checks (pipeline refuses otherwise); JSON validates against schema; signatures verify; deterministic given seed file. **2 sessions.**

**WS-06 Laravel scaffold + auth + GDPR base** — `services/api`: Laravel 12, Postgres, Sanctum cookie auth (register/login/logout/password reset via Fortify), migrations exactly matching `specs/db-schema.sql` (tables: `users`, `puzzles`, `solves`, `streaks`, `ratings`, `rating_history`, `daily_stats`, `entitlements`), Pint + PHPStan lvl 8, `.env.example`. GDPR: consentless-by-design (no trackers), data-export and account-delete jobs. *Inputs:* `specs/db-schema.sql`, `specs/openapi.yaml` (auth paths). *Outputs:* Laravel app, migrations, feature tests. *Acceptance:* `php artisan test` green; `migrate:fresh` matches schema spec (schema-dump diff empty); Sanctum flow passes a curl script; delete/export jobs tested. **2 sessions.**

**WS-07 Game API endpoints** — Implement `openapi.yaml`: `GET /api/v1/daily/{date}`, `GET /api/v1/daily/{date}/stats` (solve counter), `POST /api/v1/solves` (with the ~30-line PHP BFS validator re-checking the submitted break set against clues), `GET/PATCH /api/v1/me`, `GET /api/v1/me/streak`, `GET /api/v1/me/history`. Streak logic (Europe/Brussels day boundary, timezone-safe). *Inputs:* WS-06, `specs/openapi.yaml`, a vector fixture for the PHP BFS. *Outputs:* controllers, `BurnValidator.php`, feature tests, Spectator middleware validating responses against the OpenAPI file in tests. *Acceptance:* every endpoint's request/response validated against `openapi.yaml` in CI; PHP BFS agrees with engine on all 50 vectors; invalid solutions rejected with typed errors. **2 sessions.**

**WS-08 Fire Rating service** — Glicko-2, boards-as-opponents per `specs/RATING.md`: outcome from solved/failed + time percentile + hint count; `POST /solves` triggers rating update; nightly `ratings:recalibrate` command re-fits board ratings from aggregate solves; rating history endpoint. *Inputs:* RATING.md (frozen formulas + fixtures), WS-07. *Outputs:* `app/Domain/Rating/*`, scheduled command, fixture tests. *Acceptance:* reproduces RATING.md's numeric fixtures to 4 decimals; recalibration idempotent; rating never updates on unvalidated solves. **2 sessions.**

**WS-09 Web app shell + design system** — SPA skeleton: TanStack Router routes (`/`, `/daily/:date?`, `/endless`, `/academy`, `/academy/:lesson`, `/profile`, `/settings`, `/login`), tokens→CSS custom properties build step, dark "night incident map" layout, hub screen with the big Play button (unfinished daily → academy next → endless), auth-aware nav, API client **generated** from `openapi.yaml` via `openapi-typescript` (hallucination guard). *Inputs:* design tokens, COPY.md, openapi.yaml, WS-04. *Outputs:* `apps/web/src/*`, `api/client.ts` (generated, committed). *Acceptance:* Lighthouse a11y ≥ 95 on shell; initial JS ≤ 200KB gz; route smoke e2e passes; zero hand-written fetch paths (lint rule: only generated client). **2 sessions.**

**WS-10 Daily Burn Order + streaks + share cards** — Daily page: fetch signed daily JSON from CDN, verify signature, play, submit solve, win replay, streak flame UI, spoiler-free share (emoji-grid text + link to `burnfront.com/daily/YYYY-MM-DD`); Laravel route for that URL serves OG meta pointing at the pipeline PNG, then redirects/hydrates the SPA. *Inputs:* WS-04/07/09, content fixtures, COPY.md share strings. *Outputs:* daily feature code, `DailyShareController.php`, e2e test "solve today's daily as a fresh user". *Acceptance:* share URL unfurls correctly (OG meta test); no clue/solution data leaks in share text; offline replay of a solved daily works (PWA cache); streak survives a timezone-edge test. **2 sessions.**

**WS-11 Endless mode** — In-browser generation in a Web Worker (size + difficulty dials), progress UI for 7×7 (~5s), local history, rated-solve submission for signed-in users (server stores board hash + params; validation via replaying the seed in the PHP validator against submitted clues is *not* possible server-side — so endless solves are rated at reduced weight per RATING.md, or unrated in v1: RATING.md decides, agents implement). *Inputs:* engine, game-core, WS-09. *Outputs:* endless feature + worker. *Acceptance:* UI never blocks main thread > 50ms during generation; cancel/regenerate works; dials persist. **1 session.**

**WS-12 Academy** — Port the animated walkthrough; 6 lessons mapping the five deduction arguments (README's toolkit) + one exam board each, from the curated `academy-*.json` pack; completion tracked locally + synced. *Inputs:* content pack, COPY.md lesson scripts, WS-04. *Outputs:* academy feature, lesson content file. *Acceptance:* each lesson completable via e2e script; tutorial completion event recorded; reduced-motion variant exists. **2 sessions.**

**WS-13 Coach** — Progressive hints from the deduction oracle: nudge (highlight clue) → argument (human-readable reason from engine) → resolution (cell); hint count wired into solve payload + rating. *Inputs:* engine deduction API, game-core coach state, COPY.md hint voice. *Outputs:* coach UI + tests. *Acceptance:* for all 50 vector boards, coach can carry a no-input player to solved state; hint text renders engine reasons verbatim (no LLM text at runtime). **1 session.**

**WS-14 Accounts UI + GDPR self-service** — Login/register/reset screens, profile (rating graph, streak, history), settings (theme, reduced motion, delete account, export data), legal pages routes (`/privacy`, `/terms` — copy is owner-supplied). *Inputs:* WS-06/09, COPY.md. *Outputs:* account feature screens. *Acceptance:* full auth e2e loop; delete-account e2e actually removes rows (asserted via test API); no third-party requests anywhere (CSP report test). **1 session.**

**WS-15 Landing page** — Static marketing page at `/` for signed-out users: pitch, live demo board (5×5 fixture, playable inline), "provably fair" explainer, daily CTA; meta/OG/sitemap; the HN/press story baked in. *Inputs:* COPY.md, ui-web, tokens. *Outputs:* landing route + assets. *Acceptance:* Lighthouse perf ≥ 95 / SEO ≥ 95; demo playable without JS errors on mobile viewport; total page ≤ 300KB. **1 session.**

**WS-16 CI/CD + infra** — GitHub Actions: `ci.yml` (full gate §4 on every PR), `deploy.yml` (build SPA → artifact → Forge deploy hook; `content/` sync to Cloudflare R2 behind `cdn.burnfront.com`). Infra-as-notes: Hetzner CX22 + Laravel Forge + Cloudflare DNS/proxy + managed Postgres backup cron. Staging site `staging.burnfront.com`. *Inputs:* all package build commands; owner-provisioned accounts (§6). *Outputs:* workflows, `docs/RUNBOOK.md`, Forge deploy script. *Acceptance:* PR gate runs < 10 min; staging deploy from tag works end-to-end; rollback documented and rehearsed once. **2 sessions.**

**WS-17 E2E suite + budgets** — Playwright project: 5 smoke journeys (land→demo, register→solve daily→share, endless, academy lesson 1, coach-assisted solve), Lighthouse CI with budgets in `lighthouserc.json`, axe-core a11y scan per page. *Inputs:* WS-09..15 on staging build. *Outputs:* `e2e/`, CI wiring. *Acceptance:* suite green and < 5 min; budgets enforced as hard CI failures. **1 session.**

**WS-18 Ops & observability** — Nightwatch wired into `services/api` (exceptions, slow routes, scheduled tasks incl. `ratings:recalibrate` and daily-rollover), uptime check on `/api/health` + daily JSON freshness alert (today's daily must exist on CDN by 00:00 Brussels), Postgres nightly dump to R2, log retention per GDPR. *Inputs:* WS-16. *Outputs:* config, `RUNBOOK.md` sections, health endpoints. *Acceptance:* forced exception appears in Nightwatch; missing-daily alert fires in drill; restore-from-backup rehearsed on staging. **1 session.**

**WS-19 Privacy-clean analytics** — No third parties: a `events` table + `POST /api/v1/events` (batched, anonymous session id, no IP storage), tracked events: tutorial_step, daily_start/complete, hint_used, share_clicked; a weekly `analytics:digest` command emailing the owner KPI numbers (D1/D7 proxy, completion rate, hints/solve). *Inputs:* WS-06, PLAN.md KPI list. *Outputs:* migration, controller, digest command. *Acceptance:* zero external requests; events sampled correctly under ad-blockers (first-party path); digest renders. **1 session.**

**Workstream session subtotal: ~30.** Add lead-agent integration sessions (~8) and rework buffer (~7) → **~45 agent sessions for v1.**

---

## 2. Dependency DAG, parallelism, critical path

```
WS-00 ─► WS-01 ─┬─► WS-02 ─► WS-03 ─► WS-04 ─┬─► WS-09 ─┬─► WS-10 ─► WS-17 ─► launch
                │                             │          ├─► WS-11
                ├─► WS-05 (pipeline) ─────────┤          ├─► WS-12 ─► WS-13
                │        (content fixtures feed 10/12)   └─► WS-14
                ├─► WS-06 ─► WS-07 ─► WS-08              WS-15 (needs 01+04 only)
                └─► WS-16 (CI skeleton early; deploy legs after 06/09)
WS-18, WS-19 hang off WS-06/16, any time after.
```

**Hard sequential (contract → consumer):** WS-00→WS-01 before everything. WS-02 before WS-03 before WS-04. WS-06 before WS-07 before WS-08. WS-09 before WS-10/11/12/14. WS-05 before WS-10/WS-12 *content integration* (but WS-10/12 can build against committed fixtures earlier).

**Safe parallel lanes after WS-01 (max 4 concurrent worktrees):**
- Lane A (frontend core): 02→03→04→09→10→11
- Lane B (backend): 06→07→08
- Lane C (content): 05
- Lane D (independent): 15, 16-part-1; later 12→13, 14, 17, 18, 19

**Integration order:** 02+05 first (parity is the keystone), then 06/07, then 09, then features in any order, 17 last before launch hardening.

**Critical path:** WS-00 → WS-01 → WS-02 → WS-03 → WS-04 → WS-09 → WS-10 → WS-17 ≈ **12–13 sessions minimum**; everything else fits in its slack. The riskiest node is WS-02 (parity with Python) — schedule it immediately and let nothing downstream start until vectors are green.

---

## 3. Contract-first artifacts (written in WS-01, then FROZEN)

| File | Contents | Consumed by |
|---|---|---|
| `specs/engine-api.d.ts` | Exact public surface: `generate(seed, opts)`, `countSolutions`, `deduce()→{steps, reasons}`, `validate`, `replay`, `grade`, `encodePuzzle/decodePuzzle`, all types | WS-02/03/04/11/13 |
| `specs/puzzle-content.schema.json` | JSON Schema for dailies/packs: puzzle geometry, clues, N, grade, board-rating prior, solution hash (not solution), signature envelope, `index.json` manifest | WS-05/07/10/12 |
| `specs/openapi.yaml` | Every v1 endpoint, auth model, error envelope, `SolveSubmission` shape | WS-06/07/08/09/19 (client is *generated* from it) |
| `specs/db-schema.sql` | The 8 tables + indexes; `entitlements` present from day 1 (empty) so Pro needs no schema break | WS-06/07/08 |
| `specs/design-tokens.json` | Colors (night incident map), spacing, type scale, motion durations, burn-ramp palette (color-blind-checked) | WS-04/09/10/12/15 |
| `specs/COPY.md` | Voice guide + canonical strings: rules text, hint voice, share-card text, a11y announcements, empty states | all UI workstreams |
| `specs/RATING.md` | Glicko-2 parameters, outcome function, endless-mode weighting decision, numeric fixtures | WS-08, WS-07 |
| `specs/DEPENDENCIES.md` | Dependency allowlist (§6) | everyone, CI |
| `specs/vectors/*.json` | 50-seed Python↔TS parity vectors | WS-02/07, CI forever |

**Freeze rule:** after `adr/0001-contract-freeze.md`, no agent edits `specs/*` in a feature branch, ever (CI blocks diffs touching `specs/` unless the branch also adds an `adr/NNNN-*.md` and carries the label `contract-change`). A contract change requires: an ADR stating what/why/migration, lead-agent review, owner approval noted in the ADR. Consumers are updated in the same integration cycle, never left drifting.

---

## 4. Quality gates per merge

Every workstream branch must pass, in order (fail fast):

1. `pnpm -r typecheck` — TS strict, no `any` escapes (`@typescript-eslint/no-explicit-any` error).
2. `pnpm -r lint && pnpm format:check` — ESLint flat + Prettier; PHP: `pint --test` + `phpstan analyse --level=8`.
3. `pnpm -r test` + `php artisan test` — unit/feature suites; coverage floor: engine 95%, game-core 90%, others 70%.
4. **Cross-check vectors** — `pnpm crosscheck`: TS engine and (when touched) PHP validator vs `specs/vectors/`. Any mismatch is an automatic block, no exceptions.
5. Contract conformance — generated API client is fresh (`openapi-typescript` output diff-clean); Laravel responses validated against `openapi.yaml` (Spectator) in feature tests.
6. `pnpm e2e:smoke` — the 5 Playwright journeys against a local full stack (`docker compose up` Postgres + Laravel + built SPA).
7. Lighthouse CI — perf ≥ 90, a11y ≥ 95, SEO ≥ 90 (landing 95), initial JS ≤ 200KB gz; axe-core zero serious violations.
8. **Adversarial review-agent pass** — a separate reviewer session (not the author agent) runs `/code-review` at high effort with the workstream's acceptance criteria pasted in; must actively try to falsify each criterion (run the code, not just read it); findings CONFIRMED → back to author.
9. Hygiene sweep — CI greps: no `console.log`/`dd()`/`var_dump`, no `TODO|FIXME|HACK|XXX`, no `.skip`/`.only` tests, gitleaks secret scan, `packages/engine` `dependencies` still `{}`, no new deps outside `specs/DEPENDENCIES.md`.

**Escalation rule:** a gate failing **twice on the same branch** halts work. The author agent writes a `FAILED` entry in `tasks/WS-XX/STATUS.md` (symptom, hypothesis, what was tried). The lead triages into exactly one of: (a) spec is wrong → ADR + contract amendment; (b) task too big → split the brief into two; (c) approach wrong → new brief with an explicit constraint ("do not X"). **Never a third blind retry with the same brief** — that's how agents burn sessions polishing a wrong turn.

---

## 5. Session protocol

**Owner starts a session** (1–2h supervision window) with this template:

```
Lead: run workstream WS-10.
Brief: tasks/WS-10/brief.md   Status: tasks/WS-10/STATUS.md (resume from it)
Contracts: specs/openapi.yaml, specs/puzzle-content.schema.json, specs/COPY.md — FROZEN, read-only.
Work in a worktree branch ws-10-daily. Definition of done = brief's acceptance
criteria + full gate (§4). If a gate fails twice, stop and write FAILED per protocol.
Do not touch: specs/, reference/, other tasks/ dirs, package.json deps.
```

**Task ledger convention** — per workstream, `tasks/WS-XX/`:
- `brief.md` — written by the lead: scope, exact file paths to create, acceptance criteria as a checklist, explicit non-goals.
- `STATUS.md` — appended by the working agent at session end, machine-parsable sections: `## Done` (with commit SHAs), `## Remaining`, `## Blockers`, `## Decisions made` (anything not in the brief — the lead audits these), `## Files touched`, `## Resume instructions` (the exact next command/step). Rule: **a fresh agent with zero conversation history must be able to resume from STATUS.md alone.** Chat transcripts are not a handoff medium; the repo is.

**Handoff between sessions:** partial work is committed to the worktree branch (WIP commits fine), STATUS.md updated, branch pushed. Nothing lives only in an agent's context.

**Lead integration session** (every 3–4 completed workstreams): lead merges branches in DAG order into `main` one at a time, rerunning gates 1–6 after *each* merge (not just at the end); resolves conflicts itself for mechanical cases, spawns a fix-up subagent for semantic ones; updates `CODEMAP.md` with any new shared modules; writes `tasks/INTEGRATION-LOG.md` entry (what merged, what was rejected, why). Rejected branches go back with a fresh brief, never "fixed silently" by the lead — otherwise briefs stop being trustworthy.

**Verification separation:** the agent that builds never signs off its own acceptance criteria. Verification is either the review-agent pass (gate 8) or a dedicated verifier subagent that executes each criterion literally and reports pass/fail per line.

---

## 6. Guardrails for an AI-built codebase

- **Duplicate utilities:** shared logic may only live in `packages/engine` or `packages/game-core`; `CODEMAP.md` at repo root lists every shared module with a one-liner; `CLAUDE.md` rule: "before writing any helper, grep `CODEMAP.md` and `packages/`"; `eslint-plugin-boundaries` enforces the import DAG (apps→packages, never sideways between apps, engine imports nothing).
- **Style drift between parallel agents:** all style is mechanical — Prettier/Pint/ESLint are the arbiter, tokens file is the only source of colors/spacing (lint rule bans raw hex in `apps/`), COPY.md is the only source of user-facing strings for shared surfaces. Agents never "improve" formatting outside their diff (CI rejects branches whose diff touches files outside the brief's declared paths by >10%).
- **Hallucinated APIs:** the frontend can only call the API through the client *generated* from `openapi.yaml` — a nonexistent endpoint is a compile error. Same trick server-side: Spectator validates real responses against the spec in tests. For engine: `specs/engine-api.d.ts` is compiled against. Library hallucinations: gate 1 catches them; the deps allowlist stops "npm install to make it exist".
- **Secrets:** agents only ever see `.env.example` (committed, fake values) and a local-dev `.env` with throwaway credentials. Production secrets exist only in Laravel Forge's environment panel, set by the owner. CI secrets in GitHub Actions encrypted secrets, referenced never echoed. gitleaks in gate 9. `CLAUDE.md`: "if a task appears to need a real credential, stop and write a Blocker."
- **Dependency policy:** `specs/DEPENDENCIES.md` allowlist (initial: react, react-dom, @tanstack/router, vite + plugins, vitest, playwright, openapi-typescript, eslint/prettier toolchain; PHP: laravel/framework, sanctum, fortify, pint, phpstan, nightwatch agent). Adding one = ADR with justification + alternatives considered + supply-chain note; lead approves; CI diff-checks lockfiles against the list. `packages/engine` stays at zero runtime deps permanently.
- **Owner-only actions (humans keep the keys):** domain registration + DNS (do burnfront.com/.app/.io *now* — availability decays), Hetzner/Forge/Cloudflare/R2 accounts, GitHub repo settings + Actions secrets, Nightwatch account, OAuth app registration (when Google login lands), mail provider (Postmark/SES) signup, legal pages sign-off (GDPR privacy policy — owner is the data controller), production deploy button for the first month (CI deploys staging automatically; prod is owner-triggered `deploy.yml` dispatch), and later app-store accounts. Everything else is agent work.

---

## 7. Build schedule (solo owner, ~1–2 h/day supervising ≈ 1–2 agent sessions/weekday + integration)

| Phase | Content | Agent sessions | Calendar |
|---|---|---|---|
| P0 Foundations | WS-00, WS-01 (+ owner: register domains, GitHub, Forge/Hetzner/Cloudflare accounts) | 3 | Week 1 |
| P1 Core parity | WS-02, WS-03, WS-05, WS-06 in two parallel lanes + 1 integration | 8 | Weeks 2–3 |
| P2 Platform | WS-04, WS-07, WS-08, WS-09, WS-16 + 2 integrations | 11 | Weeks 3–5 |
| P3 Features | WS-10, WS-11, WS-12, WS-13, WS-14, WS-15 + 2 integrations | 12 | Weeks 5–7 |
| P4 Hardening | WS-17, WS-18, WS-19, full-suite bug-fix reruns, content top-up (365 dailies), staging soak | 8 | Weeks 7–8 |
| P5 Launch | Prod deploy, DNS cutover, smoke on prod, share-card check, HN/Reddit/PH per PLAN.md §5 | 3 | Week 9 |

**Total: ~45 sessions, ~9 calendar weeks** to public launch — consistent with PLAN.md's 5–6 week Phase 1 estimate only if the owner sustains 2 sessions/day; at a relaxed 1/day it's ~12 weeks. Buffer already included (rework ~15%). The schedule's binding constraint is owner review bandwidth, not agent throughput — resist running >4 parallel lanes; integration cost grows faster than lane count.

---

## 8. The first three sessions (start tomorrow)

**Session 1 — WS-00, repo restructure.** Single agent, main repo (no worktree needed yet). `git mv firebreak.py index.html README.md-content → reference/` (README stays at root, trimmed to point at docs); scaffold pnpm workspace + TS/ESLint/Prettier/Vitest config; create `specs/ adr/ tasks/ pipeline/ packages/{engine,game-core,ui-web} apps/web services/ e2e/` skeletons; write `CLAUDE.md` (agent rules from §6, gate list from §4, ledger protocol from §5) and empty `CODEMAP.md`; minimal `ci.yml` running gates 1–3 + 9. *Exit:* green CI on the skeleton, history preserved. Owner action same day: register the six domains, create the GitHub repo remote.

**Session 2 — WS-01a, behavioral contracts.** Add `--emit-vectors` to `reference/firebreak.py` (only permitted edit to reference/, ADR-noted) and generate `specs/vectors/` (50 seeds); write `specs/engine-api.d.ts` by transcribing the prototype's actual JS surface from `reference/index.html`; write `specs/puzzle-content.schema.json` + one hand-validated sample daily; draft `specs/DEPENDENCIES.md`. *Exit:* vectors regenerate deterministically; schema validates the sample; `.d.ts` compiles standalone.

**Session 3 — WS-01b, platform contracts + freeze.** Write `specs/openapi.yaml` (all §1 endpoints), `specs/db-schema.sql`, `specs/RATING.md` (including the endless-rated-or-not decision with fixtures), `specs/design-tokens.json` (extracted from the prototype's CSS), `specs/COPY.md` (rules text from README, hint voice, share text); lead reviews the whole pack for cross-consistency (solve payload ↔ DB ↔ rating inputs); owner reads and approves; commit `adr/0001-contract-freeze.md`; add the CI rule blocking `specs/` diffs without an ADR. *Exit:* contracts frozen — WS-02 (engine extraction, the critical path) and WS-06 (Laravel scaffold) can start in parallel worktrees the next day.