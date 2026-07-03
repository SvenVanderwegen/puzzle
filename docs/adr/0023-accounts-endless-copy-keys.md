# ADR-0023: COPY.md gains the accounts-UI and endless-mode keys

Status: accepted · Date: 2026-07-03 · Deciders: lead agent (WS-14/WS-11 integration)

## Context

WS-14 shipped the accounts surface (/login, /me, /settings) and needed 21
strings the frozen catalog does not cover; per the ADR-0017 quarantine pattern
it placed them in `apps/web/src/strings/proposed.ts` and flagged them for the
lead. WS-11 needed two endless-mode strings but correctly declined to add
proposed keys because the strings catalog is bundled into the committed landing
artifact (`api/resources/landing/hero.js`) — any catalog change stales that
artifact's freshness gate, and `api/` was outside WS-11's paths. It shipped a
reused `hub.play.endless` label and a glyph-only "…" pending chip as stand-ins,
the latter an a11y gap (`role="status"` announcing an ellipsis).

## Decision

COPY.md gains 23 keys in this integration range, all in the dispatcher voice:

- 21 WS-14 keys, verbatim from the quarantine (audited against the voice
  guide): `auth.email`, `auth.consuming`, `auth.expired`, `auth.signOut`,
  `settings.sound`, `settings.reducedMotion`, `settings.hideTimer`,
  `settings.highContrast`, `settings.timezone`, `settings.timezone.hint`,
  `settings.export.sent`, `settings.delete.typeToConfirm`,
  `settings.delete.word`, `settings.delete.done`, `common.cancel`,
  `me.history`, `me.history.empty`, `me.history.more`, `me.mode.endless`,
  `me.mode.pack`, `me.distributions.pending`.
- 2 WS-11 keys authored by the lead: `endless.new` ("New incident · {tier}" —
  the regenerate/retry/next-board action) and `endless.rating.pending`
  ("Rating update queued" — the queued Glicko-2 chip).

Applied in the same range: `strings.gen.ts` regenerated; the proposed-keys
quarantine dissolved back to empty (`StringKey` collapses to `CatalogKey`);
the endless stand-ins (`hub.play.endless` reuse, "…" chip) replaced with the
new keys; the landing artifact rebuilt so its freshness gate passes.

## Consequences

The quarantine pattern worked twice and stays the sanctioned route for new
strings. Note for future workstreams: catalog changes stale the committed
landing artifact — expect a `budget:landing` freshness failure in any branch
that adds proposed keys; the lead rebuilds the artifact at integration.
