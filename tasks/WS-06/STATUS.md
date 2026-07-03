# WS-06 STATUS

## Session 2026-07-03 (builder)

## Done
- `325f67ce5877ece9dcc7d4ed86b01696178219de` — WS-06: Laravel scaffold, magic-link auth, GDPR base (the whole `api/` app).
- `aceab4090e346ecc24ab71a21d7732ce4081d448` — trim remaining frontend skeleton files from `api/`.

Delivered, all verified in-session:
- **Scaffold**: `composer create-project laravel/laravel api` → Laravel **13.18.1**
  (latest stable; see Decisions), trimmed to API shape. Composer deps: framework,
  sanctum ^4.3, horizon ^5.47 (config only) + dev: pint, larastan ^3.10, pest ^4.7,
  spectator ^3.0, mockery ^1.6 (see Decisions).
- **Migrations**: contract tables in `api/database/migrations/contract/` (six
  migrations, SQL verbatim from `contracts/db-schema.sql`, registered via
  `AppServiceProvider::loadMigrationsFrom`); Laravel framework tables (cache, jobs)
  stay in the default group. `api/tests/schema-conformance.sh` loads the contract
  into DB A, `migrate:fresh --path=database/migrations/contract` into DB B, drops
  the `migrations` bookkeeping table, then diffs normalized
  `pg_dump --schema-only --no-owner --no-privileges` output. **Diff empty.**
  Also wrapped as a Pest test (`tests/Feature/SchemaConformanceTest.php`).
- **Magic-link auth (ADR-0003)**: `POST /api/v1/auth/magic-link` (constant 202,
  token sha256 at rest, 64-hex raw token, 15-min TTL, throttles 3/h/email-across-IPs
  + 5/min/IP), `POST /api/v1/auth/magic-link/consume` (410 invalid/expired/used;
  atomic single-use claim; creates users + auth_identities on first consume; session
  regenerated; 5/min/IP throttle), `POST /api/v1/auth/logout`. Sanctum stateful SPA
  mode (`statefulApi()`, cookie `burnfront_session`). Mail = mailable
  `MagicLinkMail` asserted via `Mail::fake` in tests; smtp/mailpit in `.env.example`.
- **/me (contract shapes)**: GET (profile + streak/rating summaries with defaults),
  PATCH (timezone + streak_alert_opt_in only; other fields ignored), DELETE (queued
  `AnonymizeUser`: email/handle/country nulled, timezone reset to 'UTC' — column is
  NOT NULL — anonymized_at stamped, ratings+streaks rows deleted, rating_events
  disowned, auth_identities + pending tokens deleted, solves kept with user_id
  NULL; session ended; 202). `GET /me/export`: queued `ExportUserData` → JSON file
  (local disk) + signed URL 24 h + email; download route `exports.download` requires
  valid signature **and** live session of the same user; file deleted after first
  download; 3/h/user throttle.
- **Retention**: `retention:purge-solve-artifacts` (90 d: replay/ip_hash/ua_hash
  nulled, rows kept), `retention:purge-frontend-errors` (90 d delete),
  `retention:purge-events` (13 months aggregate-then-delete; aggregation is a
  documented no-op until the WS-19 digest exists). Scheduled daily in
  `routes/console.php`; Pest tests use time travel.
- **Domain layout**: `app/Domain/{Auth,Solves,Ratings,Streaks,Content}`;
  controllers thin. Arch tests: Domain never uses `Illuminate\Http`; only
  `Domain\Content` (ExportFileStore) uses Storage; Rating/BoardRating/RatingEvent
  models only used in `Domain\Ratings`.
- **Spectator** wired to `../contracts/openapi.yaml` (path set in `tests/TestCase`,
  `path_prefix` = `api/v1`); auth + /me responses validated incl. 401/429 envelopes.
  Verified it really validates via a negative probe (removed `plan` from the Me
  payload → schema failure as expected, then restored).
- **Gates** (final run, this session):
  - `php artisan test` — **46 passed (227 assertions)**, includes schema-conformance,
    arch, Spectator.
  - `vendor/bin/pint --test` — passed (preset laravel + `declare_strict_types`).
  - `vendor/bin/phpstan analyse` — **level 9, no errors** (paths: app, config,
    database, routes, bootstrap/app.php).
  - `bash scripts/hygiene.sh` — exit 0 on source (see Decisions re: api/vendor).
  - Auth e2e via curl (`api/tests/e2e-auth.sh`) against a live
    `php artisan serve` + log mailer: csrf-cookie → request link → consume →
    replay-consume 410 → GET /me → logout → /me 401. **E2E OK.**
  - gitleaks binary not present in this environment; manual pattern sweep of all
    committed files (private keys, AKIA, xox*, ghp_, sk_live, APP_KEY=base64)
    found nothing. `.env` (with real APP_KEY) is gitignored; `.env.example` has
    fake values only.

## Remaining
- Brief acceptance sign-off by a separate verifier session (builders do not
  self-certify).
- Mailpit-mode run of `tests/e2e-auth.sh` (no mailpit binary here; log-mode run
  passed; the mailpit path is implemented but unexercised).
- WS-16: wire PHP gates into CI, and add `--exclude-dir=vendor` to the PHP grep in
  `scripts/hygiene.sh` (out of my path allowlist — see Decisions).
- WS-21 replaces the plain-text mail views with real templates + ESP.
- `docs/gdpr.md` (referenced by the brief) does not exist yet — retention windows
  implemented per brief text.

## Blockers
- None blocking. Note for the lead: GitHub *zipball* downloads are blocked by the
  session egress proxy (per-repo policy), so composer installed everything from
  git sources; dist-only `phpstan/phpstan` was seeded into the composer cache from
  a git clone of the exact pinned commit. Composer.lock records canonical dist URLs.

## Decisions made (lead: please audit)
1. **Laravel 13.18.1, not 12** — brief says "Laravel 12", task instruction says
   "latest stable". DEPENDENCIES.md governs *what*, not *which version*, so I
   followed "latest stable". Nothing in the deliverable is 13-specific beyond the
   skeleton (attribute-based `#[Fillable]` on models).
2. **mockery/mockery added to require-dev** (not in DEPENDENCIES.md). Laravel's
   own test harness hard-requires it at runtime: `Illuminate\Testing\PendingCommand::mockConsoleOutput`
   runs on every `artisan()` call in tests, which `RefreshDatabase` triggers for
   migrations. Dev-only, never shipped; it ships with the stock skeleton. Needs an
   allowlist amendment or an ADR footnote.
3. **hygiene.sh vs api/vendor**: the script greps `api/` for dd()/TODO/etc. but
   only excludes `node_modules`, so gitignored third-party `api/vendor` (~240 hits
   in whoops/faker/etc.) fails it. I may not touch `scripts/` under this brief.
   Verified exit 0 with vendor stashed aside; WS-16 should add `--exclude-dir=vendor`.
4. **Anonymize "timezone nulled"** → reset to `'UTC'`: the column is
   `NOT NULL DEFAULT 'UTC'` in the frozen schema, so NULL is impossible; the
   default is the only non-identifying value. Also nulled `country` and set
   `streak_alert_opt_in=false`, and deleted `auth_identities` + pending
   `magic_link_tokens` (both carry the email — PII) although the brief did not
   list them explicitly. `rating_events.user_id` set NULL per the schema comment.
5. **Consume + export throttles**: ADR-0003 names throttles only for the request
   endpoint; openapi documents 429 on consume and export too. Chose 5/min/IP
   (consume) and 3/h/user (export).
6. **Single-download semantics without a table**: the contract schema has no
   exports table, so single-use = file deleted after first successful download;
   403 (not-owner/unsigned/expired), 410 (already downloaded/never existed).
7. **Error envelope codes**: contract fixes only the shape
   `{error:{code,message}}`; chose codes `unauthenticated`, `validation_failed`,
   `rate_limited`.
8. **Pest arch `toUseStrictTypes` dropped**: trips an analyzer defect
   ("ObjectDescriptionBase::$path must not be accessed before initialization");
   `declare(strict_types=1)` is enforced by Pint's `declare_strict_types` rule in
   the `pint --test` gate instead.
9. **Streak summary derivation**: `freeze_available` derived conservatively from
   `freeze_available_at` (null-or-past = true), `safe_until` = null until WS-07
   owns the real UTC math.
10. **Composer install pathway**: everything from git source (see Blockers);
    `exclude-from-classmap` added for `vendor/laravel/pint/app|bootstrap` because
    source installs of pint (a Laravel Zero app) collide with the app classmap.
11. Skeleton dev packages not on the allowlist were removed (tinker, pail, pao,
    faker, collision+phpunit as direct deps — the latter two return transitively
    via pest). Factories generate data without faker.

## Files touched
- `api/**` (new — the entire Laravel app; ~110 files). Key paths:
  - `api/bootstrap/app.php` (api/v1 routing, statefulApi, error envelopes)
  - `api/database/migrations/contract/2026_07_03_00000{1..6}_*.php`
  - `api/app/Domain/{Auth,Solves,Ratings,Streaks,Content}/**`
  - `api/app/Http/Controllers/Api/V1/{MagicLinkController,MeController,ExportController}.php`
  - `api/app/Http/Controllers/ExportDownloadController.php`
  - `api/app/Console/Commands/Purge{SolveArtifacts,FrontendErrors,Events}.php`
  - `api/routes/{api,web,console}.php`
  - `api/tests/**` (8 feature files, arch tests, `schema-conformance.sh`, `e2e-auth.sh`)
  - `api/{phpstan.neon,pint.json,phpunit.xml,.env.example,composer.json,composer.lock}`
- `tasks/WS-06/STATUS.md` (this file)

## Resume instructions
1. Scratch Postgres for tests: PostgreSQL 16 cluster on `127.0.0.1:55432`, user
   `postgres`, trust auth, with database `burnfront_test` (tests) — boot recipe is
   in the header of `api/tests/schema-conformance.sh`; the conformance script
   creates its own scratch databases.
2. `cd api && composer install` (note: this environment needs git-source installs;
   normal environments install from dist per composer.lock).
3. Gates: `php artisan test` · `vendor/bin/pint --test` ·
   `vendor/bin/phpstan analyse` · `bash ../scripts/hygiene.sh` (from repo root;
   stash `api/vendor` or fix the script's vendor exclusion first, see Decisions #3).
4. E2E: `php artisan migrate --force && php artisan serve --port=8000`, then
   `MAIL_MODE=log bash tests/e2e-auth.sh` (or mailpit mode per script header).
5. Next session: verifier executes the brief acceptance checklist line by line.
