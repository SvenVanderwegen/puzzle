/**
 * Puzzle generator, run on the phone so every mode works offline.
 *
 * A typed port of the generator in reference/index.html (the frozen
 * prototype the PHP Engine is also ported from). The rules themselves
 * (adjacency, burn times) come from the shared engine via ./rules.ts.
 *
 * One difference from the prototype: a seeded fire (the daily, the campaign)
 * must come out the same on every phone, so instead of a wall-clock budget
 * it can stop after a fixed amount of work (`workBudget`). A fast and a slow
 * phone then remove exactly the same numbers.
 */
import { bfs, buildAdj } from './rules.ts';
import type { Random } from './random.ts';

export type Clue = [cell: number, minute: number];

export type Puzzle = {
  rows: number;
  cols: number;
  /** Number of firebreaks in the answer. */
  breaks: number;
  spark: number;
  clues: Clue[];
  /** Cell indexes of the firebreaks in the one answer. */
  solution: number[];
  /** Minute each cell burns in the answer, -1 for a firebreak. */
  times: number[];
};

type Adj = number[][];

type Pz = {
  R: number;
  C: number;
  n: number;
  adj: Adj;
  spark: number;
  N: number;
  clueIdx: Int32Array;
  clueVal: Int32Array;
};

const UNKNOWN = 0;
const OPEN = 1;
const SHADED = 2;

function makePuzzle(R: number, C: number, spark: number, clues: Clue[], N: number, adj: Adj): Pz {
  const clueIdx = new Int32Array(clues.length);
  const clueVal = new Int32Array(clues.length);
  clues.forEach((cv, k) => {
    clueIdx[k] = cv[0];
    clueVal[k] = cv[1];
  });
  return { R, C, n: R * C, adj, spark, N, clueIdx, clueVal };
}

function initState(pz: Pz): Int8Array {
  const s = new Int8Array(pz.n);
  s[pz.spark] = OPEN;
  for (let k = 0; k < pz.clueIdx.length; k++) s[pz.clueIdx[k]] = OPEN;
  return s;
}

/** Sound feasibility test for a partial assignment. */
function feasible(pz: Pz, state: Int8Array): boolean {
  let nSh = 0;
  let nUn = 0;
  for (let i = 0; i < pz.n; i++) {
    const v = state[i];
    if (v === SHADED) nSh++;
    else if (v === UNKNOWN) nUn++;
  }
  if (nSh > pz.N) return false;
  if (nSh + nUn < pz.N) return false;
  // Optimistic: unknowns treated as open; real times only grow from these.
  const dOpt = bfs(pz.n, pz.adj, pz.spark, (i: number) => state[i] !== SHADED);
  for (let k = 0; k < pz.clueIdx.length; k++) {
    const d = dOpt[pz.clueIdx[k]];
    if (d < 0 || d > pz.clueVal[k]) return false;
  }
  for (let i = 0; i < pz.n; i++) if (state[i] === OPEN && dOpt[i] < 0) return false;
  // Pessimistic: known-open only; fire provably travels at least this fast.
  const dPes = bfs(pz.n, pz.adj, pz.spark, (i: number) => state[i] === OPEN);
  for (let k = 0; k < pz.clueIdx.length; k++) {
    const d = dPes[pz.clueIdx[k]];
    if (d >= 0 && d < pz.clueVal[k]) return false;
  }
  return true;
}

function exactCheck(pz: Pz, state: Int8Array): boolean {
  let nSh = 0;
  for (let i = 0; i < pz.n; i++) {
    if (state[i] === UNKNOWN) return false;
    if (state[i] === SHADED) nSh++;
  }
  if (nSh !== pz.N) return false;
  const d = bfs(pz.n, pz.adj, pz.spark, (i: number) => state[i] === OPEN);
  for (let i = 0; i < pz.n; i++) if (state[i] === OPEN && d[i] < 0) return false;
  for (let k = 0; k < pz.clueIdx.length; k++) if (d[pz.clueIdx[k]] !== pz.clueVal[k]) return false;
  return true;
}

function pickBranchCell(pz: Pz, state: Int8Array): number {
  const dOpt = bfs(pz.n, pz.adj, pz.spark, (i: number) => state[i] !== SHADED);
  for (let k = 0; k < pz.clueIdx.length; k++) {
    if (dOpt[pz.clueIdx[k]] !== pz.clueVal[k]) continue;
    let x = pz.clueIdx[k];
    while (x !== pz.spark) {
      let next = -1;
      const ax = pz.adj[x];
      for (let j = 0; j < ax.length; j++) {
        const y = ax[j];
        if (dOpt[y] === dOpt[x] - 1) {
          if (state[y] === UNKNOWN) return y;
          next = y;
          break;
        }
      }
      if (next < 0) break;
      x = next;
    }
  }
  let fallback = -1;
  for (let i = 0; i < pz.n; i++) {
    if (state[i] !== UNKNOWN) continue;
    const ax = pz.adj[i];
    for (let j = 0; j < ax.length; j++) if (state[ax[j]] === OPEN) return i;
    if (fallback < 0) fallback = i;
  }
  return fallback;
}

/** Exact solution counter (the uniqueness oracle). */
export function countSolutions(pz: Pz, limit = 2, nodeBudget = 300000): { count: number; aborted: boolean } {
  let budget = nodeBudget;
  const state = initState(pz);
  let count = 0;
  let aborted = false;

  function dfs(): void {
    if (count >= limit || aborted) return;
    if (--budget < 0) {
      aborted = true;
      return;
    }
    if (!feasible(pz, state)) return;
    let nSh = 0;
    let nUn = 0;
    for (let i = 0; i < pz.n; i++) {
      const v = state[i];
      if (v === SHADED) nSh++;
      else if (v === UNKNOWN) nUn++;
    }
    if (nUn > 0 && (nSh === pz.N || nSh + nUn === pz.N)) {
      const fill = nSh === pz.N ? OPEN : SHADED;
      const filled: number[] = [];
      for (let i = 0; i < pz.n; i++) {
        if (state[i] === UNKNOWN) {
          state[i] = fill;
          filled.push(i);
        }
      }
      dfs();
      for (let j = 0; j < filled.length; j++) state[filled[j]] = UNKNOWN;
      return;
    }
    if (nUn === 0) {
      if (exactCheck(pz, state)) count++;
      return;
    }
    const x = pickBranchCell(pz, state);
    state[x] = SHADED;
    dfs();
    state[x] = UNKNOWN;
    if (count >= limit || aborted) return;
    state[x] = OPEN;
    dfs();
    state[x] = UNKNOWN;
  }
  dfs();
  return { count, aborted };
}

/** Deduction-only solver (the no-guessing oracle). */
export function deductionSolve(pz: Pz): Int8Array | null {
  const state = initState(pz);
  let progress = true;
  while (progress) {
    progress = false;
    let nSh = 0;
    let nUn = 0;
    for (let i = 0; i < pz.n; i++) {
      const v = state[i];
      if (v === SHADED) nSh++;
      else if (v === UNKNOWN) nUn++;
    }
    if (nUn === 0) break;
    if (nSh === pz.N) {
      for (let i = 0; i < pz.n; i++) if (state[i] === UNKNOWN) state[i] = OPEN;
      break;
    }
    if (nSh + nUn === pz.N) {
      for (let i = 0; i < pz.n; i++) if (state[i] === UNKNOWN) state[i] = SHADED;
      break;
    }
    for (let i = 0; i < pz.n; i++) {
      if (state[i] !== UNKNOWN) continue;
      state[i] = OPEN;
      const okOpen = feasible(pz, state);
      state[i] = SHADED;
      const okShaded = feasible(pz, state);
      state[i] = UNKNOWN;
      if (!okOpen && !okShaded) return null;
      if (!okOpen) {
        state[i] = SHADED;
        progress = true;
      } else if (!okShaded) {
        state[i] = OPEN;
        progress = true;
      }
    }
  }
  for (let i = 0; i < pz.n; i++) if (state[i] === UNKNOWN) return null;
  return exactCheck(pz, state) ? state : null;
}

function shuffle<T>(a: T[], rnd: Random): T[] {
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(rnd() * (i + 1));
    const t = a[i];
    a[i] = a[j];
    a[j] = t;
  }
  return a;
}

type Terrain = { spark: number; shaded: Uint8Array; times: Int32Array };

/** Random terrain: spark + N shaded cells with all unshaded cells connected. */
function randomTerrain(R: number, C: number, N: number, rnd: Random, adj: Adj): Terrain {
  const n = R * C;
  for (;;) {
    const spark = Math.floor(rnd() * n);
    const pool: number[] = [];
    for (let i = 0; i < n; i++) if (i !== spark) pool.push(i);
    shuffle(pool, rnd);
    const shaded = new Uint8Array(n);
    for (let k = 0; k < N; k++) shaded[pool[k]] = 1;
    const d = bfs(n, adj, spark, (i: number) => !shaded[i]);
    let reach = 0;
    for (let i = 0; i < n; i++) if (d[i] >= 0) reach++;
    if (reach === n - N) return { spark, shaded, times: d };
  }
}

/** Every firebreak, when opened, must let the fire reach some clue earlier. */
function breaksWitnessed(pz: Pz, shaded: Uint8Array): boolean {
  for (let s = 0; s < pz.n; s++) {
    if (!shaded[s]) continue;
    const d = bfs(pz.n, pz.adj, pz.spark, (i: number) => i === s || !shaded[i]);
    let changed = false;
    for (let k = 0; k < pz.clueIdx.length; k++) {
      if (d[pz.clueIdx[k]] !== pz.clueVal[k]) {
        changed = true;
        break;
      }
    }
    if (!changed) return false;
  }
  return true;
}

/** Random terrain repaired until every break is witnessed by the full time map. */
function witnessedTerrain(R: number, C: number, N: number, rnd: Random, adj: Adj): Terrain {
  const n = R * C;
  for (;;) {
    const terr = randomTerrain(R, C, N, rnd, adj);
    const { shaded, spark } = terr;
    let times = terr.times;
    let ok = false;
    for (let moves = 0; moves < 400; moves++) {
      const silent: number[] = [];
      for (let s = 0; s < n; s++) {
        if (!shaded[s]) continue;
        const d = bfs(n, adj, spark, (i: number) => i === s || !shaded[i]);
        let changed = false;
        for (let i = 0; i < n; i++) {
          if (!shaded[i] && d[i] !== times[i]) {
            changed = true;
            break;
          }
        }
        if (!changed) silent.push(s);
      }
      if (!silent.length) {
        ok = true;
        break;
      }
      const s = silent[Math.floor(rnd() * silent.length)];
      for (let tries = 0; tries < 50; tries++) {
        const t = Math.floor(rnd() * n);
        if (t === spark || shaded[t]) continue;
        shaded[s] = 0;
        shaded[t] = 1;
        const d = bfs(n, adj, spark, (i: number) => !shaded[i]);
        let reach = 0;
        for (let i = 0; i < n; i++) if (d[i] >= 0) reach++;
        if (reach === n - N) {
          times = d;
          break;
        }
        shaded[s] = 1;
        shaded[t] = 0;
      }
    }
    if (ok) return { spark, shaded, times };
  }
}

function hasDetour(R: number, C: number, spark: number, times: Int32Array): boolean {
  const sr = Math.floor(spark / C);
  const sc = spark % C;
  for (let r = 0; r < R; r++) {
    for (let c = 0; c < C; c++) {
      if (times[r * C + c] > Math.abs(r - sr) + Math.abs(c - sc)) return true;
    }
  }
  return false;
}

export type GenerateOptions = {
  random?: Random;
  /** Wall-clock budget for removing numbers. Ignored when workBudget is set. */
  budgetMs?: number;
  /** Deterministic budget: number of removal trials before stopping. */
  workBudget?: number;
  minClues?: number;
  /** Let the UI breathe every this many trials. */
  yieldEvery?: number;
};

const pause = () => new Promise<void>((resolve) => setTimeout(resolve, 0));

/**
 * Full solution first, every number shown (trivially unique), then numbers
 * removed one at a time while both oracles still agree there is exactly one
 * answer that can be found without guessing.
 */
export async function generatePuzzle(R: number, C: number, N: number, opts: GenerateOptions = {}): Promise<Puzzle> {
  const rnd = opts.random ?? Math.random;
  const budgetMs = opts.budgetMs ?? 8000;
  const workBudget = opts.workBudget;
  const yieldEvery = opts.yieldEvery ?? 8;
  const minClues = opts.minClues ?? 0;
  const adj: Adj = buildAdj(R, C);
  const t0 = Date.now();
  let work = 0;
  const outOfBudget = () => (workBudget != null ? work >= workBudget : Date.now() - t0 >= budgetMs);
  const tick = async () => {
    if (++work % yieldEvery === 0) await pause();
  };

  for (;;) {
    const terr = witnessedTerrain(R, C, N, rnd, adj);
    await pause();
    if (!hasDetour(R, C, terr.spark, terr.times)) continue;
    let clues: Clue[] = [];
    for (let i = 0; i < R * C; i++) if (!terr.shaded[i] && i !== terr.spark) clues.push([i, terr.times[i]]);
    let removed = true;
    while (removed && !outOfBudget() && clues.length > minClues) {
      removed = false;
      const order = shuffle(
        clues.map((cv) => cv[0]),
        rnd,
      );
      for (const cell of order) {
        if (outOfBudget() || clues.length <= minClues) break;
        const trialClues = clues.filter((cv) => cv[0] !== cell);
        if (trialClues.length === clues.length) continue;
        const trial = makePuzzle(R, C, terr.spark, trialClues, N, adj);
        if (!breaksWitnessed(trial, terr.shaded)) {
          await tick();
          continue;
        }
        const res = countSolutions(trial, 2, 60000);
        if (!res.aborted && res.count === 1 && deductionSolve(trial)) {
          clues = trialClues;
          removed = true;
        }
        await tick();
      }
    }
    const solution: number[] = [];
    for (let i = 0; i < R * C; i++) if (terr.shaded[i]) solution.push(i);
    return {
      rows: R,
      cols: C,
      breaks: N,
      spark: terr.spark,
      clues: clues.sort((a, b) => a[0] - b[0]),
      solution,
      times: Array.from(terr.times),
    };
  }
}
