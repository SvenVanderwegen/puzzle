# ADR-0024: Sanctum CSRF bootstrap is transport plumbing, not a contract call

Status: accepted · Date: 2026-07-03 · Deciders: lead agent (WS-14 verifier finding)

## Context

Sanctum cookie auth enforces CSRF on mutating requests (`statefulApi()`).
`contracts/openapi.yaml` prescribes "the SPA first calls GET
/sanctum/csrf-cookie" in prose but does not declare the path — it is Laravel
framework plumbing with no body, no types, and no product semantics. CLAUDE.md
rule 2 requires all frontend API calls to go through the generated
`packages/api-client`; the generated client can only produce contract paths.
The WS-14 verifier proved the resulting gap: nothing ever set the XSRF cookie,
so a fresh browser's first-ever request (the mutating POST /auth/magic-link)
would 419 in production.

## Decision

A single hand-written `GET /sanctum/csrf-cookie` is sanctioned as transport
plumbing, homed in the api-client wrapper (`packages/api-client/src/client.ts`,
`csrfBootstrapUrl` option, default `/sanctum/csrf-cookie`): it fires only when
a mutating call finds no XSRF token and a token source is injected; concurrent
mutating calls share one in-flight bootstrap (deduped, re-armed after
settling); bootstrap failures are swallowed so the caller's own request
surfaces the real error. Rule 2 is NOT otherwise softened: product endpoints
still come only from `types.gen.ts`, and the openapi paths section is
unchanged (no contract surface added for a framework route).

## Consequences

Production first-POST 419 risk is closed at the transport layer for every
mutating caller. WS-17 owes an e2e that exercises the full cold-browser
magic-link loop (request → email → consume) against a real Laravel backend,
which covers this bootstrap end-to-end. WS-22's security review covers the
CSRF posture (this bootstrap, the beacon-path exemptions from WS-19).
