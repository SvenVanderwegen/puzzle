/**
 * The fixture puzzle: the README demo board (reference/firebreak.py
 * demo_puzzle) — small, solvable, with a known unique solution. Used by the
 * fixture page and the DOM tests (keyboard-only solve drives these breaks).
 */
import type { BoardSpec } from '@burnfront/engine';

export const fixtureBoard: BoardSpec = {
  rows: 5,
  cols: 5,
  spark: { r: 3, c: 0 },
  breaks: 4,
  clues: [
    { r: 1, c: 4, m: 8 },
    { r: 2, c: 2, m: 5 },
    { r: 3, c: 1, m: 1 },
    { r: 4, c: 1, m: 2 },
    { r: 4, c: 3, m: 8 },
  ],
};

/** Row-major indices of the unique solution's firebreaks. */
export const fixtureBreakIndices: readonly number[] = [8, 11, 17, 22];
