/**
 * <BurnReplay> — plays a game-core RevealSequence minute by minute.
 *
 * Animated mode (tokens.motion): each minute lands replayMinuteMs (320ms)
 * after the previous, accelerating to replayMinuteFastMs (180ms) once past
 * minute replayAccelAfterMinute (8). Igniting cells flash white-hot for
 * replayFlashMs then settle onto their burnRamp color; clues that burn
 * on time stamp-pop; a CONTAINED stamp lands containedBeatMs after the
 * last minute when the shading is valid. Re-watchable.
 *
 * Reduced motion (prop, defaulting to the prefers-reduced-motion media
 * query) swaps the timer for a manual next/previous stepper carrying the
 * same information. Both modes announce minutes and the finale through an
 * aria-live region (COPY.md a11y keys).
 */
import { useEffect, useRef, useState } from 'react';
import type { CSSProperties, ReactElement } from 'react';
import type { BoardSpec } from '@burnfront/engine';
import type { RevealSequence } from '@burnfront/game-core';
import { MinuteCounter } from './hud';
import { cellName, formatString } from './strings';
import type { ReplayLabels, ReplayStringKey, StringsFor } from './strings';
import { burnColor, motion } from './tokens';

export interface BurnReplayProps {
  readonly board: BoardSpec;
  /** The player's shading (breaks stay dark through the burn). */
  readonly shading: readonly boolean[];
  /** revealSequence(board, shading) — pure data from game-core. */
  readonly sequence: RevealSequence;
  /** COPY.md strings (keyed-strings module lands in WS-09). */
  readonly strings: StringsFor<ReplayStringKey>;
  /** Control labels without COPY keys yet (see strings.ts). */
  readonly labels: ReplayLabels;
  /** Formatted solve time for `a11y.contained` ("{time}"). */
  readonly timeText: string;
  /** Overrides the prefers-reduced-motion default when set. */
  readonly reducedMotion?: boolean;
  readonly onFinished?: () => void;
}

function prefersReducedMotion(): boolean {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false;
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

export function BurnReplay({
  board,
  shading,
  sequence,
  strings,
  labels,
  timeText,
  reducedMotion,
  onFinished,
}: BurnReplayProps): ReactElement {
  const reduced = reducedMotion ?? prefersReducedMotion();
  const { frames, result } = sequence;
  const finalePosition = frames.length;
  // position p: frames[0..p] are revealed; p === frames.length is the finale.
  const [position, setPosition] = useState(0);
  const [announcement, setAnnouncement] = useState(() => announcementFor(0));
  const onFinishedRef = useRef(onFinished);
  onFinishedRef.current = onFinished;

  const maxMinute = frames.length > 0 ? (frames[frames.length - 1]?.minute ?? 0) : 0;
  const currentMinute =
    position < frames.length ? (frames[position]?.minute ?? maxMinute) : maxMinute;

  function announcementFor(p: number): string {
    if (p >= finalePosition) {
      return result.valid ? formatString(strings['a11y.contained'], { time: timeText }) : '';
    }
    const frame = frames[p];
    if (frame === undefined) return '';
    return formatString(strings['a11y.replay.minute'], {
      t: frame.minute,
      count: frame.cells.length,
    });
  }

  function goTo(p: number): void {
    const clamped = Math.max(0, Math.min(finalePosition, p));
    setPosition(clamped);
    setAnnouncement(announcementFor(clamped));
    if (clamped === finalePosition) onFinishedRef.current?.();
  }

  const goToRef = useRef(goTo);
  goToRef.current = goTo;

  // Animated driver. The timer chain re-arms after every reveal; the delay
  // belongs to the frame being revealed NEXT (acceleration boundary).
  useEffect(() => {
    if (reduced || position >= finalePosition) return;
    const next = position + 1;
    const nextFrame = frames[next];
    const delay =
      nextFrame === undefined
        ? motion.containedBeatMs
        : nextFrame.minute > motion.replayAccelAfterMinute
          ? motion.replayMinuteFastMs
          : motion.replayMinuteMs;
    const timer = window.setTimeout(() => {
      goToRef.current(next);
    }, delay);
    return () => {
      window.clearTimeout(timer);
    };
  }, [reduced, position, finalePosition, frames]);

  const clueByIndex = new Map<number, number>();
  for (const clue of board.clues) clueByIndex.set(clue.r * board.cols + clue.c, clue.m);
  const sparkIndex = board.spark.r * board.cols + board.spark.c;

  const cells = [];
  for (let index = 0; index < board.rows * board.cols; index++) {
    const isSpark = index === sparkIndex;
    const clueMinute = clueByIndex.get(index);
    const isBreak = shading[index] === true;
    const burnMinute = result.times[index] ?? -1;
    const burnt = !isBreak && burnMinute >= 0 && burnMinute <= currentMinute;
    const igniting = burnt && burnMinute === currentMinute && position < finalePosition;
    const onTimeClue = clueMinute !== undefined && clueMinute === burnMinute;
    const classes = ['bf-cell'];
    if (isSpark) classes.push('bf-cell--spark');
    if (clueMinute !== undefined) classes.push('bf-cell--clue');
    if (isBreak) classes.push('bf-cell--break');
    if (burnt) classes.push('bf-cell--burn');
    if (igniting) classes.push('bf-cell--igniting');
    if (burnt && onTimeClue) classes.push('bf-cell--stamp');
    const style: CSSProperties | undefined = burnt
      ? ({ '--bf-burn-bg': burnColor(burnMinute, maxMinute) } as CSSProperties)
      : undefined;
    cells.push(
      <div
        key={index}
        className={classes.join(' ')}
        data-cell={cellName(index, board.cols)}
        style={style}
      >
        <span className="bf-cell__glyph">
          {isSpark ? '★' : clueMinute !== undefined ? clueMinute : burnt ? burnMinute : ''}
        </span>
      </div>,
    );
  }

  const done = position >= finalePosition;

  return (
    <div className={`bf-replay${reduced ? ' bf-replay--reduced' : ''}`} data-testid="burn-replay">
      <div
        className="bf-board bf-board--replay"
        style={{ '--bf-cols': board.cols } as CSSProperties}
        aria-hidden="true"
      >
        {cells}
      </div>
      <div className="bf-replay__controls">
        <MinuteCounter minute={currentMinute} />
        {reduced ? (
          <>
            <button
              type="button"
              className="bf-button"
              disabled={position === 0}
              onClick={() => {
                goTo(position - 1);
              }}
            >
              {labels.previousMinute}
            </button>
            <button
              type="button"
              className="bf-button"
              disabled={done}
              onClick={() => {
                goTo(position + 1);
              }}
            >
              {labels.nextMinute}
            </button>
          </>
        ) : (
          done && (
            <button
              type="button"
              className="bf-button"
              onClick={() => {
                goTo(0);
              }}
            >
              {labels.watchAgain}
            </button>
          )
        )}
        {done && result.valid && (
          <div className="bf-replay__stamp" data-testid="contained-stamp">
            {strings['play.contained']}
          </div>
        )}
      </div>
      <div className="bf-visually-hidden" role="status" aria-live="polite">
        {announcement}
      </div>
    </div>
  );
}
