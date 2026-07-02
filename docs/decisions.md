# Burnfront — Resolved Decisions

This page resolves every load-bearing contradiction found by the design-review critic
(`docs/design/critique.md`) across the three design documents. **Contracts (`contracts/*`)
are authored from THIS page only**; the design docs in `docs/design/` are background
reading, not authorities. Owner-approved 2026-07-02 as part of the build-plan approval.
Each row is restated as an ADR in `docs/adr/`.

## Owner-locked stack decisions (pre-existing)

Laravel + Postgres backend, no Node on the server · TypeScript monorepo frontend ·
Claude Code orchestrated agent builds · v1 = web only on burnfront.com · v1 is free
(no billing code; `users.plan`/`users.pro_until` columns reserve the door) ·
Brand = Burnfront; daily = "the Daily Burn Order"; genre name stays Firebreak.

## Resolutions (ADR-0002 … ADR-0010)

| # | Question | Resolution |
|---|---|---|
| 1 | Daily day boundary | **UTC everywhere** — content calendar, streaks, `puzzle_fetches` credit, freeze rollover. In-app countdown to UTC midnight. "Local midnight" copy is dead. (`users.timezone` still stored — used ONLY for the streak-risk email send time.) |
| 2 | Auth | **Magic link only** in v1. No passwords, no social providers, no Apple dev account. `auth_identities` table from day one so providers are additive later. Token rules: single-use, 15-minute TTL, hashed at rest, constant response regardless of account existence, throttle 3/hour per email + 5/min per IP. Session rotated on consume. |
| 3 | Repo layout & naming | One canonical tree (see `docs/BUILD_PLAYBOOK.md`). Interfaces live in `contracts/` (not `specs/`), Laravel lives in `api/` (not `services/api`), CDN host is `content.burnfront.com`, share URLs are `/daily/{date}`. One daily endpoint `GET /api/v1/daily/{date}` with stats embedded (no separate `/stats`). |
| 4 | DB schema | The architecture doc's schema is the baseline (includes `puzzle_fetches`, `auth_identities`, `board_ratings`, `rating_events`, `content_imports`; entitlement = `plan`/`pro_until` columns, **no** entitlements table), **plus** `users.timezone`, `streaks.freeze_available_at`/`streaks.frozen_dates`. **No table partitioning** in v1 (plain `solves` table; 50k-DAU migration trigger documented). |
| 5 | Endless rated? | **Rated at reduced weight** (exact weight + fixtures in `contracts/RATING.md`). Server re-validates the submitted `endless_spec` with the PHP BFS — validity is checkable server-side; uniqueness is not needed to rate a solve. Keeps Glicko-2 fed with more than one game per day. Academy boards unrated. Stage-3 coach hint ⇒ solve unrated (stated on the button). |
| 6 | Leaderboards | **No named leaderboards in v1.** Percentile + rank number only ("Faster than 72% of today's crews" / "#214 to contain"). No public profiles, no handles exposed (column exists, unused). Cuts moderation, abuse and GDPR surface. |
| 7 | Analytics | **First-party only**: `events` table + `POST /api/v1/events` + weekly owner digest email. No Plausible, zero third-party requests anywhere. Persistent localStorage anon id for D1/D7 cohorts — a conscious ePrivacy posture, clearly disclosed in the privacy policy, revisited if Belgian DPA guidance tightens. Frontend errors via first-party `POST /api/v1/errors` beacon. |
| 8 | JS budgets | Two budgets, both hard CI failures: **landing** (Blade): ≤ 90KB gz deferred JS, HTML ≤ 60KB gz, LCP ≤ 2.0s mobile; **SPA**: ≤ 200KB gz initial. |
| 9 | Frontend stack | **Vite + React SPA** (TanStack Router, vite-plugin-pwa) served from Laravel `public/`; **Blade** renders `/`, `/rules`, `/about`, `/daily/{date}` unfurl shells + legal pages. No Next.js, no Inertia, no SSR sidecar (would violate no-Node-on-server). |
| 10 | Infra | Hetzner **CPX31** (Falkenstein, EU) + Laravel Forge + Cloudflare DNS/CDN + R2. Postgres on-box with **pgBackRest → R2** (nightly full + continuous WAL; quarterly restore drill; RTO 4h / RPO 15min). Staging on same box but isolated: own PG cluster + FPM pool memory caps. Redis with AOF enabled. |

## Scope cuts from v1 (do not build; do not leave dead code for)

Named leaderboards & Redis sorted sets · social login · Plausible/ClickHouse ·
`solves` partitioning · `POST /solves/batch` offline sync (submit-on-reconnect for the
single in-progress daily is enough) · nightly board-rating re-fit (seed from pipeline
grade; keep `rating_events` capture) · client-side Ed25519 verification (server-side
import verification only) · pixel-diff acceptance tests (fixture DOM assertions + one
human look instead).

## Additions the critic mandated (now in workstreams)

Anonymous→account merge `POST /me/import` with anti-fabrication caps (WS-20) ·
streak freeze schema + `streaks:rollover` (WS-07) · playtest soak phase P4.5 before
launch · transactional email workstream incl. streak-risk alert (WS-21) · first-party
error beacon (WS-19) · broken-daily runbook + T-48h immutability + streak amnesty flag
(WS-05/18) · CDN-down API fallback + T-2h freshness alert (WS-07/18) · magic-link
security spec (WS-06) · dedicated security-review session (WS-22) · `docs/gdpr.md`
processor inventory + retention schedule (replays/ip-hashes purged at 90 days, events
aggregated then purged at 13 months; delete = anonymize, aggregates survive) ·
EN-only launch but all copy through one keyed-strings module (NL later = translation
file) · landing social-proof counter fed by anonymous `solve_complete` events with
rank-fallback below 500 solves/day · `official_ms` clamped in BOTH directions
(≤ server window, ≥ replay duration) · SEO checklist in WS-15 · a11y: WCAG 2.1 AA
declared; no hold-to-reveal-only interactions · legal pages drafted by agents, signed
off by owner and lawyer · ~500 burn vectors (not 50) as the cross-language suite.
