# ADR-0008: First-party analytics only

Status: accepted · Date: 2026-07-02 · Deciders: owner + lead agent

## Context
One design proposed self-hosted Plausible (drags ClickHouse onto a 4GB box); the privacy
posture promises zero third-party requests.

## Decision
First-party only: `events` table + `POST /api/v1/events` (rate-limited, schema-validated,
batch-capped) + `POST /api/v1/errors` beacon for frontend errors + a weekly
`analytics:digest` email to the owner. Persistent localStorage anonymous id for D1/D7
cohorts — a conscious ePrivacy posture, disclosed plainly in the privacy policy, revisited
if Belgian DPA guidance tightens. Landing social proof counts anonymous `solve_complete`
events (rank-fallback under 500 solves/day). Retention: events aggregated then row-purged
at 13 months.

## Consequences
No consent banner (documented reasoning in `docs/gdpr.md`); the digest is the only
reporting surface until a dashboard is justified.
