# WS-18 STATUS

## Session 2026-07-03 (builder)

## Done

- `57756e5` — WS-18: ops & observability. Everything below is authored,
  tested and executable-on-day-one; the three staging acceptance items are
  REHEARSAL PENDING (see Blockers — no Nightwatch account, no staging, no
  CDN exist yet; nothing was faked).

Delivered, verified in-session:

- **Nightwatch, config-ready**: `laravel/nightwatch` v1.28.4 installed via
  composer (it IS on `contracts/DEPENDENCIES.md` — ADR-0010/WS-18 row — so
  no ADR was needed). Config published to `api/config/nightwatch.php` and
  annotated: request payloads never captured (magic-link emails, replays),
  cookie/XSRF headers redacted, sampling 1.0 with the env dial documented.
  Local/CI/tests run disabled (`.env.example`, `phpunit.xml` already had
  `NIGHTWATCH_ENABLED=false`). Owner wiring (token, per-site env, agent
  daemons, staging ingest port 2408) is RUNBOOK §6.2; the six alert
  definitions to click into the Nightwatch UI (incl. the brief's
  POST /solves p95 > 200ms/5min, queue lag > 60s, scheduled-task misses,
  every-occurrence alert on the OpsAlert group) are RUNBOOK §6.3.
- **Health surface closed to contract**: `GET /api/v1/health` now implements
  the openapi 503 degraded path — database unreachable returns
  `{"error":{"code":"degraded",...}}` and `report()`s the failure (Nightwatch
  sees it); 200 shape (`ok` + `tomorrow_published`) unchanged. Verified live:
  `php artisan serve` with a dead DB port returned the 503 envelope; Feature
  test simulates DB loss via transaction-local empty `search_path` and
  asserts status, Spectator conformance and the report().
- **`ops:content-freshness`** — scheduled 22:00 UTC (T-2h, critique #17):
  alerts when tomorrow (UTC) has no `daily_puzzles` row (row exists iff a
  signed calendar was imported+published; import verifies CDN files first,
  so no separate CDN probe). Tests freeze time at the scheduled moment and
  cover fire / silent / today-doesn't-count.
- **`ops:content-runway`** — scheduled 08:30 UTC daily: alerts when fewer
  than 21 CONSECUTIVE future days are covered (a calendar gap ends the
  runway — the first hole is where players hit nothing). Boundary tested:
  exactly 21 silent, 20 fires; gap test; `--min` override for staging drills
  (also the RUNBOOK §6.2 forced-exception probe: `--min=9999`).
- **Alert convention** (one incident, three sinks, notifier wiring is config
  not code): CRITICAL line on the new `ops` log channel
  (`OPS_LOG_CHANNELS`, default `stack`; a Slack notifier later =
  `OPS_LOG_CHANNELS=stack,slack` + webhook) + `report(App\Domain\Ops\OpsAlert)`
  (grouped in Nightwatch once the token exists) + non-zero exit (scheduler
  marks the run failed → Nightwatch scheduled-task monitoring / any cron
  monitor). Implemented in `RaisesOpsAlerts` trait.
- **`ops:daily-amnesty {date} {--revoke}`** — the executable step of the
  pull-a-daily procedure. Sets `daily_puzzles.amnesty`; WS-07 already honors
  it everywhere (streak walk, rollover events, risk-mail sweep, GET /daily).
  Audit line to the ops channel. Tests: set/revoke/unknown-date/malformed +
  client-visible `amnesty:true` through GET /daily with Spectator.
- **RUNBOOK operational sections** (appended §6–§11, quick reference
  renumbered to §12; WS-16's §1–§5 untouched apart from a one-line WS-18
  status note at the top): §6 monitoring (what is wired in code, owner
  wiring, alert definitions, external uptime check spec, scheduled-task
  watchlist with what-a-miss-means), §7 pull-a-daily (Case A repoint ≥48h /
  Case B live-day amnesty — see Decisions 2), §8 CDN-down
  (`CONTENT_ORIGIN_FALLBACK` flip + config:cache + verify + revert), §9
  breach notification (72h from awareness, Belgian DPA/Gegevensbescher-
  mingsautoriteit, Art. 33(4)/33(5)/34 steps, aligned with docs/gdpr.md),
  §10 log retention (every log's location + TTL + enforcement, aligned with
  gdpr.md's table), §11 quarterly restore-drill calendar + drill log
  (procedure stays WS-16's §5.4).
- **Gates** (final run this session): `php artisan test` — **278 passed
  (3437 assertions)** (264 before; 14 new). `pint --test` passed.
  `phpstan analyse` level 9, no errors. `bash scripts/hygiene.sh` exit 0.
  `bash scripts/deps-allowlist.sh` green (nightwatch is allowlisted).
  `php artisan config:cache` round-trip OK (forge-deploy runs it).
  `format:check` not applicable (only `api/` + `*.md` touched; both
  prettierignored). Live smoke: schedule:list shows both ops entries;
  freshness/runway/amnesty exercised against the scratch DB; /health 200
  and 503 both curl-verified against `artisan serve`.

## Remaining

- The three brief acceptance items that need real infrastructure — all
  REHEARSAL PENDING, procedures written and waiting in the RUNBOOK:
  1. Forced exception appears in Nightwatch (staging) — §6.2 probe.
  2. Missing-tomorrow drill + pull-a-daily rehearsal incl. amnesty behavior
     (staging) — §6.1/§7.
  3. CDN-down drill with clients kept playing (e2e, staging) — §8; ALSO
     blocked on WS-10's daily play surface (no client consumes
     `content_url`/`puzzle` yet — verified, `apps/web` daily page is a stub).
- Verifier session for the brief acceptance checklist (builders do not
  self-certify).
- RUNBOOK §6.2 note: confirm the two-agents-two-ports Nightwatch daemon
  topology against current Nightwatch docs at wire-up time.

## Blockers

**Owner actions — none of these can be done by an agent (accounts/secrets):**

1. **Nightwatch account**: create the application at nightwatch.laravel.com
   (environments `staging` + `production`), obtain `NIGHTWATCH_TOKEN`, set
   the per-site Forge env per RUNBOOK §6.2 (staging adds
   `NIGHTWATCH_INGEST_URI=127.0.0.1:2408`), add one `nightwatch:agent`
   daemon per site in Forge, then click in the six alert definitions of
   RUNBOOK §6.3. Code side is fully installed; this is env + UI only.
   This item extends the WS-16 owner checklist (tasks/WS-16/STATUS.md).
2. **Uptime-check provider**: pick one (needs external GET checks and,
   ideally, heartbeat monitors for the §5.2 pgBackRest cron — spec in
   RUNBOOK §6.4). If Nightwatch itself offers uptime checks at wire-up
   time, that satisfies this too; decide then.
3. **Staging rehearsals**: run the three REHEARSAL PENDING drills above
   once staging exists (after WS-16's blockers 1–7), record date/operator
   in the RUNBOOK, remove the markers.

## Decisions made (lead: please audit)

1. **Added `ops:daily-amnesty`** beyond the brief's named commands: the
   pull-a-daily procedure must be executable by a non-author, and the only
   alternative was documenting raw SQL against production. Thin command,
   tested, ops-channel audit line.
2. **Pull-a-daily truth vs critique #16**: WS-07's T-48h immutability
   (critique #32) means a LIVE day's board cannot be repointed —
   `content:import` refuses it. RUNBOOK §7 therefore splits: Case A
   (date ≥ 48h out) = repoint via replacement content_version; Case B
   (live/inside window) = amnesty + notice, board stands, and re-publishing
   a corrected doc under the SAME puzzle id is explicitly forbidden (cached
   CDN copies, split stats, tripwire noise). If the lead wants live-day
   replacement, that is a WS-07 change (repoint override) + ADR — flagging,
   not doing.
3. **In-app pulled-notice gap**: `GET /daily/{date}` `amnesty:true` is the
   only client-visible signal. A visible banner needs a `contracts/COPY.md`
   key (contract change → ADR) and WS-10's daily surface. Proposed
   follow-up for the lead/WS-10; RUNBOOK §7 states the gap truthfully so
   nobody promises players a banner that does not exist.
4. **Runway definition**: consecutive covered days starting tomorrow; a gap
   ends the runway even if later dates are covered. Boundary: exactly 21 is
   silent, alert strictly below. `--min` option exists for drills only.
5. **Runway schedule 08:30 UTC** (brief said daily, time unspecified):
   owner daytime — it requests content generation, not a night scramble.
6. **Alert plumbing**: new `ops` log channel in `config/logging.php`
   (env `OPS_LOG_CHANNELS`, default `stack`) + `App\Domain\Ops\OpsAlert`
   reported exception + exit-code convention. Chosen so "wire a real
   notifier" is one env change and Nightwatch needs zero code.
7. **Health 503 = database probe only**: Redis/queue/scheduler health is
   Nightwatch's job (RUNBOOK §6); probing Redis from /health would make the
   uptime check page on a degradation that does not stop play. Error code
   string `degraded` chosen (contract fixes the envelope shape, not codes —
   same posture as WS-06 decision 7).
8. **No CDN HTTP probe in ops:content-freshness**: a `daily_puzzles` row
   exists only after `content:import` verified every file (which runs after
   the R2 sync), so DB presence implies the board reached the CDN;
   CDN availability itself is §6.4/§8 territory.
9. **Log-retention hardening documented, not silently provisioned**:
   production/staging must run `LOG_CHANNEL=daily` + `LOG_DAILY_DAYS=14`
   (the `single` default never rotates). Stated in RUNBOOK §10 with a note
   to fold into §2.6 step 4's env table — that table is WS-16's section, so
   folding it in is proposed to WS-16/lead rather than edited by me.
10. **RUNBOOK §12 quick-reference updated** (renumbered from §6, stale
    "Alerts/monitoring — not wired yet" row replaced, four rows added).
    One WS-18 status-note line added under WS-16's at the top. No other
    WS-16 text changed.
11. **No CI workflow needs**: no alert requires a GitHub workflow; nothing
    proposed to WS-16 beyond Decision 9.

## Files touched

- `api/app/Console/Commands/{OpsContentFreshness,OpsContentRunway,OpsDailyAmnesty}.php` (new)
- `api/app/Console/Commands/Concerns/RaisesOpsAlerts.php` (new)
- `api/app/Domain/Ops/OpsAlert.php` (new)
- `api/app/Http/Controllers/Api/V1/HealthController.php` (503 degraded path)
- `api/config/nightwatch.php` (new — published + annotated), `api/config/logging.php` (ops channel)
- `api/routes/console.php` (two ops schedules)
- `api/composer.json`, `api/composer.lock` (laravel/nightwatch ^1.28)
- `api/.env.example` (Nightwatch + OPS_LOG_CHANNELS blocks)
- `api/tests/Feature/HealthTest.php` (degraded test), `api/tests/Feature/Ops/{ContentFreshnessTest,ContentRunwayTest,DailyAmnestyTest}.php` (new)
- `docs/RUNBOOK.md` (§6–§11 appended, quick reference → §12, WS-18 status note)
- `tasks/WS-18/STATUS.md` (this file)

## Resume instructions

1. Environment: scratch Postgres 16 on `127.0.0.1:55432` (user postgres,
   trust) with db `burnfront_test` — recipe in
   `api/tests/schema-conformance.sh` header; may already be running.
   `cd api && composer install` (this sandbox installs from git sources —
   tasks/WS-06/STATUS.md decisions 6/10; normal environments use the lock).
2. Gates: `php artisan test` (expect 278) · `vendor/bin/pint --test` ·
   `vendor/bin/phpstan analyse` · `bash scripts/hygiene.sh` and
   `bash scripts/deps-allowlist.sh` from repo root.
3. Nothing further is buildable in this workstream without the owner
   Blockers above. When staging + Nightwatch exist: RUNBOOK §6.2 wiring,
   then the three rehearsals (§6.2 probe, §7, §8), record results in the
   RUNBOOK and strike the REHEARSAL PENDING markers.
4. A separate verifier session executes the brief acceptance checklist; the
   three staging boxes cannot pass until the rehearsals run.
