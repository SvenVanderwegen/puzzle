/**
 * The hub (product §3): the big Play button (five-state decision table in
 * playButton.ts), then exactly the five lanes — Daily hero, Endless, Academy,
 * Your record, and the muted Rush footer strip. Lane internals are shell
 * placeholders wired to the anonymous-first local state; the owning
 * workstream replaces each `data-ws` area.
 */
import { Link } from '@tanstack/react-router';
import type { ReactElement } from 'react';
import { PageHeading } from '../chrome/PageHeading';
import { useOnline } from '../chrome/useOnline';
import { formatElapsed, utcDateOf } from '../state/clock';
import type { LocalState } from '../state/localState';
import { useLocalState, useRuntime } from '../state/runtime';
import { t } from '../strings';
import { decidePlayButton, type PlayDecision } from './playButton';
import { recommendedTier, tierLabel, TIERS } from './tiers';
import { useCountdown } from './useCountdown';

function PlayButton(props: { readonly decision: PlayDecision }): ReactElement {
  const { decision } = props;
  const label = t(decision.labelKey, decision.labelParams);
  if (decision.to === '/play') {
    return (
      <Link
        className="bf-play"
        to="/play"
        search={decision.tier === undefined ? {} : { tier: decision.tier }}
        data-decision={decision.kind}
      >
        {label}
      </Link>
    );
  }
  if (decision.to === '/academy') {
    // First Shift funnel (WS-12): route into the lesson, not the daily.
    return (
      <Link
        className="bf-play"
        to="/academy/$slug"
        params={{ slug: decision.slug ?? 'first-shift' }}
        data-decision={decision.kind}
      >
        {label}
      </Link>
    );
  }
  return (
    <Link className="bf-play" to="/daily/{-$date}" data-decision={decision.kind}>
      {label}
    </Link>
  );
}

/** "+9" / "−12" — signed delta for the rating chip (numerals, not copy). */
function formatDelta(delta: number): string {
  return delta >= 0 ? `+${String(delta)}` : `−${String(Math.abs(delta))}`;
}

function DailyLane(props: { readonly state: LocalState; readonly today: string }): ReactElement {
  const online = useOnline();
  const daily = props.state.daily?.date === props.today ? props.state.daily : null;
  const streak = props.state.streak.current;
  return (
    <section className="bf-lane" aria-labelledby="bf-lane-daily" data-ws="WS-10">
      <h2 className="bf-lane__title" id="bf-lane-daily">
        <Link to="/daily/{-$date}">{t('hub.lane.daily')}</Link>
      </h2>
      {daily === null ? null : (
        <p className="bf-lane__meta" data-daily-state={daily.status}>
          {daily.status === 'in_progress'
            ? t('hub.play.resume', { elapsed: formatElapsed(daily.elapsedMs) })
            : t('play.stats.time', { time: formatElapsed(daily.containedMs ?? daily.elapsedMs) })}
        </p>
      )}
      <p className="bf-lane__meta">{online ? t('daily.loading') : t('daily.offline')}</p>
      {streak > 0 ? (
        <p className="bf-flame" data-streak={streak}>
          {t('streak.days', { n: streak })}
        </p>
      ) : null}
    </section>
  );
}

function EndlessLane(props: { readonly state: LocalState }): ReactElement {
  const recommended = recommendedTier(props.state.record.rating);
  const solved = props.state.endless.solvedByTier;
  return (
    <section className="bf-lane" aria-labelledby="bf-lane-endless" data-ws="WS-11">
      <h2 className="bf-lane__title" id="bf-lane-endless">
        <Link to="/play">{t('hub.lane.endless')}</Link>
      </h2>
      <div className="bf-lane__row">
        {TIERS.map((tier) => (
          <Link
            key={tier}
            className="bf-chip bf-tier-chip"
            to="/play"
            search={{ tier }}
            data-recommended={tier === recommended}
          >
            {tierLabel(tier)}
          </Link>
        ))}
      </div>
      <p className="bf-lane__meta">{t('hub.endless.solved', { n: solved[recommended] })}</p>
    </section>
  );
}

function AcademyLane(props: { readonly state: LocalState }): ReactElement {
  const academy = props.state.academy;
  const certified = academy.total > 0 && academy.done >= academy.total;
  return (
    <section className="bf-lane" aria-labelledby="bf-lane-academy" data-ws="WS-12">
      <h2 className="bf-lane__title" id="bf-lane-academy">
        <Link to="/academy">{t('hub.lane.academy')}</Link>
      </h2>
      <div className="bf-lane__row">
        <span className="bf-lane__meta">
          {t('hub.academy.progress', { done: academy.done, total: academy.total })}
        </span>
        {certified ? (
          <span className="bf-chip" data-certified="true">
            {t('academy.certified')}
          </span>
        ) : null}
      </div>
    </section>
  );
}

function RecordLane(props: { readonly state: LocalState }): ReactElement {
  const record = props.state.record;
  return (
    <section className="bf-lane" aria-labelledby="bf-lane-record" data-ws="WS-14">
      <h2 className="bf-lane__title" id="bf-lane-record">
        <Link to="/me">{t('hub.lane.record')}</Link>
      </h2>
      <div className="bf-lane__row">
        <span className="bf-chip">
          {record.games < 10
            ? t('play.stats.calibrating', { n: record.games })
            : t('play.stats.ratingDelta', {
                rating: record.rating,
                delta: formatDelta(record.lastDelta),
              })}
        </span>
        {props.state.account === null ? <span className="bf-chip">{t('hub.guest')}</span> : null}
      </div>
      {props.state.account === null ? (
        <p className="bf-lane__meta">{t('streak.guestNote')}</p>
      ) : null}
    </section>
  );
}

export function HubPage(): ReactElement {
  const { clock } = useRuntime();
  const state = useLocalState();
  const today = utcDateOf(clock.now());
  const countdown = useCountdown(clock);
  const decision = decidePlayButton(state, today);

  return (
    <>
      <PageHeading>{t('app.title')}</PageHeading>
      <PlayButton decision={decision} />
      <p className="bf-countdown">
        {t('hub.countdown', { hh: countdown.hh, mm: countdown.mm, ss: countdown.ss })}
      </p>
      <DailyLane state={state} today={today} />
      <EndlessLane state={state} />
      <AcademyLane state={state} />
      <RecordLane state={state} />
      <p className="bf-rush">{t('hub.lane.rush')}</p>
    </>
  );
}
