/**
 * Exact solution counter (the uniqueness oracle). Exhaustive DFS whose
 * pruning never discards a completable state, so the count is exact up to
 * `limit`. Ports countSolutions() of the reference fb-engine.
 */
import { initState, OPEN, SHADED, toBoard, UNKNOWN, type Board } from './board';
import { exactCheck, feasible, makeSolverScratch, type SolverScratch } from './feasibility';
import { at, bfs } from './grid';
import type { BoardSpec, CountOptions, CountResult } from './types';

/**
 * Prefer an unknown cell on a tight optimistic path to some clue; these
 * decisions cut the search fastest. Fall back to any unknown adjacent to an
 * open cell, then to any unknown. Branch choice never changes the count.
 */
function pickBranchCell(bd: Board, state: Int8Array, scratch: SolverScratch): number {
  const dOpt = bfs(bd.n, bd.adj, bd.spark, (i) => at(state, i) !== SHADED, scratch.opt);
  for (let k = 0; k < bd.clueIdx.length; k++) {
    if (at(dOpt, at(bd.clueIdx, k)) !== at(bd.clueVal, k)) continue;
    let x = at(bd.clueIdx, k);
    while (x !== bd.spark) {
      let next = -1;
      for (const y of bd.adj[x] ?? []) {
        if (at(dOpt, y) === at(dOpt, x) - 1) {
          if (at(state, y) === UNKNOWN) return y;
          next = y;
          break;
        }
      }
      if (next < 0) break;
      x = next;
    }
  }
  let fallback = -1;
  for (let i = 0; i < bd.n; i++) {
    if (at(state, i) !== UNKNOWN) continue;
    for (const y of bd.adj[i] ?? []) {
      if (at(state, y) === OPEN) return i;
    }
    if (fallback < 0) fallback = i;
  }
  return fallback;
}

export function countSolutionsForBoard(bd: Board, limit: number, nodeBudget: number): CountResult {
  const state = initState(bd);
  const scratch = makeSolverScratch(bd.n);
  let count = 0;
  let aborted = false;
  let budget = nodeBudget;
  // A helper (not an inline condition) so control-flow analysis cannot
  // over-narrow the captured `aborted` flag across recursive calls.
  const stopped = (): boolean => count >= limit || aborted;

  const dfs = (): void => {
    if (stopped()) return;
    budget -= 1;
    if (budget < 0) {
      aborted = true;
      return;
    }
    if (!feasible(bd, state, scratch)) return;
    let nShaded = 0;
    let nUnknown = 0;
    for (let i = 0; i < bd.n; i++) {
      const v = at(state, i);
      if (v === SHADED) nShaded += 1;
      else if (v === UNKNOWN) nUnknown += 1;
    }
    // Forced completions by the break count.
    if (nUnknown > 0 && (nShaded === bd.breaks || nShaded + nUnknown === bd.breaks)) {
      const fill = nShaded === bd.breaks ? OPEN : SHADED;
      const filled: number[] = [];
      for (let i = 0; i < bd.n; i++) {
        if (at(state, i) === UNKNOWN) {
          state[i] = fill;
          filled.push(i);
        }
      }
      dfs();
      for (const i of filled) state[i] = UNKNOWN;
      return;
    }
    if (nUnknown === 0) {
      if (exactCheck(bd, state, scratch)) count += 1;
      return;
    }
    const x = pickBranchCell(bd, state, scratch);
    state[x] = SHADED;
    dfs();
    state[x] = UNKNOWN;
    if (stopped()) return;
    state[x] = OPEN;
    dfs();
    state[x] = UNKNOWN;
  };

  dfs();
  return { count, aborted };
}

/** Exact solution counter (the uniqueness oracle). */
export function countSolutions(board: BoardSpec, opts?: CountOptions): CountResult {
  const limit = opts?.limit ?? 2;
  const nodeBudget = opts?.nodeBudget ?? Number.POSITIVE_INFINITY;
  if (!Number.isInteger(limit) || limit < 1) {
    throw new Error('countSolutions: limit must be a positive integer');
  }
  if (Number.isNaN(nodeBudget) || nodeBudget < 1) {
    throw new Error('countSolutions: nodeBudget must be >= 1');
  }
  return countSolutionsForBoard(toBoard(board), limit, nodeBudget);
}
