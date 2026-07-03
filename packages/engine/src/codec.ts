/**
 * Compact shareable puzzle code.
 *
 * Format (v1): `fb1:<rows>x<cols>:<breaks>:<sparkIndex>:<clues>` where
 * `<clues>` is `-` (none) or comma-separated `<cellIndex>.<minute>` pairs
 * with strictly increasing cell indices (canonical row-major order). All
 * numbers are decimal without leading zeros, so encoding is bijective:
 * every code that decodes also re-encodes to itself byte-identically.
 *
 * Law: decodePuzzle(encodePuzzle(b)) deep-equals b (clues in canonical
 * order). decodePuzzle throws on malformed input.
 */
import { assertBoardSpec, sortClues } from './board';
import { cellIndex, toCell } from './grid';
import type { BoardSpec, Clue } from './types';

const NUM = String.raw`(?:0|[1-9]\d*)`;
const CODE_RE = new RegExp(
  `^fb1:(${NUM})x(${NUM}):(${NUM}):(${NUM}):(-|${NUM}\\.${NUM}(?:,${NUM}\\.${NUM})*)$`,
);

export function encodePuzzle(board: BoardSpec): string {
  assertBoardSpec(board);
  const cluePart =
    board.clues.length === 0
      ? '-'
      : sortClues(board.clues)
          .map((clue) => `${String(cellIndex(clue, board.cols))}.${String(clue.m)}`)
          .join(',');
  const sparkIdx = cellIndex(board.spark, board.cols);
  return `fb1:${String(board.rows)}x${String(board.cols)}:${String(board.breaks)}:${String(
    sparkIdx,
  )}:${cluePart}`;
}

export function decodePuzzle(code: string): BoardSpec {
  const match = CODE_RE.exec(code);
  if (match === null) {
    throw new Error('decodePuzzle: malformed code');
  }
  const rows = Number(match[1]);
  const cols = Number(match[2]);
  const breaks = Number(match[3]);
  const sparkIdx = Number(match[4]);
  const cluePart = match[5] ?? '-';
  const n = rows * cols;
  if (rows < 1 || cols < 1) {
    throw new Error('decodePuzzle: empty board');
  }
  if (sparkIdx >= n) {
    throw new Error('decodePuzzle: spark index out of bounds');
  }
  const clues: Clue[] = [];
  if (cluePart !== '-') {
    let prevIdx = -1;
    for (const pair of cluePart.split(',')) {
      const dot = pair.indexOf('.');
      const idx = Number(pair.slice(0, dot));
      const minute = Number(pair.slice(dot + 1));
      if (idx <= prevIdx) {
        throw new Error('decodePuzzle: clue indices must be strictly increasing');
      }
      prevIdx = idx;
      if (idx >= n) {
        throw new Error('decodePuzzle: clue index out of bounds');
      }
      clues.push({ ...toCell(idx, cols), m: minute });
    }
  }
  const board: BoardSpec = {
    rows,
    cols,
    spark: toCell(sparkIdx, cols),
    breaks,
    clues,
  };
  // Shared structural validation (dimension caps, break range, clue-on-spark).
  try {
    assertBoardSpec(board);
  } catch (err) {
    throw new Error(`decodePuzzle: ${err instanceof Error ? err.message : 'invalid board'}`);
  }
  return board;
}
