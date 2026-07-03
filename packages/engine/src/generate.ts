/**
 * Generator: witnessed terrain + greedy clue minimization, ported from the
 * reference fb-engine but fully deterministic — no clock, no ambient
 * randomness. Time budgets are replaced by maxAttempts (terrain attempts)
 * and nodeBudget (uniqueness search). Same rng => identical output, forever.
 *
 * Certificates carried by every returned puzzle: unique solution (invariant
 * of the minimization), deduction-solvable, every break witnessed by the
 * remaining clues. PRNG parity with the Python reference is not required.
 */
import { boardFromParts, MAX_DIM, type Board } from './board';
import { countSolutionsForBoard } from './count';
import { deduceBoard } from './deduce';
import { at, bfs, buildAdjacency, makeBfsScratch, toCell, type BfsScratch } from './grid';
import type { BoardSpec, GeneratedPuzzle, GenerateParams, Rng } from './types';

const REPAIR_MOVES = 400;
const RELOCATE_TRIES = 50;

export function shuffleInPlace<T>(arr: T[], rng: Rng): T[] {
  for (let i = arr.length - 1; i > 0; i--) {
    const j = Math.floor(rng() * (i + 1));
    const tmp = arr[i] as T;
    arr[i] = arr[j] as T;
    arr[j] = tmp;
  }
  return arr;
}

interface Terrain {
  readonly spark: number;
  readonly shaded: Uint8Array;
  readonly times: Int32Array;
}

/** One random terrain sample; null when the open cells are not connected. */
function sampleTerrain(
  n: number,
  adj: readonly (readonly number[])[],
  breaks: number,
  rng: Rng,
): Terrain | null {
  const spark = Math.floor(rng() * n);
  const pool: number[] = [];
  for (let i = 0; i < n; i++) {
    if (i !== spark) pool.push(i);
  }
  shuffleInPlace(pool, rng);
  const shaded = new Uint8Array(n);
  for (let k = 0; k < breaks; k++) shaded[at(pool, k)] = 1;
  const times = bfs(n, adj, spark, (i) => at(shaded, i) === 0);
  let reach = 0;
  for (let i = 0; i < n; i++) {
    if (at(times, i) >= 0) reach += 1;
  }
  return reach === n - breaks ? { spark, shaded, times } : null;
}

/**
 * Repair a terrain until every break is witnessed by the full time map:
 * silent breaks (whose opening changes no burn time) are relocated to fresh
 * connectivity-preserving positions until none remain. Null on give-up.
 */
function repairWitnessed(
  terrain: Terrain,
  n: number,
  adj: readonly (readonly number[])[],
  breaks: number,
  rng: Rng,
  scratch: BfsScratch,
): Terrain | null {
  const { spark, shaded } = terrain;
  let times = terrain.times;
  for (let moves = 0; moves < REPAIR_MOVES; moves++) {
    const silent: number[] = [];
    for (let s = 0; s < n; s++) {
      if (at(shaded, s) !== 1) continue;
      const d = bfs(n, adj, spark, (i) => i === s || at(shaded, i) === 0, scratch);
      let changed = false;
      for (let i = 0; i < n; i++) {
        if (at(shaded, i) === 0 && at(d, i) !== at(times, i)) {
          changed = true;
          break;
        }
      }
      if (!changed) silent.push(s);
    }
    if (silent.length === 0) return { spark, shaded, times };
    const s = at(silent, Math.floor(rng() * silent.length));
    for (let tries = 0; tries < RELOCATE_TRIES; tries++) {
      const target = Math.floor(rng() * n);
      if (target === spark || at(shaded, target) === 1) continue;
      shaded[s] = 0;
      shaded[target] = 1;
      const d = bfs(n, adj, spark, (i) => at(shaded, i) === 0);
      let reach = 0;
      for (let i = 0; i < n; i++) {
        if (at(d, i) >= 0) reach += 1;
      }
      if (reach === n - breaks) {
        times = d;
        break;
      }
      shaded[s] = 1;
      shaded[target] = 0;
    }
  }
  return null;
}

/** True iff some burn time exceeds its Manhattan distance from the spark. */
function hasDetour(rows: number, cols: number, spark: number, times: Int32Array): boolean {
  const sr = Math.floor(spark / cols);
  const sc = spark % cols;
  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < cols; c++) {
      if (at(times, r * cols + c) > Math.abs(r - sr) + Math.abs(c - sc)) return true;
    }
  }
  return false;
}

/**
 * Every firebreak, when opened (the others staying), must let the fire reach
 * at least one clued cell at a different minute than its number. Such a
 * break is "witnessed": the clues justify it, never the count alone.
 */
function breaksWitnessed(
  n: number,
  adj: readonly (readonly number[])[],
  spark: number,
  shaded: Uint8Array,
  cluePairs: readonly (readonly [number, number])[],
  scratch: BfsScratch,
): boolean {
  for (let s = 0; s < n; s++) {
    if (at(shaded, s) !== 1) continue;
    const d = bfs(n, adj, spark, (i) => i === s || at(shaded, i) === 0, scratch);
    let changed = false;
    for (const [idx, minute] of cluePairs) {
      if (at(d, idx) !== minute) {
        changed = true;
        break;
      }
    }
    if (!changed) return false;
  }
  return true;
}

function assertParams(
  params: GenerateParams,
  minClues: number,
  maxAttempts: number,
  nodeBudget: number,
): void {
  const { rows, cols, breaks } = params;
  if (!Number.isInteger(rows) || !Number.isInteger(cols)) {
    throw new Error('generate: rows/cols must be integers');
  }
  if (rows < 1 || cols < 1 || rows > MAX_DIM || cols > MAX_DIM) {
    throw new Error(`generate: rows/cols out of range 1..${String(MAX_DIM)}`);
  }
  if (!Number.isInteger(breaks) || breaks < 1 || breaks > rows * cols - 2) {
    throw new Error('generate: breaks out of range 1..rows*cols-2');
  }
  if (!Number.isInteger(minClues) || minClues < 0) {
    throw new Error('generate: minClues must be a non-negative integer');
  }
  if (!Number.isInteger(maxAttempts) || maxAttempts < 1) {
    throw new Error('generate: maxAttempts must be a positive integer');
  }
  if (Number.isNaN(nodeBudget) || nodeBudget < 1) {
    throw new Error('generate: nodeBudget must be >= 1');
  }
}

/**
 * Generate a puzzle carrying the three certificates: unique solution,
 * deduction-solvable, every break witnessed. Full solution first; the full
 * clue set is trivially unique and deducible; then greedy clue deletion to a
 * fixpoint, each deletion re-verified by BOTH oracles plus the witness
 * check, so uniqueness is an invariant and stopping early is always safe.
 */
export function generate(params: GenerateParams, rng: Rng): GeneratedPuzzle {
  const { rows, cols, breaks } = params;
  const minClues = params.minClues ?? 0;
  const maxAttempts = params.maxAttempts ?? 500;
  const nodeBudget = params.nodeBudget ?? 60000;
  assertParams(params, minClues, maxAttempts, nodeBudget);
  const n = rows * cols;
  const adj = buildAdjacency(rows, cols);
  const scratch = makeBfsScratch(n);

  for (let attempt = 0; attempt < maxAttempts; attempt++) {
    const sampled = sampleTerrain(n, adj, breaks, rng);
    if (sampled === null) continue;
    const terrain = repairWitnessed(sampled, n, adj, breaks, rng, scratch);
    if (terrain === null) continue;
    if (!hasDetour(rows, cols, terrain.spark, terrain.times)) continue;

    // Full clue set (row-major, hence canonically sorted).
    let clues: (readonly [number, number])[] = [];
    for (let i = 0; i < n; i++) {
      if (at(terrain.shaded, i) === 0 && i !== terrain.spark) {
        clues.push([i, at(terrain.times, i)]);
      }
    }

    // Greedy clue deletion to a fixpoint.
    let removed = true;
    while (removed && clues.length > minClues) {
      removed = false;
      const order = shuffleInPlace(
        clues.map((cv) => cv[0]),
        rng,
      );
      for (const cell of order) {
        if (clues.length <= minClues) break;
        const trial = clues.filter((cv) => cv[0] !== cell);
        if (!breaksWitnessed(n, adj, terrain.spark, terrain.shaded, trial, scratch)) continue;
        const bd: Board = boardFromParts(rows, cols, terrain.spark, trial, breaks, adj);
        const res = countSolutionsForBoard(bd, 2, nodeBudget);
        if (res.aborted || res.count !== 1) continue;
        if (deduceBoard(bd) === null) continue;
        clues = trial;
        removed = true;
      }
    }

    const bd = boardFromParts(rows, cols, terrain.spark, clues, breaks, adj);
    const deduced = deduceBoard(bd);
    if (deduced === null) continue; // defensive: certified during minimization
    const board: BoardSpec = {
      rows,
      cols,
      spark: toCell(terrain.spark, cols),
      breaks,
      clues: clues.map(([idx, m]) => ({ ...toCell(idx, cols), m })),
    };
    return {
      board,
      solution: Array.from(terrain.shaded, (v) => v === 1),
      times: Array.from(terrain.times),
      deductionSteps: deduced.steps.length,
    };
  }
  throw new Error(`generate: no puzzle within ${String(maxAttempts)} attempts`);
}
