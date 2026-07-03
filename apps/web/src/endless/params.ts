/**
 * Endless tier dials (brief WS-11): board geometry per hub/tiers.ts plus the
 * break counts and clue floors the engine's generator is dialed with.
 * minClues mirrors packages/engine/src/perf.test.ts (the values the engine's
 * own perf bounds are certified at), keeping worker generation fast.
 */
import type { BoardSpec } from '@burnfront/engine';
import { TIER_SIZE, TIERS } from '../hub/tiers';
import type { Tier } from '../state/localState';

export interface TierDials {
  readonly rows: number;
  readonly cols: number;
  readonly breaks: number;
  readonly minClues: number;
}

export const TIER_DIALS: Readonly<Record<Tier, TierDials>> = {
  lookout: { ...TIER_SIZE.lookout, breaks: 4, minClues: 5 },
  crew: { ...TIER_SIZE.crew, breaks: 8, minClues: 8 },
  hotshot: { ...TIER_SIZE.hotshot, breaks: 12, minClues: 12 },
};

/** The tier a board was generated at, or null for foreign geometry. */
export function tierOfBoard(board: BoardSpec): Tier | null {
  for (const tier of TIERS) {
    const dials = TIER_DIALS[tier];
    if (board.rows === dials.rows && board.cols === dials.cols && board.breaks === dials.breaks) {
      return tier;
    }
  }
  return null;
}
