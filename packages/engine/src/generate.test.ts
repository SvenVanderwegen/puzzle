/**
 * generate(): determinism (same rng => identical output) and the three
 * self-certificates on every emitted board — unique solution, deducible,
 * every break witnessed by the remaining clues.
 */
import { describe, expect, it } from 'vitest';
import { burnTimes, countSolutions, deduce, generate, validate } from './index';
import type { GeneratedPuzzle } from './index';
import { mulberry32 } from './testing/mulberry32';

function assertCertificates(puzzle: GeneratedPuzzle): void {
  const { board, solution, times, deductionSteps } = puzzle;

  // Certificate 0: the published solution is a valid containment map.
  const res = validate(board, solution);
  expect(res.valid).toBe(true);
  expect([...res.times]).toEqual([...times]);
  expect(solution.filter(Boolean).length).toBe(board.breaks);

  // Certificate 1: exactly one solution.
  expect(countSolutions(board)).toEqual({ count: 1, aborted: false });

  // Certificate 2: guess-free — deduction alone reaches the solution.
  const ded = deduce(board);
  if (ded === null) throw new Error('generated board is not deducible');
  expect([...ded.shading]).toEqual([...solution]);
  expect(ded.steps.length).toBe(deductionSteps);

  // Certificate 3: every break is witnessed — opening it (others staying)
  // makes some clue burn at the wrong minute.
  for (let i = 0; i < solution.length; i++) {
    if (solution[i] !== true) continue;
    const opened = solution.map((b, k) => (k === i ? false : b));
    const openedTimes = burnTimes(board.rows, board.cols, board.spark, opened);
    const witnessed = board.clues.some(
      (clue) => openedTimes[clue.r * board.cols + clue.c] !== clue.m,
    );
    expect(witnessed, `break at index ${String(i)} is unwitnessed`).toBe(true);
  }
}

describe('generate determinism', () => {
  it('same rng seed => identical output (5x5)', () => {
    const a = generate({ rows: 5, cols: 5, breaks: 4 }, mulberry32(42));
    const b = generate({ rows: 5, cols: 5, breaks: 4 }, mulberry32(42));
    expect(a).toEqual(b);
  });

  it('same rng seed => identical output (3x3)', () => {
    const a = generate({ rows: 3, cols: 3, breaks: 2 }, mulberry32(7));
    const b = generate({ rows: 3, cols: 3, breaks: 2 }, mulberry32(7));
    expect(a).toEqual(b);
  });

  it('different seeds give different boards (for these fixed seeds)', () => {
    const a = generate({ rows: 5, cols: 5, breaks: 4 }, mulberry32(1));
    const b = generate({ rows: 5, cols: 5, breaks: 4 }, mulberry32(2));
    expect(a).not.toEqual(b);
  });
});

describe('generate certificates', () => {
  it('3x3 boards self-certify across seeds', () => {
    for (const seed of [1, 2, 3]) {
      assertCertificates(generate({ rows: 3, cols: 3, breaks: 2 }, mulberry32(seed)));
    }
  });

  it('5x5 boards self-certify across seeds', () => {
    for (const seed of [10, 11, 12]) {
      assertCertificates(generate({ rows: 5, cols: 5, breaks: 4 }, mulberry32(seed)));
    }
  });

  it('6x6 board self-certifies', () => {
    assertCertificates(generate({ rows: 6, cols: 6, breaks: 8 }, mulberry32(20)));
  });

  it('honors the minClues floor', () => {
    const puzzle = generate({ rows: 5, cols: 5, breaks: 4, minClues: 5 }, mulberry32(30));
    expect(puzzle.board.clues.length).toBeGreaterThanOrEqual(5);
    assertCertificates(puzzle);
  });
});

describe('generate bounds and failure modes', () => {
  it('rejects malformed params', () => {
    const rng = mulberry32(1);
    expect(() => generate({ rows: 0, cols: 5, breaks: 1 }, rng)).toThrow(/rows\/cols/);
    expect(() => generate({ rows: 5, cols: 5.5, breaks: 1 }, rng)).toThrow(/integers/);
    expect(() => generate({ rows: 65, cols: 5, breaks: 1 }, rng)).toThrow(/rows\/cols/);
    expect(() => generate({ rows: 5, cols: 5, breaks: 0 }, rng)).toThrow(/breaks/);
    expect(() => generate({ rows: 5, cols: 5, breaks: 24 }, rng)).toThrow(/breaks/);
    expect(() => generate({ rows: 5, cols: 5, breaks: 4, minClues: -1 }, rng)).toThrow(/minClues/);
    expect(() => generate({ rows: 5, cols: 5, breaks: 4, maxAttempts: 0 }, rng)).toThrow(
      /maxAttempts/,
    );
    expect(() => generate({ rows: 5, cols: 5, breaks: 4, nodeBudget: 0 }, rng)).toThrow(
      /nodeBudget/,
    );
  });

  it('throws once maxAttempts is exhausted (2x2 has no witnessed terrain)', () => {
    expect(() => generate({ rows: 2, cols: 2, breaks: 1, maxAttempts: 5 }, mulberry32(3))).toThrow(
      /attempts/,
    );
  });

  it('throws once maxAttempts is exhausted (3x3 with 7 breaks never detours)', () => {
    expect(() => generate({ rows: 3, cols: 3, breaks: 7, maxAttempts: 30 }, mulberry32(4))).toThrow(
      /attempts/,
    );
  });
});
