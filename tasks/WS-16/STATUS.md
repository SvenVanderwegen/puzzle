# WS-16 STATUS

## Session 2026-07-03 (builder)

## Done

- `efa313b` — WS-16: full §5 CI gates, deploy + content-publish pipelines, ops RUNBOOK.

Authored and validated (no live infrastructure exists — see Blockers):

- **ci.yml — full playbook §5 gate list.** Gate map is in the workflow header.
  New over the WS-00 skeleton: `changes` path-filter job (bash, fail-open —
  docs/tasks-only diffs skip heavy legs, contracts/ diffs can never skip
  anything, `contracts-guard` + `gate-9`/gitleaks run unconditionally);
  turbo-cached TS gates (`.turbo` via actions/cache, pnpm store via
  setup-node, `TURBO_FORCE=true` when contracts/ or root config changed
  because turbo cannot hash files outside package dirs); **php-gate** —
  shivammathur/setup-php 8.3 (pdo_pgsql/pgsql/redis/sodium), Composer files
  cache keyed on composer.lock, plain `composer install --prefer-dist`
  (standard on GH runners; the WS-06 git-source workaround is an
  agent-proxy-only quirk, documented in the workflow comment), scratch
  `postgres:16` service mapped to 127.0.0.1:55432 matching `api/phpunit.xml`,
  gates: `pint --test`, `phpstan` (level 9), `php artisan test` (pest incl.
  Spectator, BurnValidator vectors, schema-conformance; pg_dump 16 presence
  step for the latter); `e2e-smoke` gate-6 sentinel (green-but-empty; turns
  RED if e2e/ gains a suite while unwired); `deps-allowlist` step in gate-9
  (gate 9's "lockfile matches DEPENDENCIES.md" had no enforcement before);
  per-job timeouts; concurrency cancel for superseded non-main pushes.
- **deploy.yml.** Staging auto-deploys on push to `main` (or dispatch);
  production on `v*` tag behind GitHub environment `production` (owner =
  required reviewer) or dispatch from a tag ref with confirmation phrase
  `deploy production`. Flow: build SPA → `spa-<sha>.tar.gz` → R2
  `burnfront-deploy` → Forge deploy hook → server-side
  `scripts/forge-deploy.sh` (atomic release + symlink flip, `migrate
  --force`, config/route/view cache, Horizon restart, SPA extracted into the
  release's public/ — refuses any commit whose bundle is missing, so API and
  client cannot skew) → workflow polls `deploy.json` sha + `/up`. Forge
  deploys branches, not tags, so production is a machine-owned pointer branch
  force-pushed to the tag commit. Every external call is behind a preflight
  job that maps secrets to booleans (secrets context is unusable in job-level
  `if`); missing secrets print their NAMES as notices and the legs skip
  green. The SPA build job always runs, keeping the deploy unit provably
  buildable pre-provisioning.
- **content-publish.yml.** workflow_dispatch only (published dates are
  immutable): WS-05 pipeline `make content` with the signing key from
  `CONTENT_SIGNING_PRIVATE_KEY` written to a 0600 temp file → refuses
  unsigned/empty dist → additive `aws s3 sync` to `burnfront-content` (never
  `--delete`) → `php artisan content:import <manifest-url>` on staging via
  the Forge API (`scripts/forge-command.sh`). Fails with a clear message if
  dispatched before WS-05 lands. Same secrets-guard pattern.
- **scripts/** (all shellcheck 0.9.0 clean): `ci-changed.sh` (path filter),
  `deps-allowlist.sh` (manifests vs DEPENDENCIES.md; passes on current tree),
  `forge-deploy.sh`, `forge-rollback.sh` (previous-release flip with awk
  selection, tested against synthetic release dirs), `forge-command.sh`
  (Forge API run-command + poll), `wait-deployed.sh` (deploy.json/up poll,
  optional basic auth for staging).
- **docs/RUNBOOK.md** (new, written for a non-author operator): §1 overview;
  §2 infra — CPX31 Falkenstein via Forge, Redis AOF, production + isolated
  staging PG16 clusters (5432/5433) with memory caps, dedicated capped
  staging FPM pool (critique #31), nginx layout for Blade landing + SPA under
  /play from `current/public`, Cloudflare DNS/TLS Full (strict) with Origin
  CA, three EU R2 buckets with least-privilege tokens; §3 deploy — pipeline
  walkthrough, first-deploy bootstrap, Forge deploy-script paste block,
  verification, content publish/promotion; §4 rollback — symlink flip,
  redeploy-older-tag path, migrate:rollback caveats (expand/contract),
  content:rollback; §5 backups — pgBackRest → R2 with client-side cipher,
  nightly full + WAL (`archive_timeout=300` bounds RPO), RTO 4h/RPO 15min,
  quarterly restore-drill procedure, fresh-box disaster recovery. Unproven
  procedures carry explicit `REHEARSAL PENDING` markers.

Validation results (this session):

- YAML: all four workflows parse (`yaml.safe_load`); custom checker verified
  every `needs` target exists and every `needs.*.outputs.*` /
  `steps.*.outputs.*` reference resolves to a declared output/step id.
  actionlint unavailable here (binary downloads blocked by the session
  proxy; pip wheel build also blocked) — careful manual review done, run
  actionlint in a normal environment if in doubt.
- Path filter: synthetic-diff tests — docs-only skips heavy legs; contracts/,
  api/, workflow diffs run the right legs; unknown/zero base runs everything.
- `TURBO_FORCE` semantics proven locally: `false` → cache hit, `true` →
  bypass.
- Full gate command `pnpm exec turbo run typecheck lint test`: 15/15 tasks
  green; forced (uncached) full run 68s locally. `pnpm format:check`,
  `bash scripts/hygiene.sh`, `bash scripts/deps-allowlist.sh` all green.
- shellcheck clean on all six scripts; secret-pattern sweep of new files
  clean (gitleaks binary not installable here; CI runs the real thing).
- Flake note: on the very first cold fully-parallel turbo run,
  `@burnfront/web#test` failed once; three forced full re-runs were green and
  a direct run passed 309/309. Not reproduced, nothing to fix in-range —
  watch the first real CI runs (WS-17 owns test infra hardening).

## Remaining

- Everything under Blockers (owner provisioning), then the three rehearsals
  (staging end-to-end, rollback, restore drill) + updating the RUNBOOK
  `REHEARSAL PENDING` markers with dates/durations.
- Measure PR gate wall-time on real runners (acceptance: < 10 min). Local
  evidence says comfortably under: legs run in parallel; the longest are
  gates-1-3 (~1 min tasks + install, cached) and php-gate (composer cached
  ~1 min + pest suite).
- Verifier session for the brief acceptance checklist (builders do not
  self-certify).
- WS-17 replaces the `e2e-smoke` sentinel with the real compose-stack gate-6
  leg (its brief authorizes CI wiring) and adds Playwright browser caching.
- WS-05 integration: confirm content-publish.yml's dist→bucket layout,
  manifest object key, and `CONTENT_SIGNING_PRIVATE_KEY_PATH` env var name
  (both flagged in workflow comments).

## Blockers

**Owner provisioning — nothing below exists yet; every live acceptance item
is blocked on it.** In order (details per step in docs/RUNBOOK.md §2):

1. GitHub repo: rename default branch to `main` (deploy.yml triggers on it),
   enable branch protection requiring every ci.yml job as a status check —
   INCLUDING the path-filter `changes` job (if `changes` fails, the heavy legs
   report "skipped" and would satisfy required checks; requiring `changes`
   itself closes that fail-closed-to-skip route). Create environment
   `production` with the owner as required reviewer.
2. Hetzner Cloud account (EU billing).
3. Laravel Forge account connected to the repo; provision the CPX31 per
   RUNBOOK §2.2–2.6.
4. Cloudflare: `burnfront.com` zone, DNS per RUNBOOK §2.7, R2 enabled,
   buckets + tokens per RUNBOOK §2.8 (`burnfront-content`,
   `burnfront-deploy`, `burnfront-backups` — EU jurisdiction is
   creation-time only).
5. GitHub Actions repository secrets (Settings → Secrets and variables →
   Actions), exact names:

   | Secret | Value / where to find it |
   | --- | --- |
   | `FORGE_STAGING_DEPLOY_URL` | Forge → staging site → Deployment → Deployment Trigger URL |
   | `FORGE_PRODUCTION_DEPLOY_URL` | same, production site |
   | `FORGE_API_TOKEN` | Forge → Account → API tokens |
   | `FORGE_SERVER_ID` | numeric id in the Forge server URL |
   | `FORGE_STAGING_SITE_ID` | numeric id in the Forge staging-site URL |
   | `FORGE_PRODUCTION_SITE_ID` | same, production site (content promotion) |
   | `R2_ACCOUNT_ID` | Cloudflare dashboard account id |
   | `R2_ACCESS_KEY_ID` | CI publisher R2 token (RUNBOOK §2.8) |
   | `R2_SECRET_ACCESS_KEY` | CI publisher R2 token secret |
   | `R2_DEPLOY_BUCKET` | `burnfront-deploy` |
   | `R2_CONTENT_BUCKET` | `burnfront-content` |
   | `CONTENT_SIGNING_PRIVATE_KEY` | Ed25519 signing key contents (generate once when WS-05 lands; offline backup mandatory) |
   | `STAGING_BASIC_AUTH` | `user:password` of the staging HTTP basic auth (RUNBOOK §2.6 step 3) |

   Until these exist the deploy/content workflows run green-but-skipped and
   print exactly which names are missing.
6. On-box secrets (never GitHub): per-site `shared/.env` via Forge
   (RUNBOOK §2.6 step 4 + §3.2), per-site `.deploy-env` with the server
   bundle-reader R2 token (§3.2 step 5), `/etc/pgbackrest/pgbackrest.conf`
   with the pgBackRest R2 token and a cipher passphrase stored off-box
   (§5.1).
7. Then run: first staging deploy (§3.2), rollback rehearsal (§4.2), first
   restore drill (§5.4) — each currently `REHEARSAL PENDING` in the RUNBOOK.
   Results were NOT faked anywhere.

## Decisions made (lead: please audit)

1. **New GitHub Actions**: `shivammathur/setup-php@v2`, `actions/cache@v4`,
   `actions/upload-artifact@v4`, `actions/download-artifact@v4`. CI actions,
   not runtime dependencies; precedent: pnpm/action-setup and gitleaks-action
   already in the WS-00 skeleton. DEPENDENCIES.md governs package manifests
   and stays untouched.
2. **Path filtering with committed bash** (`scripts/ci-changed.sh`), not
   dorny/paths-filter — one less third-party action; fail-open to running
   everything; deny-list design so only docs/tasks-only diffs skip.
3. **Turbo in CI via `TURBO_FORCE` on contract/config diffs.** The clean fix
   is `"globalDependencies": ["contracts/**"]` in turbo.json, but turbo.json
   is outside WS-16's declared paths. One-line change; recommend the lead
   apply it and then the force-flag plumbing can be dropped.
4. **Added `scripts/deps-allowlist.sh` to gate-9** — playbook gate 9 requires
   "lockfile diff matches contracts/DEPENDENCIES.md" and nothing enforced it.
   Name-presence check of every direct dep in all package.json +
   api/composer.json against the allowlist text.
5. **Gate-6 sentinel** instead of a half-wired Playwright job: green while
   e2e/ has no suite, red the moment a suite lands unwired. WS-17's brief
   ("Outputs: CI wiring") authorizes the replacement.
6. **Production deploy shape**: tag push → environment approval; plus
   machine-owned `production` pointer branch force-pushed to the tag commit,
   because Forge deploys branches, not tags. Force is deliberate: dispatching
   an older tag is the production rollback path.
7. **SPA delivery**: CI-built tarball in R2 keyed by commit sha; the deploy
   script hard-refuses a commit without its bundle. Atomic releases are
   implemented by the committed `forge-deploy.sh` (Forge's script box only
   holds a 3-line bootstrap) rather than adding Envoyer.
8. **deploy.yml targets branch `main`** although today's default branch is
   the bootstrap session branch — activates when the owner renames (Blockers
   item 1). Deliberate: playbook §6 says merges land on main.
9. **Spectator vs OpenAPI 3.1 `prefixItems`** (flagged by the task): the pin
   is `hotmeteor/spectator` v3.0.2; WS-06 verified it actually validates
   against this contract including a negative probe. No issue observed, no
   downgrade, contract untouched. If the php-gate Spectator leg ever fails on
   a `prefixItems` shape, this pin is the first suspect — do not weaken the
   contract to route around it.
10. **hygiene.sh vendor exclusion** WS-06 requested from WS-16 was already
    present at HEAD (`--exclude-dir=vendor`); no change needed.
11. **Health/verification surface**: `forge-deploy.sh` writes
    `public/deploy.json` (sha, release, timestamp) per release; CI polls it
    plus Laravel's `/up`. Exposing the deployed sha is intentional and
    documented in the RUNBOOK.
12. **`archive_timeout = 300`** on production Postgres so a quiet night still
    meets RPO 15min (WAL segments otherwise only ship when full).

## Files touched

- `.github/workflows/ci.yml` (rewritten: path filter, caching, php-gate,
  e2e sentinel, deps-allowlist step; vectors.yml untouched)
- `.github/workflows/deploy.yml` (new)
- `.github/workflows/content-publish.yml` (new)
- `scripts/ci-changed.sh`, `scripts/deps-allowlist.sh`,
  `scripts/forge-deploy.sh`, `scripts/forge-rollback.sh`,
  `scripts/forge-command.sh`, `scripts/wait-deployed.sh` (new)
- `docs/RUNBOOK.md` (new)
- `tasks/WS-16/STATUS.md` (this file)

## Resume instructions

1. Nothing in this workstream can proceed until the owner completes the
   Blockers list above, in order. The RUNBOOK §2–§3 is the step-by-step.
2. After provisioning: push any commit to `main` → watch the deploy run; if
   legs skip, the preflight notices name the missing secrets. First deploy
   needs the §3.2 bootstrap done on the box first.
3. Execute the three rehearsals (RUNBOOK `REHEARSAL PENDING` markers), record
   date/duration/operator in the RUNBOOK, remove the markers, and check off
   the corresponding brief acceptance boxes.
4. Measure a real PR's gate wall-time (< 10 min acceptance). If over budget:
   the turbo and composer caches are the levers; check cache-hit lines in the
   job logs first.
5. Verifier session runs the brief acceptance checklist; this session
   deliberately did not self-certify or fake any rehearsal.

---

## Session 2026-07-03 addendum (builder — lead fix-up round, verdict MERGE-WITH-FIXES)

## Done

- `67177df` — all six lead/verifier fixes applied:
  1. RUNBOOK §2.2: `apt-get install awscli` replaced with the official AWS
     CLI v2 installer + GPG signature verification (no working awscli package
     on Ubuntu 24.04 "noble"; `forge-deploy.sh` hard-depends on `aws`).
  2. Branch-protection wording in RUNBOOK §2.1.4 and Blockers item 1: require
     every ci.yml job INCLUDING the path-filter `changes` job (closes the
     failed-filter → legs-report-skipped → required-checks-satisfied route).
  3. ci.yml header comment corrected: contracts/ runs every CONSUMER leg;
     reference-selftest keys on reference/, scripts/ and CI config (matching
     ci-changed.sh comments too).
  4. ci-changed.sh hardening: `scripts/` added to the `reference` and
     `contracts_or_config` classes — edits to the gate/filter scripts force a
     full run.
  5. forge-command.sh: poll curl guarded; transient Forge API blips retry
     within the existing deadline instead of failing the run.
  6. RUNBOOK nits: §2.3 cross-ref → §2.6 step 4; quick-deploy OFF documented
     for the production site too (double-deploy race with the pointer-branch
     force-push).

Re-validation after the fixes:

- All four workflows `yaml.safe_load` clean; prettier clean; hygiene +
  deps-allowlist green; shellcheck clean on all six scripts.
- Path-filter simulations rerun with the new patterns: scripts-only and
  filter-itself diffs now set all four outputs true (full run); docs-only
  still skips; contracts/, api/, reference/, workflow classes unchanged and
  correct. Real-diff runs (87f2447..HEAD, zero-base) both run everything.
- Forced full gate runs: 4 executed this round — 3 fully green (60–75s),
  1 hit the web test flake below.

## FAILED (gate 3 — `@burnfront/web#test`, intermittent, pre-existing; lead triage requested)

- **Symptom**: under cold, fully-parallel forced turbo runs (all 5 vitest
  suites concurrently), `@burnfront/web#test` fails intermittently — 2
  failures in ~8 such runs this session. Captured signature:
  `Timeout.checkRealTimersCallback` in `@testing-library/dom` `wait-for.js`,
  i.e. a real-timer `waitFor` exceeding its timeout. Never fails solo, never
  fails in a direct `pnpm test` (309/309), never fails cached.
- **Hypothesis**: a timing-sensitive `waitFor` in an apps/web test breaches
  its default timeout under CPU contention. CI runners (2 cores) will see the
  same contention profile inside the gates-1-3 job; expect occasional red.
- **What was tried**: 8 forced full-parallel runs to characterize (2 red,
  6 green); solo re-runs of the web suite (always green); direct suite run
  (green, full coverage). Not a third blind retry of a broken gate — the
  failures are load-dependent and the exact test name is not deterministic to
  capture (the failing run's log was truncated by turbo's grouped output).
- **Not caused by this branch**: the WS-16 diff contains zero TS/product
  changes (workflows, scripts, docs, tasks only); the first occurrence was on
  an untouched working tree.
- **Suggested triage**: (c)-shaped — apps/web is outside WS-16's paths; the
  fix (raise the offending `waitFor` timeout or fake timers) belongs to the
  lead or WS-17's test-infra hardening. Until then, a rare gates-1-3 red that
  greens on re-run has this signature.

## Files touched (addendum)

- `.github/workflows/ci.yml`, `scripts/ci-changed.sh`,
  `scripts/forge-command.sh`, `docs/RUNBOOK.md`, `tasks/WS-16/STATUS.md`

## Resume instructions (unchanged)

- Owner provisioning checklist above, then the three rehearsals. The lead has
  the flake triage request; everything else from the fix list is applied and
  validated.
