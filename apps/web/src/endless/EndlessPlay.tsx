/**
 * Endless mode (/play, WS-11): worker-generated boards at the tier dial,
 * game-core PlaySession + ui-web Board while solving, BurnReplay + stats
 * card on containment, rated submission for signed-in users (ADR-0006),
 * clean guest skip. Generation NEVER runs on this thread — boards arrive
 * from the worker via GeneratorClient, the next one pre-generated during
 * play so "next" is instant.
 */
import { useNavigate } from '@tanstack/react-router';
import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from 'react';
import type { ReactElement } from 'react';
import type { BurnResult } from '@burnfront/engine';
import {
  clearSnapshot,
  loadSnapshot,
  PlaySession,
  revealSequence,
  saveSnapshot,
  type RevealSequence,
} from '@burnfront/game-core';
import { Board, BreaksCounter, BurnReplay, uiWebCss } from '@burnfront/ui-web';
import { recommendedTier, tierLabel, TIERS } from '../hub/tiers';
import { formatElapsed } from '../state/clock';
import type { Tier } from '../state/localState';
import { useLocalState, useRuntime } from '../state/runtime';
import { t } from '../strings';
import { useEndlessDeps } from './deps';
import { GenerationCancelled, GeneratorClient, type EndlessBoard } from './generatorClient';
import { useLoadingCopy } from './loadingCopy';
import { tierOfBoard } from './params';
import {
  creditEndlessSolve,
  ENDLESS_SESSION_KEY,
  markEndlessInProgress,
  recordTierWin,
  saveDial,
} from './prefs';
import { toKeyValueStorage } from './storage';
import { formatDelta, ratingDelta, submitEndlessSolve, type SubmissionState } from './submit';

// ui-web components consume raw COPY.md templates ({cell} etc. filled by the
// component); t() without params returns the template verbatim.
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

type Stage = 'generating' | 'playing' | 'won' | 'failed';

interface Finale {
  readonly result: BurnResult;
  readonly sequence: RevealSequence;
  readonly elapsedMs: number;
  readonly solved: number;
  readonly clean: boolean;
}

export interface EndlessPlayProps {
  readonly tier: Tier;
}

export function EndlessPlay({ tier }: EndlessPlayProps): ReactElement {
  const navigate = useNavigate();
  const runtime = useRuntime();
  const state = useLocalState();
  const deps = useEndlessDeps();
  const kv = useMemo(() => toKeyValueStorage(runtime.storage), [runtime.storage]);
  const client = useMemo(
    () => new GeneratorClient(deps.createWorker, deps.seedSource),
    [deps.createWorker, deps.seedSource],
  );

  const [stage, setStage] = useState<Stage>('generating');
  const [session, setSession] = useState<PlaySession | null>(null);
  const [wrong, setWrong] = useState(false);
  const [finale, setFinale] = useState<Finale | null>(null);
  const [submission, setSubmission] = useState<SubmissionState>({ kind: 'none' });
  const [, bump] = useReducer((n: number) => n + 1, 0);
  const mounted = useRef(true);
  const loadingKey = useLoadingCopy(stage === 'generating');
  const recommended = recommendedTier(state.record.rating);
  const signedIn = state.account !== null;

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
      client.dispose();
    };
  }, [client]);

  const beginBoard = useCallback(
    (next: EndlessBoard) => {
      const fresh = new PlaySession(
        { board: next.board, mode: 'endless', deductionSteps: next.deductionSteps },
        runtime.clock,
      );
      fresh.start();
      saveSnapshot(kv, ENDLESS_SESSION_KEY, fresh.snapshot());
      markEndlessInProgress(runtime.storage, next.tier, true);
      setSubmission({ kind: 'none' });
      setFinale(null);
      setWrong(false);
      setSession(fresh);
      setStage('playing');
      client.prefetch(next.tier);
    },
    [client, kv, runtime],
  );

  const requestBoard = useCallback(
    (requestedTier: Tier) => {
      setSession(null);
      setFinale(null);
      setWrong(false);
      setStage('generating');
      client
        .request(requestedTier)
        .then((board) => {
          if (mounted.current) beginBoard(board);
        })
        .catch((error: unknown) => {
          if (mounted.current && !(error instanceof GenerationCancelled)) setStage('failed');
        });
    },
    [beginBoard, client],
  );

  // Mount / tier change: resume the persisted mid-solve board when it belongs
  // to this tier, otherwise generate a fresh one.
  useEffect(() => {
    const snapshot = loadSnapshot(kv, ENDLESS_SESSION_KEY);
    if (snapshot !== null && snapshot.mode === 'endless' && tierOfBoard(snapshot.board) === tier) {
      const restored = PlaySession.restore(snapshot, runtime.clock);
      restored.start();
      markEndlessInProgress(runtime.storage, tier, true);
      setSubmission({ kind: 'none' });
      setFinale(null);
      setWrong(false);
      setSession(restored);
      setStage('playing');
      client.prefetch(tier);
      return;
    }
    requestBoard(tier);
  }, [tier, client, kv, requestBoard, runtime]);

  const selectTier = useCallback(
    (next: Tier) => {
      if (next === tier) return;
      saveDial(runtime.storage, next);
      // Switching mid-solve abandons the board (unrated — RATING.md §3).
      clearSnapshot(kv, ENDLESS_SESSION_KEY);
      markEndlessInProgress(runtime.storage, next, false);
      void navigate({ to: '/play', search: { tier: next } });
    },
    [tier, kv, navigate, runtime],
  );

  const regenerate = useCallback(() => {
    clearSnapshot(kv, ENDLESS_SESSION_KEY);
    markEndlessInProgress(runtime.storage, tier, false);
    requestBoard(tier);
  }, [kv, requestBoard, runtime, tier]);

  const handleChange = useCallback(() => {
    bump();
    if (session === null || stage !== 'playing') return;
    saveSnapshot(kv, ENDLESS_SESSION_KEY, session.snapshot());
    if (session.completion() === null) setWrong(false);
  }, [kv, session, stage]);

  const handleComplete = useCallback(
    (result: BurnResult) => {
      if (session === null || stage !== 'playing') return;
      if (!result.valid) {
        setWrong(true);
        return;
      }
      session.pause();
      const elapsedMs = session.elapsedMs();
      clearSnapshot(kv, ENDLESS_SESSION_KEY);
      const solved = creditEndlessSolve(runtime.storage, tier);
      recordTierWin(runtime.storage, tier, elapsedMs);
      const hints = session.hintCounts();
      setFinale({
        result,
        sequence: revealSequence(session.board, session.shading()),
        elapsedMs,
        solved,
        clean: hints.s1 === 0 && hints.s2 === 0 && hints.s3 === 0,
      });
      setWrong(false);
      setStage('won');
      client.prefetch(tier);
      if (signedIn) {
        void submitEndlessSolve(session, deps.api, deps.recordEnv, runtime.clock, (next) => {
          if (mounted.current) setSubmission(next);
        });
      }
    },
    [client, deps, kv, runtime, session, signedIn, stage, tier],
  );

  return (
    <div className="bf-endless">
      <style>{uiWebCss}</style>
      <div className="bf-lane__row" data-testid="tier-dials">
        {TIERS.map((dial) => (
          <button
            key={dial}
            type="button"
            className="bf-chip bf-tier-chip"
            data-tier={dial}
            data-recommended={dial === recommended}
            aria-pressed={dial === tier}
            onClick={() => {
              selectTier(dial);
            }}
          >
            {tierLabel(dial)}
          </button>
        ))}
      </div>
      {stage === 'generating' ? (
        <p className="bf-lane__meta" role="status" data-testid="endless-loading">
          {t(loadingKey)}
        </p>
      ) : null}
      {stage === 'failed' ? (
        <div>
          <p className="bf-lane__meta" role="alert">
            {t('error.generic')}
          </p>
          <button type="button" className="bf-chip" onClick={regenerate}>
            {t('hub.play.endless', { tier: tierLabel(tier) })}
          </button>
        </div>
      ) : null}
      {stage === 'playing' && session !== null ? (
        <>
          <div className="bf-lane__row">
            <BreaksCounter
              placed={session.breaksPlaced}
              total={session.board.breaks}
              strings={HUD_STRINGS}
            />
            <button type="button" className="bf-chip" onClick={regenerate}>
              {t('hub.play.endless', { tier: tierLabel(tier) })}
            </button>
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
          <section className="bf-lane" data-testid="endless-stats">
            <p>{t('play.stats.time', { time: formatElapsed(finale.elapsedMs) })}</p>
            {finale.clean ? <p>{t('play.stats.clean')}</p> : null}
            <p className="bf-lane__meta">{t('hub.endless.solved', { n: finale.solved })}</p>
            <RatingLine submission={submission} />
            {signedIn ? null : <p className="bf-hint">{t('streak.guestNote')}</p>}
            <button type="button" className="bf-play" onClick={regenerate}>
              {t('hub.play.endless', { tier: tierLabel(tier) })}
            </button>
          </section>
        </>
      ) : null}
    </div>
  );
}

function RatingLine(props: { readonly submission: SubmissionState }): ReactElement | null {
  const { submission } = props;
  if (submission.kind === 'none') return null;
  if (submission.kind === 'error') {
    return (
      <p className="bf-hint" role="status">
        {t(submission.messageKey)}
      </p>
    );
  }
  if (submission.kind === 'rated') {
    const { rating } = submission;
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
  // Submitting/pending: the Glicko-2 job is queued (rating_pending). No
  // COPY.md key exists for this state yet (flagged in tasks/WS-11/STATUS.md);
  // a glyph-only ellipsis chip stands in until the lead adds one.
  return (
    <p className="bf-lane__meta" role="status" data-testid="rating-pending">
      <span className="bf-chip">…</span>
    </p>
  );
}
