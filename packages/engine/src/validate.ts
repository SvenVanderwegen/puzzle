/**
 * Burn simulation + verdict with the FROZEN check order (burn.v1 parity):
 * spark_shaded -> clue_shaded (row-major) -> wrong_break_count ->
 * unreachable_cell (row-major first) -> clue_time_mismatch (row-major first)
 * -> ok. Times are always computed, also for invalid shadings.
 */
import { toBoard, type Board } from './board';
import { at, bfs, buildAdjacency, cellIndex } from './grid';
import type { BoardSpec, BurnResult, BurnVerdictReason, Cell, Shading } from './types';

function isShaded(shading: Shading, i: number): boolean {
  return shading[i] === true;
}

/** BFS minutes over unshaded cells; -1 = shaded or unreached. */
function burnDistances(
  n: number,
  adj: readonly (readonly number[])[],
  spark: number,
  shading: Shading,
): Int32Array {
  return bfs(n, adj, spark, (i) => !isShaded(shading, i));
}

/** BFS burn minutes only (no verdict). Same encoding as BurnResult.times. */
export function burnTimes(rows: number, cols: number, spark: Cell, shading: Shading): number[] {
  if (!Number.isInteger(rows) || !Number.isInteger(cols) || rows < 1 || cols < 1) {
    throw new Error('burnTimes: rows/cols must be positive integers');
  }
  if (
    !Number.isInteger(spark.r) ||
    !Number.isInteger(spark.c) ||
    spark.r < 0 ||
    spark.r >= rows ||
    spark.c < 0 ||
    spark.c >= cols
  ) {
    throw new Error('burnTimes: spark out of bounds');
  }
  const n = rows * cols;
  if (shading.length !== n) {
    throw new Error('burnTimes: shading length must equal rows * cols');
  }
  const dist = burnDistances(n, buildAdjacency(rows, cols), cellIndex(spark, cols), shading);
  return Array.from(dist);
}

function verdict(bd: Board, shading: Shading, dist: Int32Array): BurnVerdictReason {
  if (isShaded(shading, bd.spark)) return 'spark_shaded';
  for (const idx of bd.clueIdx) {
    if (isShaded(shading, idx)) return 'clue_shaded';
  }
  let nShaded = 0;
  for (let i = 0; i < bd.n; i++) {
    if (isShaded(shading, i)) nShaded += 1;
  }
  if (nShaded !== bd.breaks) return 'wrong_break_count';
  for (let i = 0; i < bd.n; i++) {
    if (!isShaded(shading, i) && at(dist, i) < 0) return 'unreachable_cell';
  }
  for (let k = 0; k < bd.clueIdx.length; k++) {
    if (at(dist, at(bd.clueIdx, k)) !== at(bd.clueVal, k)) return 'clue_time_mismatch';
  }
  return 'ok';
}

/** Validate a complete shading against a board. Mirrors burn.v1 vectors. */
export function validate(board: BoardSpec, shading: Shading): BurnResult {
  const bd = toBoard(board);
  if (shading.length !== bd.n) {
    throw new Error('validate: shading length must equal rows * cols');
  }
  const dist = burnDistances(bd.n, bd.adj, bd.spark, shading);
  const reason = verdict(bd, shading, dist);
  return { valid: reason === 'ok', reason, times: Array.from(dist) };
}
