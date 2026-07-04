/**
 * <BeatPlayer> — the reusable beat engine behind every lesson demo, ported
 * from the prototype walkthrough (reference/index.html).
 *
 * Animated mode: autoplay advances one beat every `beat.durMs`, with a
 * Play/Pause toggle and a Watch-again reset at the end. Reduced-motion mode
 * (prop, defaulting to the prefers-reduced-motion media query — the app pref is
 * folded in by the caller): NO autoplay, a Previous/Next stepper carrying the
 * exact same beats. Every beat is keyboard reachable (native buttons); the
 * caption is an aria-live region so assistive tech hears each step, and the
 * grid itself is decorative.
 *
 * `onStep(n)` fires the tutorial_step{n} analytics beat (1-based) on each
 * forward advance — a documented no-op until WS-19's beacon lands (events.ts).
 */
import { useCallback, useEffect, useRef, useState, type ReactElement } from 'react';
import { t } from '../strings';
import { DemoGrid } from './DemoGrid';
import type { DemoScript } from './beats';
import { noopTutorialStep, type TutorialStepSink } from './events';
import { prefersReducedMotion } from './reducedMotion';

export interface BeatPlayerProps {
  readonly script: DemoScript;
  /** Overrides the prefers-reduced-motion default when set. */
  readonly reducedMotion?: boolean;
  /** tutorial_step{n} sink; default drops events (WS-19 deferred). */
  readonly onStep?: TutorialStepSink;
  /** Fires once the final beat is reached (enables "Begin practice"). */
  readonly onFinished?: () => void;
}

export function BeatPlayer({
  script,
  reducedMotion,
  onStep = noopTutorialStep,
  onFinished,
}: BeatPlayerProps): ReactElement {
  const reduced = reducedMotion ?? prefersReducedMotion();
  const beats = script.beats;
  const last = beats.length - 1;

  const [position, setPosition] = useState(0);
  // Autoplay runs from the first beat in animated mode only.
  const [playing, setPlaying] = useState(!reduced);

  const onStepRef = useRef(onStep);
  onStepRef.current = onStep;
  const onFinishedRef = useRef(onFinished);
  onFinishedRef.current = onFinished;
  const finishedFired = useRef(false);

  // tutorial_step{1} for the opening beat, once per mount.
  useEffect(() => {
    onStepRef.current(1);
  }, []);

  const fireFinishedOnce = useCallback(() => {
    if (finishedFired.current) return;
    finishedFired.current = true;
    onFinishedRef.current?.();
  }, []);

  const goForward = useCallback(() => {
    setPosition((current) => {
      if (current >= last) return current;
      const next = current + 1;
      onStepRef.current(next + 1);
      if (next >= last) fireFinishedOnce();
      return next;
    });
  }, [last, fireFinishedOnce]);

  const goBack = useCallback(() => {
    setPosition((current) => (current > 0 ? current - 1 : current));
  }, []);

  const replay = useCallback(() => {
    finishedFired.current = false;
    setPosition(0);
    onStepRef.current(1);
    if (!reduced) setPlaying(true);
  }, [reduced]);

  // A single-beat script is finished on arrival.
  useEffect(() => {
    if (last === 0) fireFinishedOnce();
  }, [last, fireFinishedOnce]);

  // Animated driver: re-arm after each reveal; the dwell belongs to the beat
  // currently shown (same shape as ui-web BurnReplay).
  useEffect(() => {
    if (reduced || !playing || position >= last) return;
    const beat = beats[position];
    const delay = beat?.durMs ?? 0;
    const timer = window.setTimeout(goForward, delay);
    return () => {
      window.clearTimeout(timer);
    };
  }, [reduced, playing, position, last, beats, goForward]);

  const beat = beats[position] ?? beats[0];
  if (beat === undefined) return <div className="bf-demo" />;

  const atEnd = position >= last;
  const minuteText =
    beat.waveMinute < 0
      ? t('academy.demo.minute.pre')
      : t('academy.demo.minute', { t: beat.waveMinute });

  return (
    <figure className="bf-demo" aria-label={t('academy.demo.region')}>
      <DemoGrid script={script} beat={beat} />
      <div className="bf-demo__hud">
        <span aria-hidden="true">{minuteText}</span>
        <span aria-hidden="true">
          {t('academy.demo.progress', { n: position + 1, total: beats.length })}
        </span>
      </div>
      <figcaption className="bf-demo__cap" role="status" aria-live="polite">
        {t(beat.captionKey, beat.captionParams)}
      </figcaption>
      <div className="bf-demo__controls">
        {reduced ? (
          <>
            <button
              type="button"
              className="bf-button"
              onClick={goBack}
              disabled={position === 0}
              data-testid="demo-prev"
            >
              {t('academy.demo.back')}
            </button>
            <button
              type="button"
              className="bf-button"
              onClick={atEnd ? replay : goForward}
              data-testid="demo-next"
            >
              {atEnd ? t('academy.demo.replay') : t('academy.demo.step')}
            </button>
          </>
        ) : (
          <button
            type="button"
            className="bf-button"
            data-testid="demo-toggle"
            onClick={() => {
              if (atEnd) {
                replay();
                return;
              }
              setPlaying((value) => !value);
            }}
          >
            {atEnd
              ? t('academy.demo.replay')
              : playing
                ? t('academy.demo.pause')
                : t('academy.demo.play')}
          </button>
        )}
      </div>
    </figure>
  );
}
