# ADR-0004: Canonical repo layout and naming

Status: accepted · Date: 2026-07-02 · Deciders: owner + lead agent

## Context
The design docs used conflicting names (`specs/` vs `contracts/`, `api/` vs `services/api`,
two CDN hostnames, two share-URL shapes). Agents building from different docs would fork
the tree in week one.

## Decision
The tree in `docs/BUILD_PLAYBOOK.md` §1 is canonical: `contracts/` for interfaces, `api/`
for the Laravel app, `pipeline/` for Python content tooling, `reference/` for the frozen
prototype, `tasks/` for the agent ledger. CDN host `content.burnfront.com`; share URLs
`/daily/{date}`; one daily endpoint `GET /api/v1/daily/{date}` with stats embedded.

## Consequences
Any path not in the playbook tree needs an ADR. WS-00 performs the restructure with
`git mv` so history survives.
