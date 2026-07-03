/** Unit coverage for the surfaces the vectors do not exercise. */
import { describe, expect, it } from 'vitest';
import {
  bitsToShading,
  burnTimes,
  countSolutions,
  deduce,
  grade,
  shadingToBits,
  validate,
} from './index';
import type { BoardSpec } from './index';

/** The README demo board (reference/firebreak.py demo_puzzle). */
const demoBoard: BoardSpec = {
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

/** Its unique solution: breaks at (1,3), (2,1), (3,2), (4,2). */
const demoSolutionBits = '0000000010010000010000100';

describe('bit-string codec', () => {
  it('round-trips', () => {
    expect(shadingToBits(bitsToShading(demoSolutionBits))).toBe(demoSolutionBits);
    expect(bitsToShading('')).toEqual([]);
    expect(shadingToBits([true, false])).toBe('10');
  });

  it('rejects non-bit characters', () => {
    expect(() => bitsToShading('01x')).toThrow(/invalid character/);
  });
});

describe('burnTimes', () => {
  it('computes minutes and -1 for shaded/unreached', () => {
    expect(burnTimes(2, 2, { r: 0, c: 0 }, [false, true, false, false])).toEqual([0, -1, 1, 2]);
  });

  it('returns all -1 when the spark is shaded (the fire never starts)', () => {
    expect(burnTimes(2, 2, { r: 0, c: 0 }, [true, false, false, false])).toEqual([-1, -1, -1, -1]);
  });

  it('rejects malformed input', () => {
    expect(() => burnTimes(0, 2, { r: 0, c: 0 }, [])).toThrow(/rows\/cols/);
    expect(() => burnTimes(2, 2, { r: 2, c: 0 }, [false, false, false, false])).toThrow(/spark/);
    expect(() => burnTimes(2, 2, { r: 0, c: 0 }, [false])).toThrow(/length/);
  });
});

describe('validate input checking', () => {
  it('rejects a shading of the wrong length', () => {
    expect(() => validate(demoBoard, [false])).toThrow(/length/);
  });

  it('rejects malformed boards', () => {
    const shading = bitsToShading(demoSolutionBits);
    expect(() => validate({ ...demoBoard, rows: 5.5 }, shading)).toThrow(/integers/);
    expect(() => validate({ ...demoBoard, rows: 0 }, shading)).toThrow(/out of range/);
    expect(() => validate({ ...demoBoard, spark: { r: 5, c: 0 } }, shading)).toThrow(/spark/);
    expect(() => validate({ ...demoBoard, spark: { r: 0.5, c: 0 } }, shading)).toThrow(/spark/);
    expect(() => validate({ ...demoBoard, breaks: 26 }, shading)).toThrow(/breaks/);
    expect(() => validate({ ...demoBoard, clues: [{ r: 5, c: 0, m: 1 }] }, shading)).toThrow(
      /clue out of bounds/,
    );
    expect(() => validate({ ...demoBoard, clues: [{ r: 1, c: 1, m: -1 }] }, shading)).toThrow(
      /minute/,
    );
    expect(() => validate({ ...demoBoard, clues: [{ r: 1, c: 1.5, m: 1 }] }, shading)).toThrow(
      /integers/,
    );
    expect(() => validate({ ...demoBoard, clues: [{ r: 3, c: 0, m: 1 }] }, shading)).toThrow(
      /spark/,
    );
  });
});

describe('countSolutions options', () => {
  it('confirms the demo board is unique', () => {
    expect(countSolutions(demoBoard, { limit: 3 })).toEqual({ count: 1, aborted: false });
  });

  it('stops at the requested limit', () => {
    expect(countSolutions(demoBoard, { limit: 1 })).toEqual({ count: 1, aborted: false });
  });

  it('reports abortion when the node budget runs out', () => {
    const res = countSolutions(demoBoard, { nodeBudget: 1 });
    expect(res.aborted).toBe(true);
  });

  it('rejects malformed options', () => {
    expect(() => countSolutions(demoBoard, { limit: 0 })).toThrow(/limit/);
    expect(() => countSolutions(demoBoard, { nodeBudget: 0 })).toThrow(/nodeBudget/);
  });
});

describe('deduce', () => {
  it('solves the demo board exactly', () => {
    const ded = deduce(demoBoard);
    if (ded === null) throw new Error('demo board must be deducible');
    expect(shadingToBits(ded.shading)).toBe(demoSolutionBits);
    expect(ded.steps.length).toBeGreaterThan(0);
    expect(validate(demoBoard, ded.shading).valid).toBe(true);
  });

  it('returns null when no single-cell inference exists (stall)', () => {
    const board: BoardSpec = { rows: 3, cols: 3, spark: { r: 0, c: 0 }, breaks: 1, clues: [] };
    expect(deduce(board)).toBeNull();
  });

  it('returns null on a contradiction (clue can never be exact)', () => {
    const board: BoardSpec = {
      rows: 2,
      cols: 3,
      spark: { r: 0, c: 0 },
      breaks: 1,
      clues: [{ r: 0, c: 1, m: 5 }],
    };
    expect(deduce(board)).toBeNull();
  });

  it('returns null when the count-fill completion fails the exact check', () => {
    const board: BoardSpec = {
      rows: 1,
      cols: 3,
      spark: { r: 0, c: 0 },
      breaks: 0,
      clues: [{ r: 0, c: 2, m: 1 }],
    };
    expect(deduce(board)).toBeNull();
  });

  it('handles the rest-must-be-breaks count-fill', () => {
    const board: BoardSpec = {
      rows: 1,
      cols: 3,
      spark: { r: 0, c: 0 },
      breaks: 1,
      clues: [{ r: 0, c: 1, m: 1 }],
    };
    const ded = deduce(board);
    if (ded === null) throw new Error('board must be deducible');
    expect([...ded.shading]).toEqual([false, false, true]);
    expect(ded.steps).toEqual([
      {
        cell: { r: 0, c: 2 },
        state: 'break',
        reason: { kind: 'rest_must_be_breaks', clue: null, minute: null },
      },
    ]);
  });
});

describe('grade', () => {
  it('reports the certified deduction chain length', () => {
    const ded = deduce(demoBoard);
    if (ded === null) throw new Error('demo board must be deducible');
    expect(grade(demoBoard)).toEqual({ deductionSteps: ded.steps.length });
  });

  it('throws on boards that are not deduction-solvable', () => {
    const board: BoardSpec = { rows: 3, cols: 3, spark: { r: 0, c: 0 }, breaks: 1, clues: [] };
    expect(() => grade(board)).toThrow(/not deduction-solvable/);
  });
});
