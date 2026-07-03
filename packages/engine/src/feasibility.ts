/**
 * Feasibility of a partial assignment, with the FROZEN first-violation order
 * (contracts/vectors/README.md): too_many_breaks -> not_enough_breaks_left ->
 * clue_unreachable_in_time (clues row-major) -> open_cell_unreachable (cells
 * row-major) -> clue_reached_too_fast (clues row-major).
 *
 * The pruning is sound: it never rejects a state that can still be completed
 * to a real solution. Ports feasible()/_first_violation() of the reference.
 */
import { OPEN, SHADED, UNKNOWN, type Board } from './board';
import { at, bfs, makeBfsScratch, toCell, type BfsScratch } from './grid';
import type { DeductionReason } from './types';

/**
 * Reusable buffers for the two BFS passes of a feasibility check. One
 * solver invocation owns one scratch; results never outlive the next call.
 */
export interface SolverScratch {
  readonly opt: BfsScratch;
  readonly pes: BfsScratch;
}

export function makeSolverScratch(n: number): SolverScratch {
  return { opt: makeBfsScratch(n), pes: makeBfsScratch(n) };
}

/** First violated check for a partial state, or null when feasible. */
export function firstViolation(
  bd: Board,
  state: Int8Array,
  scratch?: SolverScratch,
): DeductionReason | null {
  let nShaded = 0;
  let nUnknown = 0;
  for (let i = 0; i < bd.n; i++) {
    const v = at(state, i);
    if (v === SHADED) nShaded += 1;
    else if (v === UNKNOWN) nUnknown += 1;
  }
  if (nShaded > bd.breaks) {
    return { kind: 'too_many_breaks', clue: null, minute: null };
  }
  if (nShaded + nUnknown < bd.breaks) {
    return { kind: 'not_enough_breaks_left', clue: null, minute: null };
  }

  // Optimistic minutes: unknowns treated as open; real times only grow from
  // these, so a clue needs dOpt(c) <= m and every known-open cell reachable.
  const dOpt = bfs(bd.n, bd.adj, bd.spark, (i) => at(state, i) !== SHADED, scratch?.opt);
  for (let k = 0; k < bd.clueIdx.length; k++) {
    const d = at(dOpt, at(bd.clueIdx, k));
    const m = at(bd.clueVal, k);
    if (d < 0 || d > m) {
      return {
        kind: 'clue_unreachable_in_time',
        clue: toCell(at(bd.clueIdx, k), bd.cols),
        minute: m,
      };
    }
  }
  for (let i = 0; i < bd.n; i++) {
    if (at(state, i) === OPEN && at(dOpt, i) < 0) {
      return { kind: 'open_cell_unreachable', clue: null, minute: null };
    }
  }

  // Pessimistic minutes: known-open cells only; the fire provably travels at
  // least this fast, so a clue needs dPes(c) >= m.
  const dPes = bfs(bd.n, bd.adj, bd.spark, (i) => at(state, i) === OPEN, scratch?.pes);
  for (let k = 0; k < bd.clueIdx.length; k++) {
    const d = at(dPes, at(bd.clueIdx, k));
    const m = at(bd.clueVal, k);
    if (d >= 0 && d < m) {
      return {
        kind: 'clue_reached_too_fast',
        clue: toCell(at(bd.clueIdx, k), bd.cols),
        minute: m,
      };
    }
  }
  return null;
}

export function feasible(bd: Board, state: Int8Array, scratch?: SolverScratch): boolean {
  return firstViolation(bd, state, scratch) === null;
}

/** Exact verification of a complete assignment (ports exact_check()). */
export function exactCheck(bd: Board, state: Int8Array, scratch?: SolverScratch): boolean {
  let nShaded = 0;
  for (let i = 0; i < bd.n; i++) {
    const v = at(state, i);
    if (v === UNKNOWN) return false;
    if (v === SHADED) nShaded += 1;
  }
  if (nShaded !== bd.breaks) return false;
  const d = bfs(bd.n, bd.adj, bd.spark, (i) => at(state, i) === OPEN, scratch?.pes);
  for (let i = 0; i < bd.n; i++) {
    if (at(state, i) === OPEN && at(d, i) < 0) return false;
  }
  for (let k = 0; k < bd.clueIdx.length; k++) {
    if (at(d, at(bd.clueIdx, k)) !== at(bd.clueVal, k)) return false;
  }
  return true;
}
