/**
 * Shared types of @burnfront/game-core.
 *
 * Everything environment-facing is an injected interface: clock, storage,
 * compressor, hasher, RNG. game-core itself never touches the DOM, the
 * network, Date.now, or Math.random (CLAUDE.md rule 8; ESLint-enforced).
 */
import type { BoardSpec } from '@burnfront/engine';

/** A player mark on one cell. 'dot' = "certified open" annotation. */
export type Mark = 'empty' | 'break' | 'dot';

/** Wire codes for marks in the replay event log: 0 empty, 1 break, 2 dot. */
export const MARK_CODES = { empty: 0, break: 1, dot: 2 } as const;
export type MarkCode = (typeof MARK_CODES)[Mark];

export const MARK_FROM_CODE: readonly Mark[] = ['empty', 'break', 'dot'];

/** One applied mark mutation (the undo/redo and event-log currency). */
export interface MarkChange {
  readonly index: number;
  readonly from: Mark;
  readonly to: Mark;
}

/**
 * Injected wall clock, epoch milliseconds. The only time source game-core
 * ever reads (determinism law — never Date.now here).
 */
export interface Clock {
  now(): number;
}

/** Replay event: [elapsed ms, row-major cell index, mark code]. */
export type ReplayEvent = readonly [number, number, MarkCode];

/**
 * Injected key/value persistence (localStorage-shaped, but game-core never
 * imports the DOM — apps/web adapts window.localStorage to this).
 */
export interface KeyValueStorage {
  get(key: string): string | null;
  set(key: string, value: string): void;
  remove(key: string): void;
}

/**
 * Injected byte compressor for the replay log (gzip in apps/web via
 * CompressionStream; identity in tests). Async-friendly by design.
 */
export interface Compressor {
  compress(data: Uint8Array): Uint8Array | Promise<Uint8Array>;
}

/** Injected SHA-256, hex digest (WebCrypto in apps/web; node:crypto in tests). */
export interface Hasher {
  sha256Hex(data: Uint8Array): string | Promise<string>;
}

export type SolveMode = 'daily' | 'pack' | 'endless';

/** Hint counters, exactly the openapi.yaml HintCounts shape (RATING.md §3). */
export interface HintCounts {
  readonly s1: number;
  readonly s2: number;
  readonly s3: number;
}

/**
 * The openapi.yaml Board schema (burnfront.puzzle/1 board object). NOTE the
 * wire shape differs from the engine's BoardSpec: positions serialize as
 * [r, c] ARRAYS (vectors/README.md), so spark is a pair, not an object.
 */
export interface WireBoard {
  readonly rows: number;
  readonly cols: number;
  readonly spark: readonly [number, number];
  readonly breaks: number;
  readonly clues: readonly { readonly r: number; readonly c: number; readonly m: number }[];
}

/** Engine BoardSpec → wire Board (clues in canonical row-major order). */
export function toWireBoard(board: BoardSpec): WireBoard {
  return {
    rows: board.rows,
    cols: board.cols,
    spark: [board.spark.r, board.spark.c],
    breaks: board.breaks,
    clues: [...board.clues]
      .sort((a, b) => a.r - b.r || a.c - b.c)
      .map((clue) => ({ r: clue.r, c: clue.c, m: clue.m })),
  };
}

/**
 * The POST /solves request body — exactly the SolveSubmission schema of
 * contracts/openapi.yaml (validated against it in solve-record.test.ts).
 */
export interface SolveSubmission {
  readonly mode: SolveMode;
  readonly puzzle_id?: string;
  readonly endless_spec?: WireBoard;
  readonly shaded: string;
  readonly client_ms: number;
  readonly started_at: string;
  readonly hints: HintCounts;
  readonly undo_count: number;
  readonly replay?: string;
  readonly replay_sha256?: string;
  readonly deduction_steps?: number;
}
