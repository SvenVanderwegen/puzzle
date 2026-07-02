# ADR-0003: Magic-link-only authentication in v1

Status: accepted · Date: 2026-07-02 · Deciders: owner + lead agent

## Context
Designs disagreed (passwordless+social vs magic-link-only vs password reset flows). Social
providers add OAuth registrations, an Apple developer account, and account-linking QA.

## Decision
Email magic link only. No passwords stored, ever. `auth_identities(provider, provider_uid)`
exists from day one so Google/Apple become additive rows later. Token rules: single-use,
15-minute TTL, hashed at rest, constant response regardless of account existence, throttle
3/hour/email + 5/min/IP, session rotated on consume.

## Consequences
Smallest GDPR/breach surface and no Fortify password machinery. Deliverability matters:
the ESP (WS-21) is launch-critical. Mobile phase 2 reuses the same flow + Sanctum tokens.
