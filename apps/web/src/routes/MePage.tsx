/**
 * /me (WS-14): own stats. Guests see the local provisional record (product
 * §1 — everything works without an account). Signed in, the server owns the
 * numbers: Fire Rating (calibrating < 10 rated solves per RATING.md §5,
 * sparkline once rated), streak with the controlled-burn (freeze) marker,
 * cursor-paged solve history (GET /me/solves) and the distributions
 * placeholder (telemetry exists post-WS-10; no contract endpoint yet).
 */
import { useEffect, useRef, useState, type ReactElement } from 'react';
import type { components } from '@burnfront/api-client';
import { PageHeading } from '../chrome/PageHeading';
import { formatElapsed, utcDateOf, utcDayBefore } from '../state/clock';
import { withoutAccount } from '../state/localState';
import { useApi, useLocalState, useLocalStateUpdate, useRuntime } from '../state/runtime';
import { t } from '../strings';

type Me = components['schemas']['Me'];
type SolveSummary = components['schemas']['SolveSummary'];

/** "+9" / "−12" — signed delta for the rating chip (numerals, not copy). */
function formatDelta(delta: number): string {
  return delta >= 0 ? `+${String(delta)}` : `−${String(Math.abs(delta))}`;
}

function RatingChip(props: { readonly rating: Me['rating'] }): ReactElement {
  const { rating } = props;
  if (rating.calibrating) {
    return <span className="bf-chip">{t('play.stats.calibrating', { n: rating.games })}</span>;
  }
  const points = rating.sparkline ?? [];
  const last = points.at(-1);
  const previous = points.at(-2);
  const delta =
    last === undefined || previous === undefined ? 0 : Math.round(last) - Math.round(previous);
  return (
    <span className="bf-chip">
      {t('play.stats.ratingDelta', {
        rating: Math.round(rating.rating),
        delta: formatDelta(delta),
      })}
    </span>
  );
}

function Sparkline(props: { readonly points: readonly number[] }): ReactElement | null {
  const { points } = props;
  if (points.length < 2) return null;
  const min = Math.min(...points);
  const max = Math.max(...points);
  const span = max - min === 0 ? 1 : max - min;
  const coords = points
    .map((value, index) => {
      const x = (index / (points.length - 1)) * 100;
      const y = 28 - ((value - min) / span) * 24 + 2;
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    })
    .join(' ');
  return (
    <svg className="bf-sparkline" viewBox="0 0 100 32" aria-hidden="true" focusable="false">
      <polyline points={coords} fill="none" stroke="var(--bf-color-ember)" strokeWidth="2" />
    </svg>
  );
}

function historyLabel(solve: SolveSummary): string {
  if (solve.mode === 'daily' && typeof solve.incident_number === 'number') {
    return t('daily.title', { n: solve.incident_number });
  }
  return solve.mode === 'endless' ? t('me.mode.endless') : t('me.mode.pack');
}

function HistoryRow(props: { readonly solve: SolveSummary }): ReactElement {
  const { solve } = props;
  return (
    <li className="bf-history__row">
      <span>{historyLabel(solve)}</span>
      <span className="bf-lane__meta">{solve.received_at.slice(0, 10)}</span>
      {solve.valid && typeof solve.official_ms === 'number' ? (
        <span className="bf-lane__meta">
          {t('play.stats.time', { time: formatElapsed(solve.official_ms) })}
        </span>
      ) : null}
      {solve.clean ? <span className="bf-lane__meta">{t('play.stats.clean')}</span> : null}
    </li>
  );
}

function AccountRecord(): ReactElement | null {
  const api = useApi();
  const { clock } = useRuntime();
  const update = useLocalStateUpdate();
  const [me, setMe] = useState<Me | null>(null);
  const [solves, setSolves] = useState<readonly SolveSummary[] | null>(null);
  const [cursor, setCursor] = useState<string | null>(null);
  const started = useRef(false);

  useEffect(() => {
    if (started.current) return;
    started.current = true;
    void (async () => {
      try {
        const [meResult, solvesResult] = await Promise.all([api.get('/me'), api.get('/me/solves')]);
        if (meResult.status !== 200) {
          update(withoutAccount); // 401 — the session is gone.
          return;
        }
        setMe(meResult.data);
        if (solvesResult.status === 200) {
          setSolves(solvesResult.data.items);
          setCursor(solvesResult.data.next_cursor ?? null);
        }
      } catch {
        /* offline etc. — the guest note below the heading still stands */
      }
    })();
  }, [api, update]);

  async function loadMore(nextCursor: string): Promise<void> {
    try {
      const result = await api.get('/me/solves', { query: { cursor: nextCursor } });
      if (result.status !== 200) return;
      const items = result.data.items;
      setSolves((current) => [...(current ?? []), ...items]);
      setCursor(result.data.next_cursor ?? null);
    } catch {
      /* keep what we have */
    }
  }

  if (me === null) return null;

  const today = utcDateOf(clock.now());
  const streak = me.streak;
  const frozen =
    streak.current > 0 &&
    streak.last_daily_date !== undefined &&
    streak.last_daily_date !== null &&
    streak.last_daily_date !== today &&
    streak.last_daily_date !== utcDayBefore(today);

  return (
    <>
      <div className="bf-lane__row">
        <RatingChip rating={me.rating} />
        {streak.current > 0 ? (
          <span className="bf-chip bf-flame">{t('streak.days', { n: streak.current })}</span>
        ) : null}
      </div>
      {frozen ? (
        <p className="bf-flame" data-streak="frozen">
          {t('streak.frozen')}
        </p>
      ) : null}
      {me.rating.calibrating ? null : <Sparkline points={me.rating.sparkline ?? []} />}
      <section aria-labelledby="bf-me-history">
        <h2 className="bf-lane__title" id="bf-me-history">
          {t('me.history')}
        </h2>
        {solves === null || solves.length === 0 ? (
          <p className="bf-lane__meta">{t('me.history.empty')}</p>
        ) : (
          <ul className="bf-history">
            {solves.map((solve) => (
              <HistoryRow key={solve.solve_id} solve={solve} />
            ))}
          </ul>
        )}
        {cursor === null ? null : (
          <button
            className="bf-button"
            type="button"
            onClick={() => {
              void loadMore(cursor);
            }}
          >
            {t('me.history.more')}
          </button>
        )}
      </section>
      <p className="bf-hint" data-me="distributions">
        {t('me.distributions.pending')}
      </p>
    </>
  );
}

function GuestRecord(): ReactElement {
  const state = useLocalState();
  const record = state.record;
  return (
    <>
      <div className="bf-lane__row">
        <span className="bf-chip">
          {record.games < 10
            ? t('play.stats.calibrating', { n: record.games })
            : t('play.stats.ratingDelta', {
                rating: record.rating,
                delta: formatDelta(record.lastDelta),
              })}
        </span>
        {state.streak.current > 0 ? (
          <span className="bf-chip bf-flame">{t('streak.days', { n: state.streak.current })}</span>
        ) : null}
      </div>
      <p className="bf-lane__meta">{t('streak.guestNote')}</p>
    </>
  );
}

export function MePage(): ReactElement {
  const state = useLocalState();
  return (
    <>
      <PageHeading>{t('hub.lane.record')}</PageHeading>
      {state.account === null ? <GuestRecord /> : <AccountRecord />}
    </>
  );
}
