/**
 * /play (Endless, WS-11). Tier resolution: explicit ?tier search param, else
 * the persisted dial, else the rating-recommended tier (hub/tiers.ts). The
 * feature itself — worker generation, session, replay, submission — lives in
 * src/endless/EndlessPlay.
 */
import { useSearch } from '@tanstack/react-router';
import { useMemo, type ReactElement } from 'react';
import { PageHeading } from '../chrome/PageHeading';
import { EndlessPlay } from '../endless/EndlessPlay';
import { loadPrefs } from '../endless/prefs';
import { recommendedTier } from '../hub/tiers';
import { useLocalState, useRuntime } from '../state/runtime';
import { t } from '../strings';

export function PlayPage(): ReactElement {
  const search = useSearch({ strict: false });
  const { storage } = useRuntime();
  const state = useLocalState();
  const dial = useMemo(() => loadPrefs(storage).dial, [storage]);
  const tier = search.tier ?? dial ?? recommendedTier(state.record.rating);

  return (
    <>
      <PageHeading>{t('hub.lane.endless')}</PageHeading>
      <section data-ws="WS-11">
        <EndlessPlay tier={tier} />
      </section>
    </>
  );
}
