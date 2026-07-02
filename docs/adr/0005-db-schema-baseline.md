# ADR-0005: Database schema baseline

Status: accepted · Date: 2026-07-02 · Deciders: owner + lead agent

## Context
Two incompatible schemas existed; one omitted the anti-cheat anchor (`puzzle_fetches`) and
the rating audit trail.

## Decision
The architecture design's schema (docs/design/architecture.md §3) is the baseline: users
(with `plan`/`pro_until`, no entitlements table), `auth_identities`, `puzzles`,
`daily_puzzles`, `puzzle_fetches`, `solves` (idempotent via `(user_id, client_solve_id)`;
one valid daily solve per user/puzzle via partial unique index), `ratings`,
`board_ratings`, `rating_events`, `streaks`, `content_imports`. Additions:
`users.timezone`, `streaks.freeze_available_at`, `streaks.frozen_dates`. No table
partitioning in v1 (revisit at 50k DAU). `contracts/db-schema.sql` is the frozen artifact;
migrations must schema-dump-diff clean against it.

## Consequences
Renames follow expand/contract; destructive migrations cite an ADR in their docblock.
