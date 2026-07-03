/**
 * /me stub — local record (provisional rating, streak, guest note); the full
 * stats surface (graphs, history, distributions) is WS-14's data-ws area.
 */
import type { ReactElement } from 'react';
import { PageHeading } from '../chrome/PageHeading';
import { useLocalState } from '../state/runtime';
import { t } from '../strings';

export function MePage(): ReactElement {
  const state = useLocalState();
  const record = state.record;
  return (
    <>
      <PageHeading>{t('hub.lane.record')}</PageHeading>
      <div className="bf-lane__row">
        <span className="bf-chip">
          {record.games < 10
            ? t('play.stats.calibrating', { n: record.games })
            : t('play.stats.ratingDelta', {
                rating: record.rating,
                delta:
                  record.lastDelta >= 0 ? `+${String(record.lastDelta)}` : String(record.lastDelta),
              })}
        </span>
        {state.streak.current > 0 ? (
          <span className="bf-chip bf-flame">{t('streak.days', { n: state.streak.current })}</span>
        ) : null}
      </div>
      {state.account === null ? <p className="bf-lane__meta">{t('streak.guestNote')}</p> : null}
      <section data-ws="WS-14" aria-labelledby="bf-me-area">
        <p className="bf-lane__meta" id="bf-me-area">
          {t('daily.loading')}
        </p>
      </section>
    </>
  );
}
