/**
 * PROPOSED COPY KEYS — quarantine for strings apps/web needs before they
 * exist in contracts/COPY.md.
 *
 * Empty since ADR-0017 moved settings.title, hub.endless.solved and
 * hub.academy.progress into the frozen catalog (strings.gen.ts). If a future
 * workstream needs a new string: add it here with a justification comment,
 * flag it in STATUS.md, and the lead amends COPY.md by ADR — at which point
 * the key moves out of this file. Generated catalog keys always win
 * collisions.
 */
export const proposedCatalog = {} as const;

export type ProposedKey = keyof typeof proposedCatalog;
