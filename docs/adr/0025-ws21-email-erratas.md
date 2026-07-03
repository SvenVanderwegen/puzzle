# ADR-0025: WS-21 erratas — email copy keys, hours plural, opt-in semantics

Status: accepted · Date: 2026-07-03 · Deciders: lead agent (WS-21 verifier findings)

## Context

WS-21 shipped the transactional email layer. Three contract-adjacent items
surfaced: (1) its new mailables carry in-voice copy that has no COPY.md keys
(the verifier produced the complete inventory); (2) `email.streak.subject`
("… has {hours} hours.") renders "1 hours" at ≤60 minutes to the deadline —
confirmed in output; the builder correctly refused to patch contract-law text;
(3) the brief's "double opt-in enforced" box is unsatisfiable as written: the
users schema holds a single boolean (frozen) and WS-14's shipped UI pins
PATCH → immediate flip, while openapi's `streak_alert_opt_in` description
read "Double opt-in starts on true (WS-21)".

## Decision

1. **Copy keys.** COPY.md ## email gains the shipped strings verbatim (audited
   against the voice guide): `email.streak.play`, `email.streak.unsubscribe`,
   `email.subscribed.subject|body|unsubscribe`, `email.deleted.subject|body|
   noFurther`, `email.export.subject` (WS-06 text, now cataloged),
   `email.signature`, and the unsubscribe landing page set
   `email.unsubscribed.title|body|back|confirm`. The api side keeps literal
   template text pinned to COPY.md by test (no PHP strings module); COPY.md
   remains authoritative.
2. **Plural errata.** `email.streak.subject` becomes
   "Your {n}-day streak has {hours, plural, one {# hour} other {# hours}}."
   Renderers expand the ICU plural (web: icu.ts natively; api: PHP-side
   expansion in the mailable, pinned by a test that expands the clause from
   COPY.md at test time).
3. **Opt-in semantics.** The shipped flow is RATIFIED as satisfying the
   brief's intent for a transactional alert: the address is verified by
   construction (magic-link-only auth, ADR-0003), enrollment is an explicit
   authenticated toggle, and a confirmation email with a no-login one-click
   revoke goes out on every false→true flip. The openapi field description is
   amended to match ("Opt-in with verified-address confirmation email and
   one-click revoke"). A strict double opt-in (pending-confirm state) would
   require a schema change and a WS-14 UI rework for marginal abuse value on
   a magic-link-verified address; anyone who wants it later writes a new ADR.

## Consequences

COPY.md and openapi.yaml amended in-range with this ADR (freeze rule).
Consumers updated in the same cycle: strings.gen.ts regenerated, api-client
regenerated, landing artifact rebuilt, api subject renderer + pin updated.
