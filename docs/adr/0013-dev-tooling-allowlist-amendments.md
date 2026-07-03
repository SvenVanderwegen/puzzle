# ADR-0013: Dev-tooling allowlist amendments

Status: accepted · Date: 2026-07-03 · Deciders: lead agent (verifier flags, WS-04 + WS-06)

## Context
Two dev-only packages proved necessary and are absent from the frozen allowlist:
`@types/react` + `@types/react-dom` (TS types for the allowlisted React runtime —
strict typechecking is impossible without them) and `mockery/mockery` (hard-required
by Laravel's own test harness for in-test artisan calls under RefreshDatabase).

## Decision
Add to contracts/DEPENDENCIES.md (dev/build sections): @types/react, @types/react-dom
(TS); mockery/mockery (PHP, require-dev). None ship to users.

## Consequences
DEPENDENCIES.md amended in this range (contracts-guard satisfied). Supply-chain note:
all three are ecosystem-canonical companions of already-allowlisted packages.
