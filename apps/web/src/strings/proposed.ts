/**
 * PROPOSED COPY KEYS — NOT in contracts/COPY.md yet.
 *
 * The shell needs these three strings and the frozen catalog has no key for
 * them (same situation WS-04 hit; resolved then by ADR-0014). They are
 * quarantined here so the copy gap stays visible; the lead should either
 * amend COPY.md by ADR (moving them into strings.gen.ts) or rule them out.
 * Everything else in apps/web renders generated catalog keys only.
 */
export const proposedCatalog = {
  /** /settings page heading — a11y requires a page name; no COPY.md key names the page. */
  'settings.title': 'Settings',
  /** Endless lane, per-tier solved count (product §3: "boards solved this tier"). */
  'hub.endless.solved': '{n} contained this tier',
  /** Academy lane progress (product §3: progress "4/7 lessons"). */
  'hub.academy.progress': '{done}/{total} lessons',
} as const;

export type ProposedKey = keyof typeof proposedCatalog;
