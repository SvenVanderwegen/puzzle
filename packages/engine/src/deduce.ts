/**
 * Deduction-only solver (the no-guessing oracle): repeatedly assign any cell
 * for which one of the two assumptions fails the feasibility test. No
 * backtracking. Step recording follows the FROZEN orderings of
 * contracts/vectors/README.md (deduction.v1 parity): row-major scan with
 * mid-scan assignment, OPEN assumed before SHADED, count-fills checked at
 * the top of each pass emitting one step per cell row-major.
 */
import { initState, OPEN, SHADED, stateToShading, toBoard, UNKNOWN, type Board } from './board';
import { exactCheck, firstViolation, makeSolverScratch } from './feasibility';
import { at, toCell } from './grid';
import type { BoardSpec, DeductionResult, DeductionStep } from './types';

export function deduceBoard(
  bd: Board,
): { readonly steps: readonly DeductionStep[]; readonly state: Int8Array } | null {
  const state = initState(bd);
  const scratch = makeSolverScratch(bd.n);
  const steps: DeductionStep[] = [];
  let progress = true;
  while (progress) {
    progress = false;
    let nShaded = 0;
    let nUnknown = 0;
    for (let i = 0; i < bd.n; i++) {
      const v = at(state, i);
      if (v === SHADED) nShaded += 1;
      else if (v === UNKNOWN) nUnknown += 1;
    }
    if (nUnknown === 0) break;
    if (nShaded === bd.breaks) {
      for (let i = 0; i < bd.n; i++) {
        if (at(state, i) === UNKNOWN) {
          state[i] = OPEN;
          steps.push({
            cell: toCell(i, bd.cols),
            state: 'open',
            reason: { kind: 'all_breaks_placed', clue: null, minute: null },
          });
        }
      }
      break;
    }
    if (nShaded + nUnknown === bd.breaks) {
      for (let i = 0; i < bd.n; i++) {
        if (at(state, i) === UNKNOWN) {
          state[i] = SHADED;
          steps.push({
            cell: toCell(i, bd.cols),
            state: 'break',
            reason: { kind: 'rest_must_be_breaks', clue: null, minute: null },
          });
        }
      }
      break;
    }
    for (let i = 0; i < bd.n; i++) {
      if (at(state, i) !== UNKNOWN) continue;
      state[i] = OPEN;
      const whyOpen = firstViolation(bd, state, scratch);
      state[i] = SHADED;
      const whyShaded = firstViolation(bd, state, scratch);
      state[i] = UNKNOWN;
      if (whyOpen !== null && whyShaded !== null) return null; // contradiction
      if (whyOpen !== null) {
        state[i] = SHADED;
        steps.push({ cell: toCell(i, bd.cols), state: 'break', reason: whyOpen });
        progress = true;
      } else if (whyShaded !== null) {
        state[i] = OPEN;
        steps.push({ cell: toCell(i, bd.cols), state: 'open', reason: whyShaded });
        progress = true;
      }
    }
  }
  for (let i = 0; i < bd.n; i++) {
    if (at(state, i) === UNKNOWN) return null;
  }
  if (!exactCheck(bd, state, scratch)) return null;
  return { steps, state };
}

/**
 * Deduction-only solver (no backtracking). Returns null when the board is not
 * solvable by single-cell inference — generated content never is.
 */
export function deduce(board: BoardSpec): DeductionResult | null {
  const result = deduceBoard(toBoard(board));
  if (result === null) return null;
  return { steps: result.steps, shading: stateToShading(result.state) };
}
