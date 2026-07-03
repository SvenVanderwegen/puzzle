# ADR-0019: Late application of the ADR-0017/0018 contract edits

Status: accepted · Date: 2026-07-03 · Deciders: lead agent

## Context
The WS-09 integration commit shipped ADR-0017 (COPY keys) and ADR-0018 (ashDim
role) but the accompanying contract edits aborted on a bad string match (the
tokens file is json.dump-formatted, not single-line) and the failure was masked
in a compound command. For one commit, two ADRs claimed amendments the tree did
not contain.

## Decision
This range applies the promised edits: COPY.md +3 keys, design-tokens.json
ashDim role, CODEMAP api-client row. Process fix adopted: lead integration
scripts must assert-and-fail the WHOLE command on any patch miss (set -e
semantics around heredocs), and contract edits are verified by re-grepping
before commit.

## Consequences
Contracts and ADRs are consistent again. The strings.gen.ts catalog picks up
the three keys at its next regeneration (WS-10/11/12 sessions).
