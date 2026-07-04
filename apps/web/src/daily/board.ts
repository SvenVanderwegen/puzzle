/**
 * Wire board → engine BoardSpec for the Daily surface.
 *
 * The daily board arrives as the `board` object of a burnfront.puzzle/1
 * document (CDN JSON) or as the `puzzle` field of GET /daily/{date} under the
 * origin-fallback flag (contracts/openapi.yaml Board schema). Both serialize
 * `spark` as a `[r, c]` pair (vectors/README.md) — the engine's BoardSpec uses
 * `spark: {r, c}`, so this is the single crossing point. Parsing is defensive:
 * a malformed board returns null rather than throwing into React render.
 */
import type { BoardSpec, Clue } from '@burnfront/engine';

/** The `board` object as it travels on the wire (spark is a [r, c] pair). */
export interface WireBoard {
  readonly rows: number;
  readonly cols: number;
  readonly spark: readonly [number, number];
  readonly breaks: number;
  readonly clues: readonly { readonly r: number; readonly c: number; readonly m: number }[];
}

function isInt(value: unknown): value is number {
  return typeof value === 'number' && Number.isInteger(value);
}

/** Structural guard for one wire clue. */
function isWireClue(value: unknown): value is { r: number; c: number; m: number } {
  if (typeof value !== 'object' || value === null) return false;
  const clue = value as Record<string, unknown>;
  return isInt(clue.r) && isInt(clue.c) && isInt(clue.m);
}

/** True when `value` is a well-formed wire board (defensive, content-bound). */
export function isWireBoard(value: unknown): value is WireBoard {
  if (typeof value !== 'object' || value === null) return false;
  const board = value as Record<string, unknown>;
  if (!isInt(board.rows) || !isInt(board.cols) || !isInt(board.breaks)) return false;
  if (board.rows < 3 || board.cols < 3 || board.breaks < 1) return false;
  const spark = board.spark;
  if (!Array.isArray(spark) || spark.length !== 2 || !isInt(spark[0]) || !isInt(spark[1])) {
    return false;
  }
  if (!Array.isArray(board.clues) || board.clues.length < 1) return false;
  return board.clues.every(isWireClue);
}

/**
 * Convert a validated wire board to the engine's BoardSpec (spark object;
 * clues left as the wire supplies them — game-core/engine treat clues as a
 * set). Returns null when the shape is not a valid board.
 */
export function wireBoardToSpec(value: unknown): BoardSpec | null {
  if (!isWireBoard(value)) return null;
  const clues: Clue[] = value.clues.map((clue) => ({ r: clue.r, c: clue.c, m: clue.m }));
  return {
    rows: value.rows,
    cols: value.cols,
    spark: { r: value.spark[0], c: value.spark[1] },
    breaks: value.breaks,
    clues,
  };
}
