/**
 * /daily/{-$date} stub — chrome, heading and catalog strings only; the play
 * surface (board, streak UI, share) is WS-10's and replaces the data-ws area.
 */
import { Link, useParams } from '@tanstack/react-router';
import type { ReactElement } from 'react';
import { PageHeading } from '../chrome/PageHeading';
import { useOnline } from '../chrome/useOnline';
import { utcDateOf } from '../state/clock';
import { useRuntime } from '../state/runtime';
import { t } from '../strings';

/** English weekday name for a UTC date — locale-rendered, not literal copy. */
function weekdayOf(date: string): string {
  const ms = Date.parse(`${date}T00:00:00Z`);
  return new Intl.DateTimeFormat('en', { weekday: 'long', timeZone: 'UTC' }).format(ms);
}

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

export function DailyPage(): ReactElement {
  const { clock } = useRuntime();
  const online = useOnline();
  const params = useParams({ strict: false });
  const today = utcDateOf(clock.now());
  const date = params.date !== undefined && DATE_RE.test(params.date) ? params.date : null;
  const isPast = date !== null && date !== today;

  return (
    <>
      <PageHeading>{t('hub.lane.daily')}</PageHeading>
      {isPast ? (
        <p className="bf-hint">
          <Link to="/daily/{-$date}">{t('daily.pastBanner', { weekday: weekdayOf(date) })}</Link>
        </p>
      ) : null}
      <section data-ws="WS-10" aria-labelledby="bf-daily-area">
        <p className="bf-lane__meta" id="bf-daily-area">
          {online ? t('daily.loading') : t('daily.offline')}
        </p>
      </section>
    </>
  );
}
