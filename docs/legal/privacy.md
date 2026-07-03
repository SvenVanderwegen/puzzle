# Privacy policy — DRAFT (WS-14)

> **Status: agent-drafted first version. NOT owner-approved. NOT legal advice.**
> The owner and a lawyer must review before launch (brief WS-14, critique #29).
> Every `TODO(owner)` below is an owner-specific fact the agent cannot invent.
> The Blade page `api/resources/views/legal/privacy.blade.php` renders this
> text with visible `[owner review: …]` placeholders; keep the two in sync.

## Who is responsible

Burnfront is operated by TODO(owner): full legal name (natural person or
company), registered address, and contact email for privacy requests.
The operator is the data controller under the GDPR.

## What we store, and why

**Playing as a guest.** The daily puzzle, endless mode and the Academy work
without an account. Your progress, streak, provisional rating and device
preferences live in your browser's local storage — strictly necessary storage,
so no consent banner is required. We receive anonymized usage events (for
example "a puzzle was started", "a hint was used") tied to a random
identifier, never to your name or email. Analytics are first-party only:
no third-party trackers, no advertising pixels, no external fonts or CDNs.

**With an account.** Accounts exist to protect your record, never to gate
play. We store: your email address (sign-in is by emailed magic link — we
never store passwords), your timezone if set (used only to time streak
protection alerts), your alert preference, and your solve record (times,
hint usage, streak, rating).

**Solve replays and abuse prevention.** Move-by-move replays of your solves
and hashed network addresses (used for rate limiting and anti-abuse) are
kept for at most 90 days, then deleted. Raw usage events are aggregated into
anonymous statistics and then purged.

**Cookies.** A session cookie and a CSRF token, both strictly necessary for
signed-in sessions. No tracking cookies.

## Email

Transactional only: sign-in links, account and security notices, and
deletion confirmations. Streak protection alerts are sent only if you turn
them on (double opt-in), at most one per day and only on days your streak
would otherwise end; every alert carries a one-click unsubscribe. No
marketing email, no digest. Email delivery events never feed analytics.

## Your rights

- **Export** — Settings → "Export my data (JSON)". A signed download link is
  emailed to you; it works once and expires after 24 hours.
- **Erasure** — Settings → "Delete my account". Your profile, streak and
  rating are erased and your solves are anonymized; only aggregate
  statistics (for example daily solve counts) survive, with nothing linking
  them to you. Deletion is queued immediately and confirmed by email.
- **Access, rectification, objection** — write to TODO(owner): privacy
  contact email.
- **Complaint** — you may complain to the Belgian Data Protection Authority
  (gegevensbeschermingsautoriteit.be) or your local supervisory authority.

## Legal bases

Performance of the service (account and solve record), consent (streak
protection alerts), and legitimate interest (aggregate statistics, abuse
prevention).

## Where data lives

TODO(owner): hosting provider, region (expected: EU region per ADR-0010
infra decision) and any sub-processors (e.g. the transactional email
provider chosen in WS-21 — must be EU-region or GDPR-adequate).

## Changes

We will announce material changes on this page. Last updated:
TODO(owner): date at approval.
