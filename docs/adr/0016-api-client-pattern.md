# ADR-0016: api-client = generated types + type-locked wrapper

Status: accepted · Date: 2026-07-03 · Deciders: lead agent (WS-09 verifier)

## Context
"GENERATED from openapi.yaml" was written assuming a generator emits a runtime
client; openapi-typescript emits types only, and runtime client packages
(openapi-fetch) are not allowlisted.

## Decision
packages/api-client = types.gen.ts (100% generated, byte-diff freshness-gated by
generate:check) + client.ts, a zero-dependency wrapper whose every path/verb/
param/body/response derives from the generated `paths` type — a wrong call is a
compile error (proven by @ts-expect-error tests and verifier mutations). The
wrapper is the ONLY hand-authored file and may not widen the typed surface.

## Consequences
Hallucinated endpoints remain compile errors. generate:check joins CI.
