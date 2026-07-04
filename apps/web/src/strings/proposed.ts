/**
 * PROPOSED COPY KEYS — quarantine for strings apps/web needs before they
 * exist in contracts/COPY.md.
 *
 * Process (ADR-0017/0023/0026): add a key here WITH a justification comment,
 * flag it in STATUS.md, and the lead amends COPY.md by ADR — at which point
 * the key moves out of this file into the generated catalog (strings.gen.ts),
 * which always wins collisions. `budget:landing` (the landing catalog check)
 * is expected to FAIL on a catalog change; that gate is lead-owned.
 *
 * WS-10 (Daily Burn Order) additions — the daily play surface needs two
 * strings the frozen catalog does not yet carry:
 *  - `share.action`: the client-share button label. The `## share` section of
 *    COPY.md carries the card text (headline/line2/url) and `share.copied`,
 *    but no label for the control that triggers navigator.share / clipboard.
 *  - `daily.retry`: the retry affordance when the board content fetch fails
 *    (CDN path down and the origin-fallback flag not yet flipped). COPY.md has
 *    the offline/loading lines but no reload control.
 */
export const proposedCatalog = {
  'share.action': 'Share the burn signature',
  'daily.retry': 'Try the dispatch again',
} as const;

export type ProposedKey = keyof typeof proposedCatalog;
