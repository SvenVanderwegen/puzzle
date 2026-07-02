# ADR-0001: Record architecture decisions; the locked stack

Status: accepted · Date: 2026-07-02 · Deciders: owner + lead agent

## Context
Burnfront is built by many parallel AI agent sessions. Decisions that live only in chat
transcripts do not exist for the next session.

## Decision
All boundary-crossing decisions are recorded as MADR-style files in `docs/adr/`. Agents cite
ADR numbers in PR descriptions. The owner-locked stack is recorded here: Laravel + Postgres
backend (no Node on the server), TypeScript monorepo frontend (`packages/engine` pure TS),
web-first v1 on burnfront.com, free at launch (`users.plan`/`pro_until` reserve the Pro
door), brand Burnfront / daily "Burn Order" / genre Firebreak, orchestrated Claude Code
agent builds with contracts frozen after WS-01.

## Consequences
A decision that isn't an ADR doesn't exist. Changing a frozen contract requires a new ADR
plus lead review plus owner approval.
