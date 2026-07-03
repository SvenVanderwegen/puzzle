/**
 * Public types of @burnfront/engine.
 *
 * These mirror contracts/engine-api.d.ts exactly; parity is enforced by
 * api-parity.test.ts (a compile error there means the surface drifted).
 */

/** Uniform random in [0, 1). Injected; implementations must be pure given it. */
export type Rng = () => number;

export interface Cell {
  readonly r: number;
  readonly c: number;
}

/** A clue definition: this cell catches fire at exactly minute `m`. */
export interface Clue extends Cell {
  readonly m: number;
}

/**
 * Puzzle geometry + clue set. Matches the `board` object of
 * contracts/schemas/puzzle.v1.json. `clues` is canonically row-major sorted.
 */
export interface BoardSpec {
  readonly rows: number;
  readonly cols: number;
  readonly spark: Cell;
  /** Exact number of firebreaks the solver must shade. */
  readonly breaks: number;
  readonly clues: readonly Clue[];
}

/** Full assignment, row-major; `true` = firebreak. Length = rows * cols. */
export type Shading = readonly boolean[];

/** Frozen verdict order — see contracts/vectors/README.md. */
export type BurnVerdictReason =
  | 'ok'
  | 'spark_shaded'
  | 'clue_shaded'
  | 'wrong_break_count'
  | 'unreachable_cell'
  | 'clue_time_mismatch';

export interface BurnResult {
  readonly valid: boolean;
  readonly reason: BurnVerdictReason;
  /**
   * Row-major burn minutes over unshaded cells; -1 = shaded or unreached.
   * Always computed, also for invalid shadings (drives the replay).
   */
  readonly times: readonly number[];
}

export interface CountOptions {
  /** Stop counting at this many solutions (default 2). */
  readonly limit?: number;
  /** Search-node budget; exhausting it sets `aborted` (default unlimited). */
  readonly nodeBudget?: number;
}

export interface CountResult {
  /** Exact number of solutions found before `limit`/budget. */
  readonly count: number;
  /** True iff the node budget ran out — count is then a lower bound. */
  readonly aborted: boolean;
}

/** Frozen enum — see contracts/vectors/README.md for when each kind arises. */
export type DeductionKind =
  | 'too_many_breaks'
  | 'not_enough_breaks_left'
  | 'clue_unreachable_in_time'
  | 'open_cell_unreachable'
  | 'clue_reached_too_fast'
  | 'all_breaks_placed'
  | 'rest_must_be_breaks';

export interface DeductionReason {
  readonly kind: DeductionKind;
  /** The clue that forced the step, when kind concerns a clue. */
  readonly clue: Cell | null;
  /** That clue's minute, when applicable. */
  readonly minute: number | null;
}

export interface DeductionStep {
  readonly cell: Cell;
  readonly state: 'open' | 'break';
  /** The refuted assumption's first violation — Coach renders this. */
  readonly reason: DeductionReason;
}

export interface DeductionResult {
  /** Ordered per contracts/vectors/README.md frozen orderings. */
  readonly steps: readonly DeductionStep[];
  readonly shading: Shading;
}

export interface GenerateParams {
  readonly rows: number;
  readonly cols: number;
  readonly breaks: number;
  /** Stop clue minimization at this floor (difficulty knob; default 0). */
  readonly minClues?: number;
  /** Attempt/search bounds so generation terminates without a clock. */
  readonly maxAttempts?: number;
  readonly nodeBudget?: number;
}

export interface GeneratedPuzzle {
  readonly board: BoardSpec;
  readonly solution: Shading;
  readonly times: readonly number[];
  readonly deductionSteps: number;
}

export interface Grade {
  /** Length of the certified deduction chain (grading v2 may extend this). */
  readonly deductionSteps: number;
  readonly tier?: string;
  readonly score?: number;
}
