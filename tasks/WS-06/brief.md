# WS-06: Laravel scaffold + magic-link auth + GDPR base

Lane: B (backend) · Deps: WS-01 · Sessions: 2

## Scope
`api/`: Laravel 12, Postgres, Sanctum stateful SPA auth (same-origin cookie + CSRF).
Magic-link auth per ADR-0003: request + consume endpoints, single-use hashed tokens, 15-min
TTL, constant responses (no account enumeration), throttles 3/hour/email + 5/min/IP,
session rotation on consume. Mail via the ESP abstraction (mailpit locally). Migrations
matching `contracts/db-schema.sql` exactly. Domain module skeletons
`app/Domain/{Auth,Solves,Ratings,Streaks,Content}` with Pest `arch()` boundary tests
(Domain never references Illuminate\Http; only Content touches storage; only Ratings writes
rating tables). GDPR base: `DELETE /me` = queued **anonymize** job (null user_id on solves,
keep aggregates — critique #22), `GET /me/export` = queued job → signed URL, 24h expiry,
single download, re-auth required; retention purge commands (replays + ip/ua hashes at 90
days; events aggregate-then-purge at 13 months). Pint + Larastan level 9. `.env.example`
complete.

## Inputs
`contracts/db-schema.sql`, `contracts/openapi.yaml` (auth + me paths), ADR-0003/0005.

## Outputs
The Laravel app, migrations, auth flow, GDPR jobs, feature tests.

## Acceptance
- [ ] `php artisan migrate:fresh` then schema-dump diff vs `contracts/db-schema.sql` empty
- [ ] Auth e2e via curl script: request link (mailpit) → consume → `GET /me` → logout
- [ ] Enumeration test: identical response shape/timing class for known vs unknown email
- [ ] Throttle tests (3/h/email enforced across IPs)
- [ ] Anonymize job: solves rows survive with null user; ratings/streaks rows deleted;
      export produces valid JSON of all user data
- [ ] Larastan 9 + Pint clean; arch() tests green; Spectator wired for auth paths

## Non-goals
No game endpoints (WS-07), no email templates/ESP account (WS-21 — use log/mailpit mailer).
