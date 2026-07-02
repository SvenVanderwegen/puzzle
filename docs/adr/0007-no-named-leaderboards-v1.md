# ADR-0007: No named leaderboards in v1

Status: accepted · Date: 2026-07-02 · Deciders: owner + lead agent

## Context
A leaderboard endpoint existed in one design while the product design promised no public
profiles. Named boards demand handle moderation, abuse policy, and stronger anti-cheat.

## Decision
Cut named leaderboards and the Redis sorted-set plan from v1. Ship percentile + rank number
only ("Faster than 72% of today's crews" / "#214 to contain today's fire"), computed from
aggregates. `users.handle` stays in the schema, unexposed.

## Consequences
No moderation surface, no public-profile GDPR questions. Rush/Duel leaderboards return in
phase 3 with account age + replay-audit prerequisites.
