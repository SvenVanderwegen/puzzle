# ADR-0011: Contract freeze

Status: accepted · Date: 2026-07-02 · Deciders: **owner (explicit sign-off recorded
this date)** + lead agent

## Context

WS-01 authored the complete contract pack from `docs/decisions.md`. Independent
verification: session a — 8/8 (including a from-scratch BFS re-check of sampled
vectors and byte-identical regeneration on GitHub's runner); session b — 16/17
(including Glicko-2 fixtures reproduced from the RATING.md prose alone and
`db-schema.sql` executed on a live PostgreSQL 16 cluster; the single literal
grep hit was ruled an allowed negative statement). A final lead cross-consistency
pass closed two seams pre-freeze (endless `deduction_steps` storage note;
self-serve email change cut from WS-14 — no endpoint existed and magic-link auth
makes it nontrivial; delete + re-signup covers v1).

## Decision

The following are FROZEN as of this ADR:

`contracts/openapi.yaml` · `contracts/engine-api.d.ts` · `contracts/db-schema.sql`
· `contracts/RATING.md` · `contracts/COPY.md` · `contracts/design-tokens.json` ·
`contracts/DEPENDENCIES.md` · `contracts/schemas/{puzzle,pack,calendar}.v1.json`
(+ example) · `contracts/vectors/*` (generated only by
`reference/firebreak.py --emit-vectors` — the `--emit-vectors` block is the one
sanctioned edit ever made to the frozen reference, added in WS-01a).

**Change process:** any diff touching `contracts/` must, in the same push/PR, add
a new `docs/adr/NNNN-*.md` describing what/why/migration; PRs additionally carry
the `contract-change` label; the owner approves. Consumers are updated in the
same integration cycle. CI enforces the ADR-presence rule (`contracts-guard`
job). EN wording changes to existing COPY.md keys are exempt (keys are the
contract); the exemption does not cover adding/removing keys.

## Consequences

WS-02 (engine extraction, critical path) and WS-06 (Laravel scaffold) are
unblocked and may run in parallel worktrees. `packages/api-client` generation,
Spectator conformance, vector parity, and the deps allowlist check all now have
a stable target. The rating outcome function deliberately ignores solve time in
v1 (recorded in WS-01 STATUS; changing it is a normal ADR).
