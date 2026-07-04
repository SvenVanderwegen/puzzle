/**
 * Daily Burn Order (/daily/{-$date}, WS-10) — the daily loop, the product's
 * core. Load today's board (CDN content URL first, origin-embedded fallback),
 * play through game-core + ui-web exactly like Endless, then the win flow:
 * BurnReplay → stats card (time · percentile/rank · rating chip · streak flame
 * with the "controlled burn" freeze ring · clean-contain · spoiler-free share
 * · tomorrow tease). Signed-in solves submit through the api-client with a
 * UUIDv7 Idempotency-Key and retry idempotently on reconnect; guests keep a
 * local record (WS-20 solve log). Past dates (last 7 days) are playable with no
 * streak credit — the server rules, the surface reflects it honestly.
 */
import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from 'react';
import type { ReactElement } from 'react';
import { shadingToBits, type BurnResult } from '@burnfront/engine';
import {
  loadSnapshot,
  PlaySession,
  revealSequence,
  saveSnapshot,
  uuidV7,
  type RevealSequence,
} from '@burnfront/game-core';
import { Board, BreaksCounter, BurnReplay, uiWebCss } from '@burnfront/ui-web';
import { useOnline } from '../chrome/useOnline';
import { useCountdown } from '../hub/useCountdown';
import { tierLabel } from '../hub/tiers';
import { formatElapsed, utcDateOf, utcDayBefore } from '../state/clock';
import { appendSolveLog } from '../state/localState';
import { toKeyValueStorage } from '../endless/storage';
import { formatDelta, ratingDelta } from '../endless/submit';
import { useLocalState, useRuntime, useApi } from '../state/runtime';
import { t } from '../strings';
import { createDailyApi, type DailyData } from './api';
import { useDailyDeps } from './deps';
import {
  creditLocalStreak,
  markDailyContained,
  markDailyInProgress,
  readLocalStreak,
  writeLocalStreak,
} from './localDaily';
import { shareOrCopy } from './share';
import { StreakFlame } from './StreakFlame';
import { wireBoardToSpec } from './board';
import {
  assembleDailyRecord,
  retryPendingDaily,
  submitDaily,
  type DailySubmissionState,
} from './submit';

const DAILY_SESSION_KEY = 'burnfront.daily.session';
const DAY_MS = 86_400_000;

const BOARD_STRINGS = {
  'a11y.cell.empty': t('a11y.cell.empty'),
  'a11y.cell.break': t('a11y.cell.break'),
  'a11y.cell.dot': t('a11y.cell.dot'),
  'a11y.cell.clue': t('a11y.cell.clue'),
  'a11y.cell.spark': t('a11y.cell.spark'),
} as const;

const REPLAY_STRINGS = {
  'a11y.replay.minute': t('a11y.replay.minute'),
  'a11y.contained': t('a11y.contained'),
  'play.contained': t('play.contained'),
} as const;

const REPLAY_LABELS = {
  watchAgain: t('replay.watchAgain'),
  nextMinute: t('replay.nextMinute'),
  previousMinute: t('replay.previousMinute'),
} as const;

const HUD_STRINGS = { 'play.breaks': t('play.breaks') } as const;

type Stage = 'loading' | 'offline' | 'error' | 'playing' | 'won';

interface Finale {
  readonly result: BurnResult;
  readonly sequence: RevealSequence;
  readonly elapsedMs: number;
  readonly clean: boolean;
}

interface StreakView {
  readonly streak: number;
  readonly frozen: boolean;
}

interface TomorrowTease {
  readonly incident: number;
  readonly tier: string;
  readonly saturday: boolean;
}

export interface DailyPlayProps {
  /** The UTC incident date to play (DailyPage resolves the route param). */
  readonly date: string;
}

/** The UTC date one day after `date`. */
function utcDayAfter(date: string): string {
  return utcDateOf(Date.parse(`${date}T00:00:00Z`) + DAY_MS);
}

function weekdayOf(date: string): string {
  return new Intl.DateTimeFormat('en', { weekday: 'long', timeZone: 'UTC' }).format(
    Date.parse(`${date}T00:00:00Z`),
  );
}

/**
 * Did a freeze/coverage hold the streak through a missed day? Derived because
 * the contract's Streak carries no per-solve freeze bit: a real gap (last
 * contain earlier than yesterday) that still produced a continued run (>1)
 * means the day between was frozen/amnestied — "controlled burn".
 */
function streakFrozen(
  lastDailyDate: string | null,
  previousCurrent: number,
  serverCurrent: number,
  today: string,
): boolean {
  if (lastDailyDate === null || previousCurrent <= 0) return false;
  const gap = lastDailyDate < utcDayBefore(today);
  return gap && serverCurrent > 1;
}

export function DailyPlay({ date }: DailyPlayProps): ReactElement {
  const runtime = useRuntime();
  const state = useLocalState();
  const client = useApi();
  const deps = useDailyDeps();
  const online = useOnline();
  // Mirror connectivity into a ref so an in-flight getDaily that resolves AFTER
  // a disconnect reads live status, not the stale value captured when the load
  // began (jsdom keeps navigator.onLine true, so the event-driven state is the
  // only source of truth).
  const onlineRef = useRef(online);
  onlineRef.current = online;
  const countdown = useCountdown(runtime.clock);
  const api = useMemo(() => createDailyApi(client), [client]);
  const kv = useMemo(() => toKeyValueStorage(runtime.storage), [runtime.storage]);

  const today = utcDateOf(runtime.clock.now());
  const isPast = date < today;
  const signedIn = state.account !== null;

  const [stage, setStage] = useState<Stage>('loading');
  const [daily, setDaily] = useState<DailyData | null>(null);
  const [session, setSession] = useState<PlaySession | null>(null);
  const [wrong, setWrong] = useState(false);
  const [finale, setFinale] = useState<Finale | null>(null);
  const [submission, setSubmission] = useState<DailySubmissionState>({ kind: 'none' });
  const [streakView, setStreakView] = useState<StreakView | null>(null);
  const [tomorrow, setTomorrow] = useState<TomorrowTease | null>(null);
  const [shareDone, setShareDone] = useState(false);
  const [, bump] = useReducer((n: number) => n + 1, 0);
  const mounted = useRef(true);

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);

  const setStateIfMounted = useCallback(<T,>(setter: (v: T) => void, value: T) => {
    if (mounted.current) setter(value);
  }, []);

  const showWon = useCallback(
    (finished: Finale) => {
      setStateIfMounted(setFinale, finished);
      setStateIfMounted(setStage, 'won');
    },
    [setStateIfMounted],
  );

  // ---- board load ----------------------------------------------------------

  const beginBoard = useCallback(
    (meta: DailyData) => {
      const embedded = meta.puzzle === undefined ? null : wireBoardToSpec(meta.puzzle);
      const boardFrom = (spec: ReturnType<typeof wireBoardToSpec>): boolean => {
        if (spec === null) return false;
        const snapshot = loadSnapshot(kv, DAILY_SESSION_KEY);
        const resumable =
          snapshot !== null && snapshot.mode === 'daily' && snapshot.puzzleId === meta.puzzle_id;
        const fresh = resumable
          ? PlaySession.restore(snapshot, runtime.clock)
          : new PlaySession(
              { board: spec, mode: 'daily', puzzleId: meta.puzzle_id },
              runtime.clock,
            );
        fresh.start();
        saveSnapshot(kv, DAILY_SESSION_KEY, fresh.snapshot());
        setStateIfMounted(setSession, fresh);

        const contained = state.daily?.date === date && state.daily.status === 'contained';
        if (resumable && contained) {
          // Reload of an already-contained daily (incl. PWA offline replay):
          // reconstruct the win view from the persisted shading.
          const shading = fresh.shading();
          const sequence = revealSequence(fresh.board, shading);
          const hints = fresh.hintCounts();
          showWon({
            result: sequence.result,
            sequence,
            elapsedMs: state.daily.containedMs ?? fresh.elapsedMs(),
            clean: hints.s1 === 0 && hints.s2 === 0 && hints.s3 === 0,
          });
          return true;
        }

        markDailyInProgress(runtime.storage, date, fresh.elapsedMs());
        if (signedIn && online) void api.startDaily(date);
        setStateIfMounted(setStage, 'playing');
        return true;
      };

      if (embedded !== null) {
        if (boardFrom(embedded)) return;
      }
      void deps.content.fetchBoard(meta.content_url).then((wire) => {
        if (!mounted.current) return;
        const spec = wire === null ? null : wireBoardToSpec(wire);
        if (spec === null || !boardFrom(spec)) setStateIfMounted(setStage, 'error');
      });
    },
    [api, date, deps, kv, online, runtime, setStateIfMounted, showWon, signedIn, state.daily],
  );

  const loadBoard = useCallback(() => {
    setStateIfMounted(setStage, 'loading');
    void api.getDaily(date).then((outcome) => {
      if (!mounted.current) return;
      if (outcome.kind === 'ok') {
        setStateIfMounted(setDaily, outcome.daily);
        beginBoard(outcome.daily);
        return;
      }
      if (outcome.kind === 'error') {
        setStateIfMounted(setStage, onlineRef.current ? 'error' : 'offline');
        return;
      }
      // not_found: no incident for this date (future/unpublished).
      setStateIfMounted(setStage, 'error');
    });
  }, [api, beginBoard, date, online, setStateIfMounted]);

  useEffect(() => {
    // A fresh date invalidates a mismatched snapshot lazily (beginBoard checks
    // puzzle_id); reset transient view state and (re)load.
    setDaily(null);
    setSession(null);
    setFinale(null);
    setSubmission({ kind: 'none' });
    setStreakView(null);
    setTomorrow(null);
    setShareDone(false);
    setWrong(false);
    if (!online) {
      // Try to resume a persisted in-progress board offline; else offline notice.
      const snapshot = loadSnapshot(kv, DAILY_SESSION_KEY);
      if (snapshot !== null && snapshot.mode === 'daily') {
        const restored = PlaySession.restore(snapshot, runtime.clock);
        restored.start();
        setSession(restored);
        setStage('playing');
        return;
      }
      setStage('offline');
      return;
    }
    loadBoard();
    // Deliberately keyed on `date` only; connectivity changes are handled by
    // the sibling effect below. (react-hooks/exhaustive-deps is not enabled.)
  }, [date]);

  // Reconnect: reload when we came online without a board, and flush any queued
  // (offline-solved) daily submission idempotently.
  useEffect(() => {
    if (!online) {
      // Lost connectivity: surface the offline notice unless a board is already
      // in hand — an in-progress or contained daily keeps its persisted session
      // (offline replay), so only the pre-board stages fall back to the notice.
      setStage((s) => (s === 'playing' || s === 'won' ? s : 'offline'));
      return;
    }
    if (stage === 'offline') loadBoard();
    void retryPendingDaily(api, runtime.storage, (next) => {
      setStateIfMounted(setSubmission, next);
    });
    // Runs on connectivity flips. (react-hooks/exhaustive-deps is not enabled.)
  }, [online]);

  // ---- pre-cache tomorrow --------------------------------------------------

  const preCacheTomorrow = useCallback(() => {
    const nextDate = utcDayAfter(today);
    void api.getDaily(nextDate).then((outcome) => {
      if (!mounted.current || outcome.kind !== 'ok') return; // 404 pre-publish: silent.
      const meta = outcome.daily;
      setStateIfMounted(setTomorrow, {
        incident: meta.incident_number,
        tier: tierLabel(meta.grade_tier),
        saturday: weekdayOf(nextDate) === 'Saturday',
      });
      // Warm the CDN cache for tomorrow's board (ignore the result).
      if (meta.puzzle === undefined) void deps.content.fetchBoard(meta.content_url);
    });
  }, [api, deps, setStateIfMounted, today]);

  // ---- play ----------------------------------------------------------------

  const handleChange = useCallback(() => {
    bump();
    if (session === null || stage !== 'playing') return;
    saveSnapshot(kv, DAILY_SESSION_KEY, session.snapshot());
    markDailyInProgress(runtime.storage, date, session.elapsedMs());
    if (session.completion() === null) setWrong(false);
  }, [date, kv, runtime.storage, session, stage]);

  const guestRecord = useCallback(
    (finished: Finale) => {
      const previous = readLocalStreak(runtime.storage);
      const credited = creditLocalStreak(previous, date, today);
      writeLocalStreak(runtime.storage, credited);
      setStateIfMounted(setStreakView, isPast ? null : { streak: credited.current, frozen: false });
      const nowMs = runtime.clock.now();
      const hints = session?.hintCounts() ?? { s1: 0, s2: 0, s3: 0 };
      const shading = session?.shading() ?? [];
      appendSolveLog(runtime.storage, {
        clientSolveId: uuidV7(nowMs, deps.recordEnv.rng),
        mode: 'daily',
        date,
        shaded: shadingToBits(shading),
        clientMs: Math.min(86_400_000, Math.max(0, Math.round(finished.elapsedMs))),
        hints: {
          s1: Math.min(200, hints.s1),
          s2: Math.min(200, hints.s2),
          s3: Math.min(200, hints.s3),
        },
        solvedAt: new Date(nowMs).toISOString(),
      });
    },
    [date, deps, isPast, runtime, session, setStateIfMounted, today],
  );

  const handleComplete = useCallback(
    (result: BurnResult) => {
      if (session === null || stage !== 'playing') return;
      if (!result.valid) {
        setWrong(true);
        return;
      }
      session.pause();
      const elapsedMs = session.elapsedMs();
      const shading = session.shading();
      const sequence = revealSequence(session.board, shading);
      const hints = session.hintCounts();
      const clean = hints.s1 === 0 && hints.s2 === 0 && hints.s3 === 0;
      const finished: Finale = { result, sequence, elapsedMs, clean };
      // Keep the snapshot: a reload reconstructs this exact win view (PWA).
      saveSnapshot(kv, DAILY_SESSION_KEY, session.snapshot());
      markDailyContained(runtime.storage, date, elapsedMs);
      setWrong(false);
      showWon(finished);
      preCacheTomorrow();

      if (!signedIn) {
        guestRecord(finished);
        return;
      }

      const previous = readLocalStreak(runtime.storage);
      void assembleDailyRecord(session, deps.recordEnv, runtime.clock).then((record) =>
        submitDaily(record, date, api, runtime.storage, (next) => {
          if (!mounted.current) return;
          setSubmission(next);
          if (next.kind === 'accepted' && next.result.streak !== undefined) {
            const server = next.result.streak;
            const frozen = streakFrozen(
              previous.lastDailyDate,
              previous.current,
              server.current,
              today,
            );
            if (!isPast) {
              writeLocalStreak(runtime.storage, {
                current: server.current,
                best: server.best,
                lastDailyDate: server.last_daily_date ?? today,
              });
              setStreakView({ streak: server.current, frozen });
            }
          } else if (next.kind === 'unauthenticated') {
            // Session expired mid-solve: keep the record as a guest would.
            guestRecord(finished);
          }
        }),
      );
    },
    [
      api,
      date,
      deps,
      guestRecord,
      isPast,
      kv,
      runtime,
      session,
      showWon,
      signedIn,
      stage,
      today,
      preCacheTomorrow,
    ],
  );

  // ---- share ---------------------------------------------------------------

  const onShare = useCallback(() => {
    if (finale === null || daily === null) return;
    const streak = streakView?.streak ?? state.streak.current;
    void shareOrCopy(
      {
        incident: daily.incident_number,
        date,
        timeText: formatElapsed(finale.elapsedMs),
        clean: finale.clean,
        streak,
        sequence: finale.sequence,
      },
      deps.shareEnv,
    ).then((outcome) => {
      if (outcome === 'copied') setStateIfMounted(setShareDone, true);
    });
  }, [daily, date, deps, finale, setStateIfMounted, state.streak.current, streakView]);

  // ---- render --------------------------------------------------------------

  return (
    <div className="bf-daily" data-testid="daily-play">
      <style>{uiWebCss}</style>

      {stage === 'loading' ? (
        <p className="bf-lane__meta" role="status" data-testid="daily-loading">
          {t('daily.loading')}
        </p>
      ) : null}

      {stage === 'offline' ? (
        <p className="bf-lane__meta" role="status">
          {t('daily.offline')}
        </p>
      ) : null}

      {stage === 'error' ? (
        <div>
          <p className="bf-lane__meta" role="alert">
            {t('error.generic')}
          </p>
          <button type="button" className="bf-chip" onClick={loadBoard}>
            {t('daily.retry')}
          </button>
        </div>
      ) : null}

      {stage === 'playing' && session !== null ? (
        <>
          {daily !== null ? (
            <p className="bf-countdown" data-testid="daily-countdown">
              {t('hub.countdown', { hh: countdown.hh, mm: countdown.mm, ss: countdown.ss })}
            </p>
          ) : null}
          {daily !== null && daily.stats.solved_count > 0 ? (
            <p className="bf-lane__meta">
              {t('daily.solvedBy', {
                count: daily.stats.solved_count,
                n: daily.incident_number,
              })}
            </p>
          ) : null}
          <div className="bf-lane__row">
            <BreaksCounter
              placed={session.breaksPlaced}
              total={session.board.breaks}
              strings={HUD_STRINGS}
            />
          </div>
          <Board
            session={session}
            strings={BOARD_STRINGS}
            label={t('a11y.board')}
            onChange={handleChange}
            onComplete={handleComplete}
          />
          {wrong ? (
            <p className="bf-hint" role="status">
              {t('play.wrong', { n: session.board.breaks })}
            </p>
          ) : null}
        </>
      ) : null}

      {stage === 'won' && session !== null && finale !== null ? (
        <>
          <BurnReplay
            board={session.board}
            shading={session.shading()}
            sequence={finale.sequence}
            strings={REPLAY_STRINGS}
            labels={REPLAY_LABELS}
            timeText={formatElapsed(finale.elapsedMs)}
          />
          <section className="bf-lane" data-testid="daily-stats">
            <p>{t('play.stats.time', { time: formatElapsed(finale.elapsedMs) })}</p>
            <DailyRankLine submission={submission} />
            {finale.clean ? <p data-testid="clean-contain">{t('play.stats.clean')}</p> : null}
            <RatingChip submission={submission} />
            {streakView !== null ? (
              <StreakFlame streak={streakView.streak} frozen={streakView.frozen} />
            ) : null}
            {submission.kind === 'offline' ? (
              <p className="bf-hint" role="status">
                {t('error.offline')}
              </p>
            ) : null}
            {!signedIn ? <p className="bf-hint">{t('streak.guestNote')}</p> : null}
            <div className="bf-lane__row">
              <button type="button" className="bf-chip" onClick={onShare} data-testid="daily-share">
                {t('share.action')}
              </button>
              {shareDone ? (
                <span role="status" data-testid="share-copied">
                  {t('share.copied')}
                </span>
              ) : null}
            </div>
            {tomorrow !== null ? (
              <p className="bf-lane__meta" data-testid="tomorrow-tease">
                {tomorrow.saturday
                  ? t('play.tomorrow.saturday', { n: tomorrow.incident })
                  : t('play.tomorrow', { n: tomorrow.incident, tier: tomorrow.tier })}
              </p>
            ) : null}
          </section>
        </>
      ) : null}
    </div>
  );
}

function DailyRankLine(props: { readonly submission: DailySubmissionState }): ReactElement | null {
  const { submission } = props;
  if (submission.kind !== 'accepted') return null;
  const daily = submission.result.daily;
  if (daily === undefined) return null;
  if (daily.percentile !== null && daily.percentile !== undefined) {
    return (
      <p data-testid="daily-percentile">{t('play.stats.percentile', { p: daily.percentile })}</p>
    );
  }
  if (daily.rank !== null && daily.rank !== undefined) {
    return <p data-testid="daily-rank">{t('daily.rankFallback', { rank: daily.rank })}</p>;
  }
  return null;
}

function RatingChip(props: { readonly submission: DailySubmissionState }): ReactElement | null {
  const { submission } = props;
  if (submission.kind !== 'accepted' || submission.rating === null) return null;
  const rating = submission.rating;
  return (
    <p role="status">
      <span className="bf-chip" data-testid="rating-chip">
        {rating.calibrating
          ? t('play.stats.calibrating', { n: rating.games })
          : t('play.stats.ratingDelta', {
              rating: Math.round(rating.rating),
              delta: formatDelta(ratingDelta(rating)),
            })}
      </span>
    </p>
  );
}
