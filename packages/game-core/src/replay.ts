/**
 * Replay driver — pure data for the UI's minute-by-minute reveal animation.
 * Groups cells by burn minute from the engine's validate().times (frame 0 is
 * the spark). Shaded/unreached cells never appear in a frame; invalid
 * shadings still produce frames (the verdict drives what the fire DID reach).
 */
import { validate } from '@burnfront/engine';
import type { BoardSpec, BurnResult, Shading } from '@burnfront/engine';

export interface RevealFrame {
  readonly minute: number;
  /** Row-major cell indices igniting this minute, ascending. */
  readonly cells: readonly number[];
}

export interface RevealSequence {
  readonly result: BurnResult;
  /** Frames ordered by ascending minute. Empty when the fire never starts. */
  readonly frames: readonly RevealFrame[];
}

export function revealSequence(board: BoardSpec, shading: Shading): RevealSequence {
  const result = validate(board, shading);
  const byMinute = new Map<number, number[]>();
  let maxMinute = -1;
  for (let i = 0; i < result.times.length; i++) {
    const minute = result.times[i];
    if (minute === undefined || minute < 0) continue;
    const cells = byMinute.get(minute);
    if (cells === undefined) byMinute.set(minute, [i]);
    else cells.push(i);
    if (minute > maxMinute) maxMinute = minute;
  }
  const frames: RevealFrame[] = [];
  for (let minute = 0; minute <= maxMinute; minute++) {
    const cells = byMinute.get(minute);
    if (cells !== undefined) frames.push({ minute, cells });
  }
  return { result, frames };
}
