# WS-21 STATUS

## Session 2026-07-03 (builder)

## Done
- (commit SHA recorded below after commit; single commit on this worktree branch)
- **Transactional mail hardening**: new abstract `App\Domain\Email\TransactionalMail`
  (extends `Mailable`, implements `ShouldQueue`, `$tries = 5`, exponential
  `backoff()` 1m/5m/15m/1h). Every user-facing mailable now derives from it, so
  `Mail::to(...)->send()` and `->queue()` both go through the queue — no SMTP
  round-trip ever runs inside a request or the solve/streak flow, and an ESP
  outage degrades to delayed mail, never a failed API response.
  - `MagicLinkMail` (WS-06) hardened: queued; tighter backoff (10/30/60/120s);
    `retryUntil` = the token's own 15-minute TTL captured at issue time (a link
    that arrives dead is worse than none). Subject stays the COPY.md
    `email.magic.subject` text, now pinned by test. No List-Unsubscribe headers
    on purpose (pure transactional).
  - `ExportReadyMail` (WS-06) queued with default backoff (link lives 24 h).
  - `DeletionConfirmedMail` (new): GDPR erasure receipt, queued by
    `UserAnonymizer` after the anonymize transaction commits, to the address
    captured before nulling; idempotent replays send nothing.
- **Streak-protection alert** (`notifications:streak-risk`, scheduled hourly at
  :15 in `routes/console.php`):
  - Selection in `App\Domain\Streaks\StreakRiskAlert`: opted-in + live email +
    not anonymized + streak >= 2 + today's daily unsolved, and the streak must
    actually die at the coming UTC midnight — decided by WS-07's `safe_until`
    math, so freezes, amnesty days, unpublished dailies and already-dead streaks
    all suppress the alert for free. Day boundary stays UTC (ADR-0002); only the
    send moment is local: a user is matched in the hour their local clock
    (`users.timezone`) reads 20 (`config/burnfront.php streak_alert.local_hour`).
    IANA offsets are 15-minute multiples, so each zone's 60-minute window
    contains exactly one hourly tick per local day.
  - Once per user per UTC day via atomic `Cache::add` marker (36 h TTL) written
    before queueing: on a crash between marker and queue we prefer a missed
    alert to a double. Per-candidate try/catch (`report()` + continue) so one
    bad row (e.g. unparseable stored timezone) never halts the sweep.
  - `StreakRiskMail`: subject/body verbatim from COPY.md `email.streak.subject`
    / `email.streak.body` with `{n}`/`{hours}`/`{incident}` substituted (pinned
    by test); play link `FRONTEND_URL/daily/{date}`; `retryUntil` = the UTC
    midnight it warns about (an alert after the deadline is misinformation);
    List-Unsubscribe + List-Unsubscribe-Post (RFC 8058) headers.
- **One-click unsubscribe** (no contract path exists for this — checked
  openapi.yaml — so it is a signed web route like `exports.download`, NOT a new
  openapi path): `GET|POST /email/streak-alert/unsubscribe/{userId}`, route
  `email.streak-alert.unsubscribe`, middleware `signed`, non-expiring signature
  (mailed unsubscribe links must keep working). GET = human click, renders a
  standalone confirmation page; POST = RFC 8058 one-click target, CSRF-exempt
  in `bootstrap/app.php` (signature-guarded, single-purpose: can only switch
  alerts OFF). Same calm 200 whether or not the id resolves (no oracle);
  tamper/unsigned/cross-route-signature all 403.
- **Opt-in confirmation**: PATCH /me flipping `streak_alert_opt_in` false→true
  queues `StreakAlertSubscribedMail` (consent paper trail + immediate one-click
  off switch). See Decisions #1 for the double-opt-in ruling this encodes.
- **ESP posture (code side)**: `config/mail.php` trimmed to smtp/log/array —
  vendor-SDK transports removed (no such deps allowlisted), skeleton's
  smtp→log failover removed ("delivered to the log" would mark a queued send
  successful and silently skip its retries). `.env.example` documents the
  production shape (EU ESP SMTP relay, creds in Forge only); mailpit stays the
  local default. Code is fully ESP-agnostic SMTP.
- **docs/gdpr.md**: ESP processor row updated — ESP-agnostic SMTP shipped,
  TODO(owner) appoint vendor, candidates named, DPA + DNS preconditions stated.
- **Tests**: 265 passed (3390 assertions) — 43 new in `api/tests/Feature/Email/`
  (CopyPinningTest, StreakRiskAlertTest targeting matrix incl. UTC+14/UTC-11
  rollover edges and once-per-day idempotency, StreakAlertUnsubscribeTest incl.
  tamper + cross-route signature replay, StreakAlertSubscribedTest,
  DeletionConfirmedTest, MailablesTest with one rendered-body snapshot per
  mailable + retry-semantics + text-only assertions). WS-06's magic-link/export
  mail assertions updated `assertSent`→`assertQueued` (the mailables are now
  ShouldQueue; same flow, new queue semantics).
- **Gates** (final run this session): `php artisan test` 265 passed ·
  `vendor/bin/pint --test` passed · `vendor/bin/phpstan analyse` L9 no errors ·
  `bash scripts/hygiene.sh` exit 0 (repo root).
- **Live end-to-end smoke** (scratch PG :55432, log mailer, `artisan serve`):
  seeded an at-risk user (Asia/Bangkok, local 20:xx at run time) → sweep queued
  exactly 1 mail (subject "Your 5-day streak has 11 hours.", body Incident #999,
  correct headers); second run queued 0 (idempotency, database cache); mailed
  unsubscribe URL: GET 200 flips flag off, POST one-click 200 flips again,
  tampered signature 403, unsigned 403; POST /auth/magic-link 202 with the
  queued mail rendered through the log transport. Smoke DB and logs removed.

## Remaining
- Brief acceptance sign-off by a separate verifier session (builders do not
  self-certify).
- Owner actions in Blockers below (ESP account, DNS, DPA) — production sending
  is impossible until then; nothing else in the workstream is blocked by it.
- Lead ruling on Decisions #1 (double-opt-in interpretation) and #2 (non-COPY
  email copy handoff).
- Mailpit-mode visual pass of the four templates (no mailpit binary in this
  environment; log-transport rendering verified instead).

## Blockers
**BLOCKER (owner-only): ESP account + domain authentication for burnfront.com.**
No ESP account exists; per CLAUDE.md rule 5 no agent may hold or request the
credentials. The code is ESP-agnostic SMTP — everything below is Forge/DNS
work, no code change needed:
1. **Choose + contract an EU ESP** (EU entity, EU data residency, SMTP relay,
   GDPR DPA). Vetted candidates:
   - Scaleway Transactional Email (TEM) — France; SMTP relay; data in France.
   - Brevo — France; SMTP relay; EU data centers.
   - Mailjet (Sinch, Sweden) — EU data residency; SMTP relay.
2. **Sign the DPA** with the chosen ESP before the first production send, then
   replace the TODO(owner) row in `docs/gdpr.md` §Processors with the vendor's
   legal name and transfer basis.
3. **Set SMTP creds in Forge** (`MAIL_HOST`, `MAIL_PORT=587`,
   `MAIL_SCHEME=smtp` (STARTTLS), `MAIL_USERNAME`, `MAIL_PASSWORD`,
   `MAIL_FROM_ADDRESS=dispatch@burnfront.com`, `MAIL_FROM_NAME=Burnfront`,
   `MAIL_EHLO_DOMAIN=burnfront.com`). Never commit these.
4. **DNS records to create on burnfront.com (Cloudflare)** — exact values come
   from the ESP dashboard at domain verification; the record set is:
   - SPF — TXT at `burnfront.com`:
     `v=spf1 include:<esp-spf-host> -all`
     (Scaleway TEM: `include:_spf.tem.scaleway.com` · Brevo:
     `include:spf.brevo.com` · Mailjet: `include:spf.mailjet.com`).
     One SPF record only — merge if a TXT `v=spf1` already exists.
   - DKIM — the ESP-issued selector record(s), e.g.
     `<selector>._domainkey.burnfront.com` CNAME/TXT with the ESP's public key.
   - DMARC — TXT at `_dmarc.burnfront.com`:
     `v=DMARC1; p=none; rua=mailto:dmarc-reports@burnfront.com; adkim=s; aspf=s`
     to start (monitor), tighten to `p=quarantine` then `p=reject` after two
     clean weeks of reports.
   - Return-Path/bounce CNAME if the ESP issues one (custom bounce domain,
     e.g. `bounces.burnfront.com`).
   - Ensure `burnfront.com` (or the From subdomain) has an MX + mailbox so
     replies and the `dmarc-reports@` address do not bounce.
5. After DNS propagates: send-to-self smoke via the ESP relay, confirm
   SPF/DKIM/DMARC all pass in the received headers, then flip Forge to the ESP.

## Decisions made
1. **Double opt-in interpreted, not literally enforced** (lead ruling wanted).
   The brief says "double opt-in enforced"; openapi.yaml's field description
   says "Double opt-in starts on true (WS-21)". But WS-14 shipped the settings
   toggle + pinned API test expecting the PATCH to flip the flag immediately,
   and there is no schema state for "pending confirmation" (users has only the
   boolean; contract frozen). A strict confirm-by-mail flow would break WS-14's
   UI/tests outside my paths. Shipped instead: opt-in step 1 = the address is
   proven owned by construction (magic-link-only auth, ADR-0003 — you cannot
   hold a session without having clicked a link mailed to that address);
   step 2 = the explicit authenticated toggle, which queues a confirmation mail
   carrying a one-click no-login off switch. Alerts also only ever fire for
   users actively playing (streak >= 2). If the lead wants strict double opt-in
   the signed-route pattern is already in place — it needs a WS-14 UI state +
   copy key + MeTest change, not new plumbing.
2. **Email copy without COPY.md keys** (same governance path as ADR-0014/0017/
   0023 copy-key handoffs): COPY.md defines only magic subject + streak
   subject/body, all used verbatim and pinned by test. New copy I drafted
   in-voice, for the lead to bless into COPY.md at integration: subjects
   "Streak protection alerts are on." / "Your Burnfront account is deleted.",
   the streak-alert-subscribed and deletion-confirmed bodies, the two link
   lines in the streak-risk body ("Contain it: {url}", "One click turns these
   alerts off: {url}"), and the unsubscribe page (Blade, marketing-exempt
   precedent ADR-0022). Existing WS-06 magic-link/export bodies left as-is.
3. **{hours} rounds UP** (ceil, never "0 hours"): for far-west evening zones
   (e.g. UTC-3 → local 20:xx is 23:xx UTC) the true remainder can be under an
   hour; "0 hours" reads as already lost. Ceil overstates by <1 h at worst.
   Note for copy governance: `{hours}` = 1 renders "1 hours" — COPY.md text is
   law so I did not fix the plural; flagging it.
4. **Once-per-day marker in cache, not a table**: the frozen contract schema
   has no sends table and adding one is a contract change. `Cache::add` (atomic,
   database store in prod, 36 h TTL) keyed `streak-alert:{user}:{utc-date}`.
   Failure bias is deliberate: marker-then-queue means a crash in between skips
   that day's alert rather than double-sending; a flushed cache could at worst
   allow one duplicate per user — acceptable for a warning mail.
5. **Alert eligibility delegates to WS-07's `safe_until`** instead of
   reimplementing the freeze/amnesty walk: the alert fires iff `safe_until` ==
   the coming UTC midnight. One source of truth for streak-death math.
6. **`streaks:rollover` at 00:05 vs hourly sweep at :15**: no interaction — the
   sweep's 00:15 tick evaluates the NEW UTC day (rollover already ran), and
   candidates for the new day are re-derived from `safe_until`.
7. **MeController + UserAnonymizer touched** (beyond the brief's obvious mail/
   job/config surface, still inside `api/`): 6 lines in MeController::update to
   queue the opt-in confirmation; UserAnonymizer returns the pre-null email
   from the transaction and queues the deletion receipt after commit. Both are
   the minimal seams for brief deliverables (double-opt-in flow, deletion
   confirmation template).
8. **WS-06 test files edited** (`MagicLinkRequestTest`, `MagicLinkConsumeTest`,
   `ExportTest`): `Mail::assertSent` → `Mail::assertQueued` only — forced by
   the ShouldQueue hardening of those same mailables, which this brief owns
   ("WS-21 replaces/hardens WS-06 mail").
9. **config/mail.php trimmed** to smtp/log/array: vendor-SDK transports
   (ses/postmark/resend) reference packages not in DEPENDENCIES.md; the stock
   smtp→log failover would count a logged mail as delivered and cancel queue
   retries. Swapping ESPs stays a pure .env change.
10. **Unsubscribe signature never expires**; it is single-purpose (route can
    only set the flag false), single-user (userId under signature), and the
    page/200 is identical for unknown/anonymized ids (no existence oracle).
    Anonymized rows are left untouched by the route.
11. **Email events never feed analytics** (brief non-goal): no ESP webhooks, no
    ingestion, nothing added to the events surface. Nothing to build; recorded
    so the verifier can tick it.

## Files touched
- `api/app/Domain/Email/TransactionalMail.php` (new)
- `api/app/Domain/Streaks/StreakRiskAlert.php` (new)
- `api/app/Domain/Streaks/Mail/{StreakRiskMail,StreakAlertSubscribedMail}.php` (new)
- `api/app/Domain/Auth/Mail/DeletionConfirmedMail.php` (new)
- `api/app/Domain/Auth/Mail/{MagicLinkMail,ExportReadyMail}.php` (hardened)
- `api/app/Domain/Auth/UserAnonymizer.php` (deletion receipt after commit)
- `api/app/Console/Commands/NotificationsStreakRisk.php` (new)
- `api/app/Http/Controllers/StreakAlertUnsubscribeController.php` (new)
- `api/app/Http/Controllers/Api/V1/MeController.php` (opt-in confirmation mail)
- `api/resources/views/mail/{streak-risk,streak-alert-subscribed,deletion-confirmed}.blade.php` (new)
- `api/resources/views/streak-alert/unsubscribed.blade.php` (new)
- `api/routes/{web,console}.php`, `api/bootstrap/app.php` (route/schedule/CSRF)
- `api/config/{mail,burnfront}.php`, `api/.env.example`
- `api/tests/Feature/Email/{CopyPinningTest,StreakRiskAlertTest,StreakAlertUnsubscribeTest,StreakAlertSubscribedTest,DeletionConfirmedTest,MailablesTest}.php` (new)
- `api/tests/Feature/Auth/{MagicLinkRequestTest,MagicLinkConsumeTest}.php`,
  `api/tests/Feature/Me/ExportTest.php` (assertQueued)
- `docs/gdpr.md` (ESP processor row only)
- `tasks/WS-21/STATUS.md` (this file)

## Resume instructions
1. Scratch Postgres 16 on `127.0.0.1:55432` (user postgres, trust), database
   `burnfront_test` — recipe in `api/tests/schema-conformance.sh` header; it
   may already be running (`pg_isready -h 127.0.0.1 -p 55432`).
2. `cd api && composer install` (this environment needs git-source installs —
   tasks/WS-06/STATUS.md decisions 6/10; if the sibling checkout at the repo
   main worktree has an identical composer.lock, copying its vendor/ works),
   `cp .env.example .env && php artisan key:generate`.
3. Gates: `php artisan test` (265) · `vendor/bin/pint --test` ·
   `vendor/bin/phpstan analyse` · `bash scripts/hygiene.sh` (repo root).
4. Live smoke recipe: seed a daily + opted-in user whose `users.timezone` reads
   local hour 20 at run time, streak current_len>=2, last_daily_date=yesterday,
   freeze_available_at=next month; then
   `MAIL_MAILER=log QUEUE_CONNECTION=sync php artisan notifications:streak-risk`
   and follow the unsubscribe URL from `storage/logs/laravel.log` against
   `php artisan serve`.
5. Next: verifier session walks the brief acceptance checklist; lead rules on
   Decisions #1/#2; owner executes the Blocker list before any production send.
