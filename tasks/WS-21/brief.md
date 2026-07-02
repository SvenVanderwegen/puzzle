# WS-21: Transactional email

Lane: B · Deps: WS-06 · Sessions: 1

## Scope
EU-compliant ESP integration (EU region or EU processor; document the DPA + region choice
in `docs/gdpr.md` — critique #20): templates for magic link, deletion confirmation, export
link, and the **streak-risk alert** (opt-in at signup, default OFF, double opt-in; sent at
20:00 user-local via `users.timezone` by a `notifications:streak-risk` scheduled command,
ONLY on days the streak would die unsolved; copy per product §7). List-unsubscribe header;
one-click unsubscribe; email events never feed analytics. Plain, fast, on-voice templates
(dispatcher tone, no images required to read).

## Inputs
WS-06 mail abstraction, `contracts/COPY.md` email strings, owner ESP account (Blocker if
absent — develop against mailpit).

## Outputs
Mailables + templates, scheduled command, double-opt-in flow, tests.

## Acceptance
- [ ] Streak-risk logic: fires only when unsolved + streak ≥ 2 + opted-in; respects
      timezone; never twice per day (tests across UTC edges)
- [ ] Double opt-in enforced; unsubscribe one-click; headers present
- [ ] Deliverability checklist documented (SPF/DKIM/DMARC — owner DNS actions listed)
- [ ] All templates render text-only cleanly

## Non-goals
No marketing/digest emails to users, no ESP webhooks/analytics ingestion.
