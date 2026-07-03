/**
 * Landing-only copy quarantine (WS-15).
 *
 * These strings are specified verbatim by docs/design/product.md §2 (the
 * landing spec) but have NO contracts/COPY.md key yet. Same policy as
 * WS-04's typed props / WS-09's proposed.ts: consumed from one flagged
 * place, never inline, so the copy gap stays visible for a lead ADR
 * (flagged in tasks/WS-15/STATUS.md). Everything else the hero module
 * renders comes from the generated COPY.md catalog via `t`.
 */
export const landingCopy = {
  /** product.md §2.1 — the post-solve card on the hero board. */
  'landing.hero.solved': "That's the game. A new one drops every midnight →",
} as const;
