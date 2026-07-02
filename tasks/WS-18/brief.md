# WS-18: Ops & observability

Lane: D · Deps: WS-16 · Sessions: 1

## Scope
Nightwatch wired per environment (exceptions, slow routes with `POST /solves` p95 alert at
200ms, queue lag, scheduled-task misses). Health: `/api/health` uptime check; **daily
freshness alert at T-2h for tomorrow's board** on the CDN (critique #17) + content-runway
alert < 21 days. Backups verified (pgBackRest from WS-16) + quarterly restore-drill
calendar note. `docs/RUNBOOK.md` operational sections: **pull-a-daily procedure**
(published replacement content_version + streak amnesty flag for that date + in-app notice
— critique #16), CDN-down fallback drill (flip the WS-07 flag), breach-notification note
(GDPR 72h, contact steps), log retention consistent with `docs/gdpr.md`.

## Inputs
WS-16 infra, WS-07 flags/commands, Nightwatch account (owner Blocker if absent).

## Outputs
Config, health endpoints, alert definitions, RUNBOOK operational sections.

## Acceptance
- [ ] Forced exception appears in Nightwatch (staging)
- [ ] Missing-tomorrow drill fires the alert; pull-a-daily rehearsed on staging incl.
      amnesty flag behavior
- [ ] CDN-down drill: clients keep playing via fallback (e2e against staging)
- [ ] RUNBOOK sections reviewed by lead for executability by a non-author

## Non-goals
No paid APM beyond Nightwatch, no status page in v1.
