/**
 * @burnfront/engine — FROZEN public API (WS-01; ADR-0011 governs changes).
 *
 * WS-02 implements exactly this surface; every consumer compiles against it.
 * Behavioral truth lives in contracts/vectors/ (see vectors/README.md for the
 * frozen scan/check orderings). Determinism law: the engine never touches
 * Date.now or Math.random — time and randomness are injected.
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

/** Row-major bit-string codec ('1' = firebreak), as used by the vectors. */
export declare function shadingToBits(shading: Shading): string;
export declare function bitsToShading(bits: string): Shading;

/** Frozen verdict order — see vectors/README.md. */
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

/** Validate a complete shading against a board. Mirrors burn.v1 vectors. */
export declare function validate(board: BoardSpec, shading: Shading): BurnResult;

/** BFS burn minutes only (no verdict). Same encoding as BurnResult.times. */
export declare function burnTimes(
  rows: number,
  cols: number,
  spark: Cell,
  shading: Shading,
): number[];

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

/** Exact solution counter (the uniqueness oracle). */
export declare function countSolutions(
  board: BoardSpec,
  opts?: CountOptions,
): CountResult;

/** Frozen enum — see vectors/README.md for when each kind arises. */
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
  /** Ordered per vectors/README.md frozen orderings (deduction.v1 parity). */
  readonly steps: readonly DeductionStep[];
  readonly shading: Shading;
}

/**
 * Deduction-only solver (no backtracking). Returns null when the board is not
 * solvable by single-cell inference — generated content never is.
 */
export declare function deduce(board: BoardSpec): DeductionResult | null;

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

/**
 * Generate a puzzle carrying the three certificates: unique solution,
 * deduction-solvable, every break witnessed. PRNG parity with the Python
 * reference is NOT required (vectors/README.md) — certificates are.
 */
export declare function generate(params: GenerateParams, rng: Rng): GeneratedPuzzle;

export interface Grade {
  /** Length of the certified deduction chain (grading v2 may extend this). */
  readonly deductionSteps: number;
  readonly tier?: string;
  readonly score?: number;
}

export declare function grade(board: BoardSpec): Grade;

/**
 * Compact shareable puzzle code. Law: decodePuzzle(encodePuzzle(b)) is deeply
 * equal to b (clues in canonical order). decodePuzzle throws on malformed input.
 */
export declare function encodePuzzle(board: BoardSpec): string;
export declare function decodePuzzle(code: string): BoardSpec;
