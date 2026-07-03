# WS-19 STATUS — first-party analytics + error beacon + weekly digest

## Session 2026-07-03 (builder)

## Done
Commit `2d309a6` on `worktree-agent-a3bd56de1eabb2d40` (ledger commit follows it).

- **POST /api/v1/events** (`recordEvents`, `security: []`): contract shape via
  validator (anon_id 8–64, events 1–25, name in enum, ts RFC 3339, props ≤12
  keys) plus per-name typed props validation in
  `api/app/Domain/Analytics/EventCatalog.php` — every declared prop required,
  undeclared/mis-typed rejected, 422 before any DB write.
  `additionalProperties: false` enforced against the raw JSON body (Laravel's
  validator only strips unknown keys). user_id attached from the SPA session
  when present (`$request->user()`), null otherwise. Batch lands in ONE
  multi-row INSERT (`EventRecorder`); client `ts` clamped to
  [received−48h, received]. Bodiless 202. Throttle `events`: 60/min keyed by
  anon_id (IP only as fallback key for junk requests; never stored).
- **POST /api/v1/errors** (`recordFrontendError`, `security: []`): PII scrub
  before storage (`PiiScrubber`: emails, `Bearer` credentials, `token=` query
  params → placeholders), scrub-then-truncate to caps 2000/8000/200. The
  contract declares only 202/429 for this op, so malformed beacons (missing/
  non-string/blank message) return 202 with no row; oversized fields are
  truncated, not rejected. Throttle `frontend-errors`: 10/min by session id,
  IP fallback for cookieless clients.
- **Retention**: `analytics:purge` scheduled daily 03:20 —
  `AnalyticsRetention` deletes frontend_errors >90d and aggregates-then-purges
  events raw rows past a whole-calendar-month 13-month boundary into rollup
  rows in `events` itself: `name = _rollup.<name>`, `anon_id = _system`,
  props = {month, count, distinct_anon_ids} + per-name extras
  (solve_complete: median_ms/sum_hint_stages/first_count; board_abandoned:
  median_ms; replay_watched: median_fraction). Transactional + idempotent;
  rollups excluded from purge by namespace, never by age. WS-06's
  `retention:purge-events` (whose aggregation seam said "WS-19 replaces this")
  and `retention:purge-frontend-errors` now delegate to the same service and
  remain for manual runs, unscheduled (no double runs).
- **Weekly digest**: `analytics:digest` scheduled Mondays 06:10 —
  `WeeklyDigest` compiles activation, median time-to-first-solve, D1/D7
  (complete-day cohorts), completion by weekday, hint stages per solve, day-3
  conversion (72h from the first_seen event), share rate, top-5 frontend
  errors, over the 7 complete UTC days before the run; rollup/_system rows
  excluded from all metrics. Rendered as a plain-text Mailable
  (`WeeklyDigestMail`, `resources/views/mail/analytics-digest.blade.php`,
  incident-report voice) to `config('analytics.owner_digest_email')`
  (env OWNER_DIGEST_EMAIL, fake value in .env.example). Missing address →
  command fails loudly, nothing sent.
- **Landing counter**: `api/config/landing.php` created with
  `live_counter => (bool) env('LANDING_LIVE_COUNTER', true)` — flipped on.
  Tests cover: default-on live count at ≥500 (`500 crews have contained…`),
  rank fallback at 499 with flag on, rank when flag forced off. No new
  endpoint — the landing stays server-rendered off daily_stats.
- **docs/gdpr.md** (new): Art. 30 inventory, processors (Hetzner Falkenstein;
  Cloudflare/R2 under DPF+SCC fallback; EU ESP placeholder pending WS-21;
  Forge noted), retention table (events 13mo→permanent rollups incl. the
  `_rollup.*`/`_system` namespace explanation; frontend_errors 90d;
  replay/ip_hash/ua_hash 90d; magic-link 15min; backups), anon-id/ePrivacy
  posture per decisions.md #7, beacon scrub documentation, delete=anonymize
  semantics (why the orphaned events.user_id is anonymous), 72h breach note
  (owner is controller, Belgian DPA).
- **Gates** (run from api/): `php artisan test` — 203 passed (2890
  assertions; baseline before WS-19 was 174); `vendor/bin/pint --test` —
  clean; `vendor/bin/phpstan analyse` — level 9, 0 errors, no baseline file
  exists and none added; `bash scripts/hygiene.sh` — exit 0. Spectator
  conformance asserted for both new endpoints (202/422/429 on /events,
  202/429 on /errors, plus assertValidRequest on happy paths).
- **Abuse tests** (tests/Feature/Analytics/RecordEventsTest.php,
  RecordFrontendErrorTest.php): 61st batch/min → 429 with rows still at 60;
  26-event batch → 422; bad name, reserved `_rollup.*`/`_system` names,
  unknown/mis-typed/missing props, 13-key props, 100KB string prop, bad
  anon_id bounds, malformed ts, unknown top-level/event keys → all 422 with
  zero rows written; single-INSERT assertion via DB::listen; ts clamp
  asserted; 11th error beacon/min → 429, per-key isolation asserted for both
  limiters.
- **D1/D7 hand-verified** (tests/Feature/Analytics/DigestTest.php): 8-user
  fixture cohort, every expected number derived by hand in test comments
  (activation 2/4, median TTFS 3600s, D1 3/5, D7 2/4, day-3 1/5, share 1/4,
  hints avg 1.00, weekday lines, top errors 3×/1×).

## Remaining
- Verifier session: adversarial pass over the brief acceptance checklist
  (builders do not self-certify).
- Frontend beacon client module (anon-id issuance, batching, sendBeacon) —
  deferred out of this brief by lead ruling; the endpoints and CSRF exemption
  are ready for it.
- WS-21: replace the EU ESP placeholder in docs/gdpr.md §Processors and sign
  the DPA before first production send.
- CSP/zero-external-request posture unchanged (nothing new calls out; mail
  goes through the configured mailer only) — verifier should confirm the
  existing CSP test still covers it.

## Blockers
- None.

## Decisions made (lead: please audit)
1. **`analytics:purge` replaces the two WS-06 retention entries in the
   schedule** (03:20 daily); `retention:purge-events` /
   `retention:purge-frontend-errors` still exist and delegate to the shared
   `AnalyticsRetention` service, but are unscheduled to avoid double runs.
   WS-06's RetentionPurgeTest schedule assertion updated accordingly.
2. **Whole-calendar-month purge boundary** for events: a rollup per (month,
   name) must be written exactly once from complete data, so rows are deleted
   at 13 months + at most one partial month. Documented in gdpr.md.
3. **Rollup `created_at` = first day of the summarized month**; exclusion
   from purge is by namespace (name/anon_id), never by age.
4. **Client `ts` stored as `created_at`, clamped to [now−48h, now]** — keeps
   funnel gaps real across batched flushes while preventing backdating past
   retention windows or future-dating into immortality. (The contract table
   has no separate ts column.)
5. **Declared props are required**, not just allowed — each event's props is
   a closed record (missing prop → 422). Keeps digest math trustworthy and
   free-form payloads impossible. Ranges: hint_stages 0–3, stage 1–3,
   fraction 0–1, ms ≤ 7 days, counters ≤ 100k, puzzle_id ≤ 64 chars,
   tutorial n 0–100.
6. **/errors never returns 422** — the contract declares only 202/429 for
   recordFrontendError, and Spectator would fail an undocumented status. So:
   unusable message → 202 without a row; oversized fields → scrub then
   truncate to caps.
7. **CSRF exemption for /events and /errors** (bootstrap/app.php):
   navigator.sendBeacon cannot attach the XSRF header. Both are anonymous
   fire-and-forget writes; the session cookie is SameSite=Lax so cross-site
   POSTs carry no session.
8. **PiiScrubber also redacts `token=` query params** (beyond the ruled
   emails + bearer tokens): magic-link consume URLs land in beacon routes and
   are live credentials for 15 minutes.
9. **Throttle keys**: events keyed strictly by anon_id when plausible
   (8–64 chars), IP-keyed fallback otherwise; errors keyed by session id,
   IP fallback. IPs never persisted.
10. **`config/analytics.php` + `config/landing.php`** created (existing
    burnfront.php is WS-07 content config; landing flag was read by WS-15's
    LandingController from the `landing.` namespace).
11. **New env keys in .env.example**: OWNER_DIGEST_EMAIL (fake),
    LANDING_LIVE_COUNTER (default true in config anyway).
12. **Digest cohort windows**: D1/D7 graded only on complete days
    (first_seen day + k ≤ yesterday); day-3 conversion = account_created
    within 72h of the first_seen event timestamp; activation = solve_complete
    on the first_seen UTC day. All windows documented in WeeklyDigest's
    docblock and pinned by the fixture test.

## Files touched
- `api/app/Domain/Analytics/{EventCatalog,EventRecorder,PiiScrubber,AnalyticsRetention,WeeklyDigest}.php` (new)
- `api/app/Domain/Analytics/Mail/WeeklyDigestMail.php` (new)
- `api/app/Http/Controllers/Api/V1/{EventController,FrontendErrorController}.php` (new)
- `api/app/Console/Commands/{AnalyticsPurge,AnalyticsDigest}.php` (new)
- `api/app/Console/Commands/{PurgeEvents,PurgeFrontendErrors}.php` (delegate to AnalyticsRetention)
- `api/app/Providers/AppServiceProvider.php` (events + frontend-errors limiters)
- `api/routes/api.php` (two routes), `api/routes/console.php` (schedule),
  `api/bootstrap/app.php` (CSRF exemption)
- `api/config/{analytics,landing}.php` (new), `api/.env.example`
- `api/resources/views/mail/analytics-digest.blade.php` (new)
- `api/tests/Feature/Analytics/{RecordEventsTest,RecordFrontendErrorTest,AnalyticsPurgeTest,DigestTest}.php` (new)
- `api/tests/Feature/Retention/RetentionPurgeTest.php` (rollup-aware
  aggregation test; schedule assertion)
- `api/tests/Feature/Landing/LandingPageTest.php` (flipped-on counter tests)
- `docs/gdpr.md` (new)
- `tasks/WS-19/STATUS.md` (this file)

## Resume instructions
1. Scratch Postgres 16 on `127.0.0.1:55432` (user postgres, trust), database
   `burnfront_test` — recipe in `api/tests/schema-conformance.sh` header;
   `pg_isready -h 127.0.0.1 -p 55432` may show it already running.
2. `cd api && composer install` (this environment needs git-source installs —
   tasks/WS-06/STATUS.md decisions 6/10), `.env` from `.env.example`,
   `php artisan key:generate`.
3. Gates: `php artisan test` (203) · `vendor/bin/pint --test` ·
   `vendor/bin/phpstan analyse` · `bash scripts/hygiene.sh` (repo root).
4. Next: a separate verifier session executes the brief acceptance checklist
   adversarially; the abuse/digest/retention coverage map is in Done above.
   Then the frontend beacon client (separate ruling/brief) can consume
   POST /api/v1/events and /errors as shipped.
