/**
 * /play (Endless) stub — heading, tier chip from the validated ?tier search
 * param (rating-recommended when absent), and the generation loading line.
 * On-site generation and the board are WS-11's (data-ws area).
 */
import { useSearch } from '@tanstack/react-router';
import type { ReactElement } from 'react';
import { PageHeading } from '../chrome/PageHeading';
import { recommendedTier, tierLabel } from '../hub/tiers';
import { useLocalState } from '../state/runtime';
import { t } from '../strings';

export function PlayPage(): ReactElement {
  const search = useSearch({ strict: false });
  const state = useLocalState();
  const tier = search.tier ?? recommendedTier(state.record.rating);

  return (
    <>
      <PageHeading>{t('hub.lane.endless')}</PageHeading>
      <p>
        <span className="bf-chip bf-tier-chip" data-tier={tier}>
          {tierLabel(tier)}
        </span>
      </p>
      <section data-ws="WS-11" aria-labelledby="bf-play-area">
        <p className="bf-lane__meta" id="bf-play-area">
          {t('play.loading')}
        </p>
      </section>
    </>
  );
}
