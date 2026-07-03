# Burnfront — GDPR record (WS-19)

Status report, data protection. Controller: the owner (sole proprietor, Belgium).
Contact: the address published on the site's legal page. This page is the Art. 30
record of processing activities and the operating notes that keep it true.
Sources of authority: ADR-0008 (first-party analytics only), ADR-0003 (magic-link
auth), decisions.md #7 and #10.

## Processing inventory (Art. 30)

| Activity | Data | Subjects | Legal basis | Where |
|---|---|---|---|---|
| Accounts and sign-in | email (citext), timezone, country, session cookie; magic-link token hashes | players with accounts | contract (Art. 6(1)(b)) | Postgres, on-box |
| Solve records | solve rows, replay logs, peppered ip/ua hashes (anti-abuse) | players | legitimate interest (Art. 6(1)(f)) | Postgres, on-box |
| First-party analytics | anon_id (random localStorage id), event name, typed props, optional user id | all visitors | legitimate interest, disclosed | Postgres, on-box |
| Error beacon | error message, stack, route — PII-scrubbed before storage | all visitors | legitimate interest | Postgres, on-box |
| Transactional email | recipient address, send metadata | players who request mail | contract | EU ESP (WS-21, pending) |

No profiling, no automated decisions, no sale or sharing of personal data, no
third-party analytics, zero third-party requests from any page (ADR-0008).

## Processors

| Processor | Role | Location / transfer basis |
|---|---|---|
| Hetzner Online GmbH | hosting (app, Postgres, Redis) | Falkenstein, Germany — EU, no transfer |
| Cloudflare, Inc. (DNS/CDN + R2) | traffic proxy, content CDN, encrypted backups | EU edge; US entity under the EU–US Data Privacy Framework, with SCCs as fallback |
| EU ESP — TODO(owner): appoint the vendor. The WS-21 integration is ESP-agnostic SMTP, so the choice is an .env change; candidates with EU entity + EU data residency: Scaleway TEM, Brevo, Mailjet (owner checklist in tasks/WS-21/STATUS.md) | transactional email delivery: magic links, GDPR receipts, opt-in streak alerts | EU entity + EU data residency required; the DPA is signed and SPF/DKIM/DMARC published before the first production send |
| Laravel Forge | server management (no customer-data access in normal operation) | US entity, DPF; holds deploy credentials, not player data |

## Retention schedule

| Data | Window | Then |
|---|---|---|
| `events` raw rows | 13 months | aggregated, then deleted. Aggregation writes one rollup row per calendar month and event name into `events` itself under the reserved namespace `name = _rollup.<name>`, `anon_id = _system` (counts, distinct-id counts, medians/sums only). The API's event-name enum cannot produce `_rollup.*` or `_system`, so raw and rollup rows never collide. Rollup rows are permanent, carry no per-user data, and are excluded from all per-user semantics. Purging runs on whole calendar months: a raw row is deleted at 13 months plus at most one partial month. |
| `frontend_errors` rows | 90 days | deleted outright |
| `solves.replay`, `solves.ip_hash`, `solves.ua_hash` | 90 days | nulled; the solve row itself survives without them |
| magic-link tokens | 15 minutes, single use | expired rows deleted; only sha256 hashes are ever stored |
| account data | life of the account | delete = anonymize, below |
| backups (pgBackRest → R2, encrypted) | rolling nightly + WAL | expired sets pruned by retention policy; a restore re-applies the purge commands on next run |

Enforcement is code, not calendar reminders: `analytics:purge` runs daily
(events + frontend_errors), `retention:purge-solve-artifacts` runs daily,
token expiry is checked at consume time.

## Anonymous id posture

The frontend assigns each browser a random id, kept in localStorage, sent only
to our own API with analytics events. It is first-party, never in a cookie
header, never shared, and never joined with IP addresses — the API does not
store IPs for analytics at all (the IP participates in rate limiting only, in
memory/cache, never in a row). Rate-limit throttle keys — including the
IP-derived per-address ceilings on the events and errors endpoints — are
transient cache entries that decay within about a minute; they are never
persisted to the analytics tables, so the no-IP-stored guarantee is unchanged.
This persistent identifier is a conscious
ePrivacy position (decisions.md #7): it is disclosed plainly in the privacy
policy, players can clear it at any time by clearing site data, and the
position is revisited if Belgian DPA guidance tightens. There is no consent
banner because there is nothing third-party to consent to; we state this
reasoning publicly rather than imply the question away.

## Error beacon scrub

Frontend error reports are scrubbed server-side before the row is written:
anything matching an email address, a `Bearer` credential, or a `token=` query
parameter (magic-link URLs) is replaced with a fixed placeholder. Scrubbing
runs before truncation to the contract caps (message 2000, stack 8000,
route 200), so redaction can never be truncated back into visibility.

## Delete = anonymize

Account deletion (DELETE /me) anonymizes rather than erases rows that feed
anonymous aggregates: email, handle and country are cleared, identities and
pending tokens are deleted, ratings and streaks are removed, solves are
disowned. In `events`, the `user_id` column has no foreign key on purpose: the
users row it once pointed at keeps none of its identifying fields after
anonymization, so what survives in `events` is an opaque ULID that resolves to
nothing and to no one — it cannot be joined back to a person by us or by
anyone with the dataset. It is retained only so pre-deletion aggregates stay
arithmetically true, and it ages out with its rows at the 13-month purge.
Export (GET /me/export) ships the player's own data as a single-download
archive.

## Breach notification

If personal data is exposed, the owner — as controller — notifies the Belgian
DPA (Gegevensbeschermingsautoriteit) within 72 hours of becoming aware, and
affected players without undue delay where the risk is high (Art. 33/34). The
incident log records detection time, scope, and containment steps. Processor
contracts (Hetzner, Cloudflare, the WS-21 ESP) require breach notice to us
without undue delay.
