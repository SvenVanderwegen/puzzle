/**
 * Vector crosscheck — contracts/vectors/ is law (CLAUDE.md rule 3).
 * Runs ALL THREE vector files:
 *   burn.v1.jsonl      — validate() reproduces valid+reason+times (509 cases)
 *   generate.v1.jsonl  — certificates confirmed by this engine (50 boards)
 *   deduction.v1.jsonl — deduce() reproduces the exact ordered step lists
 */
import { describe, expect, it } from 'vitest';
import burnRaw from '../../../contracts/vectors/burn.v1.jsonl?raw';
import deductionRaw from '../../../contracts/vectors/deduction.v1.jsonl?raw';
import generateRaw from '../../../contracts/vectors/generate.v1.jsonl?raw';
import { bitsToShading, countSolutions, deduce, shadingToBits, validate } from './index';
import type { BoardSpec, BurnVerdictReason, DeductionKind, DeductionStep } from './index';

interface VectorClue {
  readonly r: number;
  readonly c: number;
  readonly m: number;
}

interface BoardVector {
  readonly rows: number;
  readonly cols: number;
  readonly breaks: number;
  readonly spark: readonly [number, number];
  readonly clues: readonly VectorClue[];
}

interface BurnVector extends BoardVector {
  readonly id: string;
  readonly shading: string;
  readonly times: readonly number[];
  readonly valid: boolean;
  readonly reason: BurnVerdictReason;
}

interface GenerateVector extends BoardVector {
  readonly id: string;
  readonly seed: number;
  readonly solution: string;
  readonly times: readonly number[];
  readonly unique: boolean;
  readonly deduction_steps: number;
  readonly nonunique_without_clue: readonly [number, number] | null;
}

interface DeductionVector {
  readonly id: string;
  readonly steps: readonly {
    readonly cell: readonly [number, number];
    readonly state: 'open' | 'break';
    readonly reason: {
      readonly kind: DeductionKind;
      readonly clue: readonly [number, number] | null;
      readonly minute: number | null;
    };
  }[];
}

function parseJsonl<T>(raw: string): T[] {
  return raw
    .trimEnd()
    .split('\n')
    .map((line) => JSON.parse(line) as T);
}

function boardOf(v: BoardVector): BoardSpec {
  return {
    rows: v.rows,
    cols: v.cols,
    breaks: v.breaks,
    spark: { r: v.spark[0], c: v.spark[1] },
    clues: v.clues.map(({ r, c, m }) => ({ r, c, m })),
  };
}

const burnVectors = parseJsonl<BurnVector>(burnRaw);
const generateVectors = parseJsonl<GenerateVector>(generateRaw);
const deductionVectors = parseJsonl<DeductionVector>(deductionRaw);
const generateById = new Map(generateVectors.map((v) => [v.id, v]));

describe('burn.v1.jsonl', () => {
  it('has the frozen case count', () => {
    expect(burnVectors.length).toBe(509);
  });

  it('validate() reproduces valid + reason + times for every case', () => {
    for (const v of burnVectors) {
      const res = validate(boardOf(v), bitsToShading(v.shading));
      expect({ id: v.id, valid: res.valid, reason: res.reason, times: [...res.times] }).toEqual({
        id: v.id,
        valid: v.valid,
        reason: v.reason,
        times: [...v.times],
      });
    }
  });
});

describe('generate.v1.jsonl', () => {
  it('has the frozen case count', () => {
    expect(generateVectors.length).toBe(50);
  });

  it('validate(solution) is ok with matching times', () => {
    for (const v of generateVectors) {
      const res = validate(boardOf(v), bitsToShading(v.solution));
      expect({ id: v.id, valid: res.valid, reason: res.reason, times: [...res.times] }).toEqual({
        id: v.id,
        valid: true,
        reason: 'ok',
        times: [...v.times],
      });
    }
  });

  it('countSolutions(board) confirms uniqueness exactly', () => {
    for (const v of generateVectors) {
      expect({ id: v.id, ...countSolutions(boardOf(v)) }).toEqual({
        id: v.id,
        count: 1,
        aborted: false,
      });
    }
  });

  it('deduce(board) reaches the exact solution in the certified step count', () => {
    for (const v of generateVectors) {
      const ded = deduce(boardOf(v));
      if (ded === null) throw new Error(`${v.id}: deduce returned null`);
      expect({ id: v.id, solution: shadingToBits(ded.shading), steps: ded.steps.length }).toEqual({
        id: v.id,
        solution: v.solution,
        steps: v.deduction_steps,
      });
    }
  });

  it('dropping the flagged clue provably breaks uniqueness', () => {
    for (const v of generateVectors) {
      if (v.nonunique_without_clue === null) continue;
      const [r, c] = v.nonunique_without_clue;
      const board = boardOf(v);
      const reduced: BoardSpec = {
        ...board,
        clues: board.clues.filter((clue) => clue.r !== r || clue.c !== c),
      };
      expect(reduced.clues.length).toBe(board.clues.length - 1);
      const res = countSolutions(reduced);
      expect({ id: v.id, aborted: res.aborted }).toEqual({ id: v.id, aborted: false });
      expect(res.count, v.id).toBeGreaterThanOrEqual(2);
    }
  });
});

describe('deduction.v1.jsonl', () => {
  it('has the frozen case count', () => {
    expect(deductionVectors.length).toBe(50);
  });

  it('deduce() reproduces the exact ordered step lists with structured reasons', () => {
    for (const v of deductionVectors) {
      const gen = generateById.get(v.id);
      if (gen === undefined) throw new Error(`${v.id}: no matching generate vector`);
      const expected: DeductionStep[] = v.steps.map((s) => ({
        cell: { r: s.cell[0], c: s.cell[1] },
        state: s.state,
        reason: {
          kind: s.reason.kind,
          clue: s.reason.clue === null ? null : { r: s.reason.clue[0], c: s.reason.clue[1] },
          minute: s.reason.minute,
        },
      }));
      const ded = deduce(boardOf(gen));
      if (ded === null) throw new Error(`${v.id}: deduce returned null`);
      expect({ id: v.id, steps: ded.steps }).toEqual({ id: v.id, steps: expected });
    }
  });
});
