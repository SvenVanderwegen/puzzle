# ADR-0010: Infrastructure baseline and v1 scope cuts

Status: accepted · Date: 2026-07-02 · Deciders: owner + lead agent

## Context
Designs disagreed on box size and backup method; several features exceeded v1's needs.

## Decision
Hetzner CPX31 (Falkenstein, EU) provisioned by Laravel Forge; Cloudflare DNS/CDN + R2 for
content and backups; Postgres 16 on-box with pgBackRest → R2 (nightly full + continuous
WAL; RTO 4h / RPO 15min; quarterly restore drill); Redis with AOF; staging site on the same
box but isolated (own PG cluster + FPM pool memory caps); prod deploys on tag with owner
dispatch. Cut from v1: named leaderboards, social login, Plausible, `solves` partitioning,
`POST /solves/batch` (submit-on-reconnect for the in-progress daily only), nightly
board-rating re-fit, client-side Ed25519 verification, pixel-diff acceptance tests.

## Consequences
≈ €35–50/month all-in. Managed Postgres and a second app box are pre-planned 50k-DAU moves,
not v1 work. Anything on the cut list returning needs a new ADR.
