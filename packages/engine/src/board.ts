/**
 * Internal board representation and BoardSpec validation.
 * Cell states: 0 unknown, 1 open, 2 shaded (parity with the reference).
 */
import { at, buildAdjacency, cellIndex } from './grid';
import type { BoardSpec, Clue } from './types';

export const UNKNOWN = 0;
export const OPEN = 1;
export const SHADED = 2;

/** Shared structural bound (also the codec's): boards never exceed this. */
export const MAX_DIM = 64;

export interface Board {
  readonly rows: number;
  readonly cols: number;
  readonly n: number;
  readonly spark: number;
  readonly breaks: number;
  readonly adj: readonly (readonly number[])[];
  /** Clue cell indices, canonically row-major sorted. */
  readonly clueIdx: readonly number[];
  readonly clueVal: readonly number[];
}

/** Throws on structurally malformed specs (shared by validate/codec/etc.). */
export function assertBoardSpec(spec: BoardSpec): void {
  const { rows, cols, spark, breaks, clues } = spec;
  if (!Number.isInteger(rows) || !Number.isInteger(cols)) {
    throw new Error('BoardSpec: rows/cols must be integers');
  }
  if (rows < 1 || cols < 1 || rows > MAX_DIM || cols > MAX_DIM) {
    throw new Error(`BoardSpec: rows/cols out of range 1..${String(MAX_DIM)}`);
  }
  const n = rows * cols;
  if (!Number.isInteger(spark.r) || !Number.isInteger(spark.c)) {
    throw new Error('BoardSpec: spark must have integer coordinates');
  }
  if (spark.r < 0 || spark.r >= rows || spark.c < 0 || spark.c >= cols) {
    throw new Error('BoardSpec: spark out of bounds');
  }
  if (!Number.isInteger(breaks) || breaks < 0 || breaks > n) {
    throw new Error('BoardSpec: breaks out of range');
  }
  const sparkIdx = cellIndex(spark, cols);
  const seen = new Set<number>();
  for (const clue of clues) {
    if (!Number.isInteger(clue.r) || !Number.isInteger(clue.c) || !Number.isInteger(clue.m)) {
      throw new Error('BoardSpec: clue fields must be integers');
    }
    if (clue.r < 0 || clue.r >= rows || clue.c < 0 || clue.c >= cols) {
      throw new Error('BoardSpec: clue out of bounds');
    }
    if (clue.m < 0) {
      throw new Error('BoardSpec: clue minute must be >= 0');
    }
    const idx = cellIndex(clue, cols);
    if (idx === sparkIdx) {
      throw new Error('BoardSpec: clue on the spark cell');
    }
    if (seen.has(idx)) {
      throw new Error('BoardSpec: duplicate clue cell');
    }
    seen.add(idx);
  }
}

/** Canonical clue order: row-major by (r, c). */
export function sortClues(clues: readonly Clue[]): Clue[] {
  return [...clues].sort((a, b) => a.r - b.r || a.c - b.c);
}

/** Build an internal board from pre-sorted (row-major) [index, minute] pairs. */
export function boardFromParts(
  rows: number,
  cols: number,
  spark: number,
  cluePairs: readonly (readonly [number, number])[],
  breaks: number,
  adj: readonly (readonly number[])[],
): Board {
  return {
    rows,
    cols,
    n: rows * cols,
    spark,
    breaks,
    adj,
    clueIdx: cluePairs.map((cv) => cv[0]),
    clueVal: cluePairs.map((cv) => cv[1]),
  };
}

/** Validate and convert a public BoardSpec (clues canonicalized). */
export function toBoard(spec: BoardSpec): Board {
  assertBoardSpec(spec);
  const pairs = sortClues(spec.clues).map((clue) => [cellIndex(clue, spec.cols), clue.m] as const);
  return boardFromParts(
    spec.rows,
    spec.cols,
    cellIndex(spec.spark, spec.cols),
    pairs,
    spec.breaks,
    buildAdjacency(spec.rows, spec.cols),
  );
}

/** Initial deduction state: spark and clue cells are open, rest unknown. */
export function initState(bd: Board): Int8Array {
  const state = new Int8Array(bd.n);
  state[bd.spark] = OPEN;
  for (const idx of bd.clueIdx) state[idx] = OPEN;
  return state;
}

/** Convert a complete internal state to the public shading encoding. */
export function stateToShading(state: Int8Array): boolean[] {
  const shading: boolean[] = [];
  for (let i = 0; i < state.length; i++) shading.push(at(state, i) === SHADED);
  return shading;
}
