# ADR-0012: replay_sha256 is computed over the uncompressed replay JSON

Status: accepted · Date: 2026-07-03 · Deciders: lead agent (WS-03 verifier flag)

## Context
The SolveSubmission carries a gzipped replay plus replay_sha256. The contract did
not state whether the digest covers the compressed or uncompressed bytes; WS-03
implemented uncompressed, and WS-07's server-side verification must match.

## Decision
replay_sha256 = SHA-256 hex over the uncompressed replay JSON byte sequence
(the canonical [t_ms, cellIndex, mark] event-log JSON). Servers verify after
gunzip. openapi.yaml description amended in this range (contracts-guard
satisfied by this ADR).

## Consequences
Gzip output variance across implementations is irrelevant to integrity. WS-07's
BurnValidator path asserts the digest post-gunzip.
