/**
 * Tier facts (product §3/§5: Lookout 5×5 · Crew 6×6 · Hotshot 7×7) and the
 * rating→recommended-tier mapping the Endless lane and Play button use.
 */
import type { Tier } from '../state/localState';
import { t } from '../strings';

export const TIERS: readonly Tier[] = ['lookout', 'crew', 'hotshot'];

export const TIER_SIZE: Readonly<Record<Tier, { rows: number; cols: number }>> = {
  lookout: { rows: 5, cols: 5 },
  crew: { rows: 6, cols: 6 },
  hotshot: { rows: 7, cols: 7 },
};

const TIER_NAME_KEY = {
  lookout: 'tier.lookout',
  crew: 'tier.crew',
  hotshot: 'tier.hotshot',
} as const;

/** "Crew 6×6" — the tier.size template filled for a tier. */
export function tierLabel(tier: Tier): string {
  const size = TIER_SIZE[tier];
  return t('tier.size', { tier: t(TIER_NAME_KEY[tier]), rows: size.rows, cols: size.cols });
}

/**
 * Rating-recommended tier. Provisional local ratings start at 1200 (Crew);
 * the bands mirror the difficulty ladder until the server rating (WS-08)
 * takes over post-account.
 */
export function recommendedTier(rating: number): Tier {
  if (rating < 1100) return 'lookout';
  if (rating < 1450) return 'crew';
  return 'hotshot';
}
