/**
 * <BurnReplay> sequencing — fake-timer DOM assertions (ADR-0010: no pixel
 * diffs). Minute grouping, the 320ms -> 180ms acceleration boundary after
 * minute 8, white-hot ignite class, on-time clue stamps, CONTAINED finale,
 * re-watchability, and the reduced-motion stepper.
 */
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { BoardSpec } from '@burnfront/engine';
import type { RevealSequence } from '@burnfront/game-core';
import { BurnReplay } from './BurnReplay';
import { replayLabels, replayStrings } from './fixture/fixtureStrings';
import { burnColor, motion } from './tokens';

/** 1x12 synthetic strip: fire walks left to right, one cell per minute. */
const stripBoard: BoardSpec = {
  rows: 1,
  cols: 12,
  spark: { r: 0, c: 0 },
  breaks: 0,
  clues: [{ r: 0, c: 5, m: 5 }],
};
const stripTimes = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11];
const stripSequence: RevealSequence = {
  result: { valid: true, reason: 'ok', times: stripTimes },
  frames: stripTimes.map((minute, i) => ({ minute, cells: [i] })),
};
const noShading: readonly boolean[] = new Array<boolean>(12).fill(false);

interface RenderOptions {
  readonly sequence?: RevealSequence;
  readonly reducedMotion?: boolean;
  readonly onFinished?: () => void;
}

function renderReplay(options: RenderOptions = {}): HTMLElement[] {
  render(
    <BurnReplay
      board={stripBoard}
      shading={noShading}
      sequence={options.sequence ?? stripSequence}
      strings={replayStrings}
      labels={replayLabels}
      timeText="1:23"
      {...(options.reducedMotion !== undefined ? { reducedMotion: options.reducedMotion } : {})}
      {...(options.onFinished !== undefined ? { onFinished: options.onFinished } : {})}
    />,
  );
  return Array.from(screen.getByTestId('burn-replay').querySelectorAll<HTMLElement>('.bf-cell'));
}

function advance(ms: number): void {
  act(() => {
    vi.advanceTimersByTime(ms);
  });
}

/**
 * Step whole minutes. The replay arms each timeout only after the previous
 * reveal rendered, so a bulk advanceTimersByTime would fire just the first
 * one — advance per step, like a real clock ticking past each reveal.
 */
function advanceSteps(steps: readonly number[]): void {
  for (const ms of steps) advance(ms);
}

const toMinute8: readonly number[] = new Array<number>(8).fill(motion.replayMinuteMs);
const fastTail: readonly number[] = new Array<number>(3).fill(motion.replayMinuteFastMs);

afterEach(() => {
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe('animated sequencing', () => {
  it('reveals minute 0 immediately and announces it', () => {
    vi.useFakeTimers();
    const cells = renderReplay();
    expect(cells[0]).toHaveClass('bf-cell--burn');
    expect(cells[0]).toHaveClass('bf-cell--igniting');
    expect(cells[1]).not.toHaveClass('bf-cell--burn');
    expect(screen.getByRole('status')).toHaveTextContent('Minute 0: 1 cells burning.');
  });

  it('advances one minute per replayMinuteMs up to the acceleration boundary', () => {
    vi.useFakeTimers();
    const cells = renderReplay();
    advance(motion.replayMinuteMs - 1);
    expect(cells[1]).not.toHaveClass('bf-cell--burn');
    advance(1);
    expect(cells[1]).toHaveClass('bf-cell--burn');
    expect(screen.getByRole('status')).toHaveTextContent('Minute 1: 1 cells burning.');

    // minutes 2..8 still take 320ms each (boundary is AFTER minute 8)
    for (let minute = 2; minute <= motion.replayAccelAfterMinute; minute++) {
      advance(motion.replayMinuteMs);
      expect(cells[minute]).toHaveClass('bf-cell--burn');
    }
    expect(cells[9]).not.toHaveClass('bf-cell--burn');
  });

  it('accelerates to replayMinuteFastMs past minute 8', () => {
    vi.useFakeTimers();
    const cells = renderReplay();
    advanceSteps(toMinute8); // minutes 1..8 revealed
    expect(cells[8]).toHaveClass('bf-cell--burn');

    advance(motion.replayMinuteFastMs - 1);
    expect(cells[9]).not.toHaveClass('bf-cell--burn');
    advance(1);
    expect(cells[9]).toHaveClass('bf-cell--burn');
    advance(motion.replayMinuteFastMs);
    expect(cells[10]).toHaveClass('bf-cell--burn');
    advance(motion.replayMinuteFastMs);
    expect(cells[11]).toHaveClass('bf-cell--burn');
  });

  it('only the newest minute carries the white-hot ignite class', () => {
    vi.useFakeTimers();
    const cells = renderReplay();
    advanceSteps([motion.replayMinuteMs, motion.replayMinuteMs]);
    expect(cells[2]).toHaveClass('bf-cell--igniting');
    expect(cells[1]).not.toHaveClass('bf-cell--igniting');
    expect(cells[0]).not.toHaveClass('bf-cell--igniting');
  });

  it('burnt cells get their burnRamp color as --bf-burn-bg', () => {
    vi.useFakeTimers();
    const cells = renderReplay();
    advanceSteps(new Array<number>(3).fill(motion.replayMinuteMs));
    expect(cells[0]?.style.getPropertyValue('--bf-burn-bg')).toBe(burnColor(0, 11));
    expect(cells[3]?.style.getPropertyValue('--bf-burn-bg')).toBe(burnColor(3, 11));
    expect(cells[4]?.style.getPropertyValue('--bf-burn-bg')).toBe('');
  });

  it('stamps an on-time clue the minute the fire hits it', () => {
    vi.useFakeTimers();
    const cells = renderReplay();
    advanceSteps(new Array<number>(4).fill(motion.replayMinuteMs));
    expect(cells[5]).not.toHaveClass('bf-cell--stamp');
    advance(motion.replayMinuteMs);
    expect(cells[5]).toHaveClass('bf-cell--stamp');
    expect(cells[5]).toHaveClass('bf-cell--clue');
  });

  it('never stamps a late clue', () => {
    vi.useFakeTimers();
    // same strip, but the "5" clue actually burns at minute 6
    const times = [0, 1, 2, 3, 4, 6, 6, 7, 8, 9, 10, 11];
    const sequence: RevealSequence = {
      result: { valid: false, reason: 'clue_time_mismatch', times },
      frames: [
        { minute: 0, cells: [0] },
        { minute: 1, cells: [1] },
        { minute: 2, cells: [2] },
        { minute: 3, cells: [3] },
        { minute: 4, cells: [4] },
        { minute: 6, cells: [5, 6] },
        { minute: 7, cells: [7] },
        { minute: 8, cells: [8] },
      ],
    };
    const cells = renderReplay({ sequence });
    advanceSteps(new Array<number>(7).fill(motion.replayMinuteMs)); // all 8 frames
    expect(cells[5]).toHaveClass('bf-cell--burn');
    expect(cells[5]).not.toHaveClass('bf-cell--stamp');
    // invalid shading: no CONTAINED finale
    advance(motion.containedBeatMs * 2);
    expect(screen.queryByTestId('contained-stamp')).not.toBeInTheDocument();
  });

  it('lands CONTAINED containedBeatMs after the last minute and announces it', () => {
    vi.useFakeTimers();
    const onFinished = vi.fn();
    renderReplay({ onFinished });
    advanceSteps([...toMinute8, ...fastTail]);
    expect(screen.queryByTestId('contained-stamp')).not.toBeInTheDocument();
    advance(motion.containedBeatMs);
    expect(screen.getByTestId('contained-stamp')).toHaveTextContent('CONTAINED');
    expect(screen.getByRole('status')).toHaveTextContent('Contained. 1:23.');
    expect(onFinished).toHaveBeenCalledTimes(1);
  });

  it('is re-watchable: the finale offers Watch again and restarts from minute 0', () => {
    vi.useFakeTimers();
    const cells = renderReplay();
    advanceSteps([...toMinute8, ...fastTail, motion.containedBeatMs]);
    const again = screen.getByRole('button', { name: 'Watch the burn again' });
    fireEvent.click(again);
    expect(screen.queryByTestId('contained-stamp')).not.toBeInTheDocument();
    expect(cells[1]).not.toHaveClass('bf-cell--burn');
    expect(screen.getByRole('status')).toHaveTextContent('Minute 0: 1 cells burning.');
    advance(motion.replayMinuteMs);
    expect(cells[1]).toHaveClass('bf-cell--burn');
  });
});

describe('reduced motion', () => {
  it('renders a stepper: no timers, manual next/previous, same information', () => {
    vi.useFakeTimers();
    const cells = renderReplay({ reducedMotion: true });
    advance(motion.replayMinuteMs * 20);
    expect(cells[1]).not.toHaveClass('bf-cell--burn'); // nothing auto-advances

    const next = screen.getByRole('button', { name: 'Next minute' });
    const prev = screen.getByRole('button', { name: 'Previous minute' });
    expect(prev).toBeDisabled();

    fireEvent.click(next);
    expect(cells[1]).toHaveClass('bf-cell--burn');
    expect(screen.getByRole('status')).toHaveTextContent('Minute 1: 1 cells burning.');

    fireEvent.click(prev);
    expect(cells[1]).not.toHaveClass('bf-cell--burn');
    expect(screen.getByRole('status')).toHaveTextContent('Minute 0: 1 cells burning.');

    for (let i = 0; i < 12; i++) fireEvent.click(next);
    expect(next).toBeDisabled();
    expect(screen.getByTestId('contained-stamp')).toHaveTextContent('CONTAINED');
    expect(screen.getByRole('status')).toHaveTextContent('Contained. 1:23.');
  });

  it('suppresses the flash/stamp animations via the reduced modifier', () => {
    renderReplay({ reducedMotion: true });
    expect(screen.getByTestId('burn-replay')).toHaveClass('bf-replay--reduced');
  });

  it('defaults to the prefers-reduced-motion media query when no prop is set', () => {
    const spy = vi.spyOn(window, 'matchMedia').mockReturnValue({
      matches: true,
      media: '(prefers-reduced-motion: reduce)',
    } as MediaQueryList);
    renderReplay();
    expect(spy).toHaveBeenCalledWith('(prefers-reduced-motion: reduce)');
    expect(screen.getByRole('button', { name: 'Next minute' })).toBeInTheDocument();
  });
});
