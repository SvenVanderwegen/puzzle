/**
 * PROPOSED COPY KEYS — quarantine for strings apps/web needs before they
 * exist in contracts/COPY.md.
 *
 * Process (ADR-0017/ADR-0023 pattern): add a key here with a justification
 * comment, flag it in STATUS.md, and the lead amends COPY.md by ADR — at
 * which point the key moves into the generated catalog (strings.gen.ts) and
 * out of this file. Generated catalog keys always win collisions.
 *
 * WS-20 (lead-directed fix-up): `streak.protect.capped` — the day-3 nudge
 * (`streak.protect`) overpromises for guests whose local streak exceeds 7:
 * the account merge carries at most the trailing 7 days (anti-fabrication
 * cap, openapi importLocalRecord). This variant states the real behavior in
 * dispatcher voice; the nudge switches to it when local streak > 7.
 */
export const proposedCatalog = {
  'streak.protect.capped':
    '{n}-day streak in this browser. An account carries the last 7 days forward — and every day after.',
} as const;

export type ProposedKey = keyof typeof proposedCatalog;
