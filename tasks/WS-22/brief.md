# WS-22: Security review (hardening session)

Lane: hardening (P4) · Deps: all API surface merged · Sessions: 1

## Scope
Adversarial pass over the deployed staging stack, not just code reading: auth (magic-link
enumeration/timing, session fixation, CSRF on state-changing routes), solves (idempotency
abuse, clock lies both directions, replay forgery, oversized payloads), `POST /me/import`
(fabrication, quota abuse), events/errors endpoints (write amplification, log injection),
export links (signing, expiry, single-use), content import (signature bypass attempts).
Author the final CSP + security headers (HSTS, frame-ancestors, nonce/hash for the landing
hero inline script — critique #19) and flip CSP from report-only to enforced. Run
`/security-review` tooling against `api/` plus manual checks; file findings as fix-up
briefs; re-test after fixes.

## Inputs
Staging environment, all briefs' threat notes, WS-14 CSP report-only data.

## Outputs
Findings report in `tasks/WS-22/STATUS.md`, fix-up briefs, enforced security headers,
`docs/RUNBOOK.md` security section.

## Acceptance
- [ ] Every finding either fixed + re-tested or explicitly risk-accepted by the owner
- [ ] CSP enforced with zero violations across the e2e suite
- [ ] securityheaders.com A rating on staging (documented screenshot/output)
- [ ] Rate-limit matrix verified endpoint-by-endpoint against openapi.yaml declarations

## Non-goals
No external pentest procurement (post-revenue), no bug bounty.
