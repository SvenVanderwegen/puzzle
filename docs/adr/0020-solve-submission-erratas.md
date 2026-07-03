# ADR-0020: SolveSubmission erratas — endless deduction_steps; replay digest

Status: accepted · Date: 2026-07-03 · Deciders: lead agent (WS-07 verifier flags)

## Context
(1) The server requires `deduction_steps` for endless submissions (RATING.md §4
needs it to price the board) and prohibits it plus `puzzle_id` cross-mode — the
frozen openapi left it optional-shaped, so a schema-conformant client could be
rejected. (2) `replay` and `replay_sha256` are independently optional, letting a
client ship a replay with no integrity digest, hollowing out ADR-0012.

## Decision
Contract erratas (description-level; JSON-schema structure untouched to keep the
Spectator/opis 3.1 tooling stable): `deduction_steps` is REQUIRED for
mode=endless and PROHIBITED otherwise; `replay_sha256` is REQUIRED whenever
`replay` is present. Server already enforces (1); enforcement of (2)
(required_with) lands in the next api session (WS-08 scope rider), together with
a test for the untested `mapUniqueViolation` race branch.

## Consequences
openapi.yaml descriptions amended in this range. api-client TS types unchanged
(optionality is unchanged structurally); game-core already emits both fields.
