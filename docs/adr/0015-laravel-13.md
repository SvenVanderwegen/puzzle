# ADR-0015: Laravel 13 ratified

Status: accepted · Date: 2026-07-03 · Deciders: lead agent (WS-06 verifier flag)

## Context
Briefs and DEPENDENCIES.md prose said "Laravel 12" (written from 2025-era design
docs); the builder installed the current stable 13.18.1 per "latest stable" and
uses 13-only affordances (#[Fillable] attributes). DEPENDENCIES.md's header says
it governs what, not which version.

## Decision
Laravel 13 is ratified for v1. DEPENDENCIES.md's aside updated in this range.
Greenfield launches track the current major; the brief number was stale, not a
constraint.

## Consequences
No downgrade. Stale "12" references in historical briefs/design docs stay as-is;
the playbook tree comment is updated (docs, non-contract).
