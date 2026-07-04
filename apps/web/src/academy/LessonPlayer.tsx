/**
 * <LessonPlayer> — one lesson end to end: the animated demo (BeatPlayer), then
 * the two pack practice boards (PracticeBoard), then the completion panel.
 *
 * Completion is recorded once both practice boards are contained: the
 * per-lesson flag lands in the academy store and the hub-facing summary
 * (academy.done + firstShiftDone) is mirrored into LocalState through the
 * runtime store, so the hub badge and Play button update live and survive
 * reload. Signed-in crews also sync each contain as a mode=pack solve
 * (best-effort; local completion stays authoritative).
 *
 * First Shift is the funnel: its completion panel routes DIRECTLY into today's
 * daily (the only forward action). The daily route is WS-10's and may still be
 * a stub — navigating there is graceful (handoff seam documented in STATUS).
 */
import { Link } from '@tanstack/react-router';
import { useCallback, useMemo, useState, type ReactElement } from 'react';
import { shadingToBits } from '@burnfront/engine';
import type { PlaySession } from '@burnfront/game-core';
import { uiWebCss } from '@burnfront/ui-web';
import { useApi, useLocalState, useLocalStateUpdate, useRuntime } from '../state/runtime';
import { t } from '../strings';
import { academyCss } from './academyCss';
import { BeatPlayer } from './BeatPlayer';
import { PRACTICE_BOARDS } from './boards';
import { useAcademyDeps } from './deps';
import { FIRST_SHIFT_SLUG, LESSON_COUNT, nextLesson, type Lesson } from './lessons';
import { createPackSync, type PackSolveInput } from './packSync';
import { markLessonComplete } from './progress';
import { useReducedMotion } from './reducedMotion';
import { PracticeBoard } from './PracticeBoard';

type Stage = 'demo' | 'practice' | 'complete';

function packInput(session: PlaySession, puzzleId: string, fallbackNow: number): PackSolveInput {
  return {
    puzzleId,
    shaded: shadingToBits(session.shading()),
    clientMs: session.elapsedMs(),
    startedAtMs: session.startedAt ?? fallbackNow,
    undoCount: session.undoCount,
    hints: session.hintCounts(),
  };
}

export function LessonPlayer(props: { readonly lesson: Lesson }): ReactElement {
  const { lesson } = props;
  const runtime = useRuntime();
  const api = useApi();
  const deps = useAcademyDeps();
  const state = useLocalState();
  const update = useLocalStateUpdate();
  const reducedMotion = useReducedMotion();

  const [stage, setStage] = useState<Stage>('demo');
  const [practiceIndex, setPracticeIndex] = useState(0);

  const signedIn = state.account !== null;
  const packSync = useMemo(
    () => createPackSync(api, deps.rng, runtime.clock),
    [api, deps.rng, runtime.clock],
  );

  const onContained = useCallback(
    (session: PlaySession) => {
      if (!signedIn) return;
      const puzzleId = lesson.practice[practiceIndex];
      // Fire-and-forget: the hub badge never waits on the network.
      void packSync.submit(packInput(session, puzzleId, runtime.clock.now()));
    },
    [signedIn, lesson.practice, practiceIndex, packSync, runtime.clock],
  );

  const completeLesson = useCallback(() => {
    const done = markLessonComplete(runtime.storage, lesson.slug);
    update((current) => ({
      ...current,
      academy: { ...current.academy, done, total: LESSON_COUNT },
      firstShiftDone: lesson.slug === FIRST_SHIFT_SLUG ? true : current.firstShiftDone,
    }));
    setStage('complete');
  }, [runtime.storage, lesson.slug, update]);

  const onNext = useCallback(() => {
    if (practiceIndex < lesson.practice.length - 1) {
      setPracticeIndex((index) => index + 1);
      return;
    }
    completeLesson();
  }, [practiceIndex, lesson.practice.length, completeLesson]);

  const isFirstShift = lesson.slug === FIRST_SHIFT_SLUG;
  const upcoming = nextLesson(lesson.slug);

  if (stage === 'demo') {
    return (
      <>
        <style>{academyCss}</style>
        <p className="bf-practice__intro">{t(lesson.blurbKey)}</p>
        <BeatPlayer script={lesson.demo} reducedMotion={reducedMotion} onStep={deps.onTutorialStep} />
        <button
          type="button"
          className="bf-play"
          data-testid="begin-practice"
          onClick={() => {
            setPracticeIndex(0);
            setStage('practice');
          }}
        >
          {t('academy.begin')}
        </button>
      </>
    );
  }

  if (stage === 'practice') {
    const puzzleId = lesson.practice[practiceIndex];
    const isLast = practiceIndex >= lesson.practice.length - 1;
    return (
      <>
        <style>{uiWebCss}</style>
        <style>{academyCss}</style>
        <PracticeBoard
          key={puzzleId}
          puzzleId={puzzleId}
          board={PRACTICE_BOARDS[puzzleId]}
          index={practiceIndex + 1}
          total={lesson.practice.length}
          reducedMotion={reducedMotion}
          onContained={onContained}
          onNext={onNext}
          nextLabel={isLast ? t('academy.practice.finish') : t('academy.practice.next')}
        />
      </>
    );
  }

  // stage === 'complete'
  return (
    <section className="bf-lesson__foot" data-testid="lesson-complete">
      <style>{academyCss}</style>
      <h2 className="bf-lane__title">{t('academy.lesson.completeHeading')}</h2>
      <p className="bf-lane__meta">{t('academy.lesson.completeNote')}</p>
      {isFirstShift ? (
        <div className="bf-lesson__actions">
          <Link className="bf-play" to="/daily/{-$date}" data-testid="to-daily">
            {t('academy.firstShift.toDaily')}
          </Link>
          <p className="bf-lane__meta">{t('academy.firstShift.toDailyNote')}</p>
        </div>
      ) : (
        <div className="bf-lesson__actions">
          {upcoming === null ? null : (
            <Link
              className="bf-play"
              to="/academy/$slug"
              params={{ slug: upcoming.slug }}
              data-testid="next-lesson"
            >
              {t('academy.lesson.next', { title: t(upcoming.titleKey) })}
            </Link>
          )}
          <Link className="bf-chip" to="/academy">
            {t('academy.lesson.back')}
          </Link>
        </div>
      )}
    </section>
  );
}
</content>
