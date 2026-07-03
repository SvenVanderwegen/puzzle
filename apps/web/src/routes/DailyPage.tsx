/**
 * /daily/{-$date} (WS-10) — chrome + heading + past-incident banner, wrapping
 * the DailyPlay surface (board, streak UI, share). An absent or malformed date
 * param resolves to today; a valid past date (last 7 days) is playable with the
 * "today's is live" banner and no streak credit.
 */
import { Link, useParams } from '@tanstack/react-router';
import type { ReactElement } from 'react';
import { PageHeading } from '../chrome/PageHeading';
import { DailyPlay } from '../daily/DailyPlay';
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
  const params = useParams({ strict: false });
  const today = utcDateOf(clock.now());
  const date = params.date !== undefined && DATE_RE.test(params.date) ? params.date : today;
  const isPast = date < today;

  return (
    <>
      <PageHeading>{t('hub.lane.daily')}</PageHeading>
      {isPast ? (
        <p className="bf-hint">
          <Link to="/daily/{-$date}">{t('daily.pastBanner', { weekday: weekdayOf(date) })}</Link>
        </p>
      ) : null}
      <section data-ws="WS-10">
        <DailyPlay date={date} />
      </section>
    </>
  );
}
