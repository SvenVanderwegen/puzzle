/**
 * Marks state: the player's per-cell annotations on one board.
 *
 * Tap cycle (forward): empty → break → dot → empty. Reverse runs the cycle
 * backwards. Spark and clue cells are immutable — every mutation on them is a
 * guarded no-op. Completion: once placed breaks == board.breaks, the engine's
 * validate() verdict is exposed (null while the count is short or over).
 */
import { validate } from '@burnfront/engine';
import type { BoardSpec, BurnResult, Shading } from '@burnfront/engine';
import type { Mark, MarkChange } from './types';
import { MARK_FROM_CODE } from './types';

const FORWARD: Readonly<Record<Mark, Mark>> = { empty: 'break', break: 'dot', dot: 'empty' };
const REVERSE: Readonly<Record<Mark, Mark>> = { empty: 'dot', dot: 'break', break: 'empty' };

/** Serialize marks for snapshots: one char per cell, '0' empty '1' break '2' dot. */
export function marksToString(marks: readonly Mark[]): string {
  let out = '';
  for (const m of marks) out += m === 'empty' ? '0' : m === 'break' ? '1' : '2';
  return out;
}

/** Inverse of marksToString. Throws on malformed input. */
export function marksFromString(s: string): Mark[] {
  const marks: Mark[] = [];
  for (const ch of s) {
    const mark = MARK_FROM_CODE[Number(ch)];
    if (mark === undefined || (ch !== '0' && ch !== '1' && ch !== '2')) {
      throw new Error(`marksFromString: invalid character ${JSON.stringify(ch)}`);
    }
    marks.push(mark);
  }
  return marks;
}

export class MarksBoard {
  readonly board: BoardSpec;
  readonly size: number;
  private readonly locked: boolean[];
  private readonly marks: Mark[];
  private breaks = 0;

  constructor(board: BoardSpec, initialMarks?: readonly Mark[]) {
    this.board = board;
    this.size = board.rows * board.cols;
    this.locked = new Array<boolean>(this.size).fill(false);
    this.locked[board.spark.r * board.cols + board.spark.c] = true;
    for (const clue of board.clues) {
      this.locked[clue.r * board.cols + clue.c] = true;
    }
    this.marks = new Array<Mark>(this.size).fill('empty');
    if (initialMarks !== undefined) {
      if (initialMarks.length !== this.size) {
        throw new Error('MarksBoard: initial marks length does not match the board');
      }
      for (let i = 0; i < this.size; i++) {
        const m = initialMarks[i];
        if (m !== undefined && m !== 'empty' && !this.isLocked(i)) {
          this.marks[i] = m;
          if (m === 'break') this.breaks += 1;
        }
      }
    }
  }

  isLocked(index: number): boolean {
    return this.locked[index] === true;
  }

  markAt(index: number): Mark {
    const m = this.marks[index];
    if (m === undefined) throw new Error(`MarksBoard: index ${String(index)} out of range`);
    return m;
  }

  get breaksPlaced(): number {
    return this.breaks;
  }

  /**
   * Set one cell to a mark. Returns the applied change, or null when guarded
   * (locked cell, out of range) or already in that state.
   */
  set(index: number, mark: Mark): MarkChange | null {
    if (index < 0 || index >= this.size || this.isLocked(index)) return null;
    const from = this.markAt(index);
    if (from === mark) return null;
    this.marks[index] = mark;
    if (from === 'break') this.breaks -= 1;
    if (mark === 'break') this.breaks += 1;
    return { index, from, to: mark };
  }

  /** The mark a forward tap on this cell would produce (guards ignored). */
  nextForward(index: number): Mark {
    return FORWARD[this.markAt(index)];
  }

  /** The mark a reverse tap on this cell would produce (guards ignored). */
  nextReverse(index: number): Mark {
    return REVERSE[this.markAt(index)];
  }

  cycleForward(index: number): MarkChange | null {
    if (index < 0 || index >= this.size || this.isLocked(index)) return null;
    return this.set(index, this.nextForward(index));
  }

  cycleReverse(index: number): MarkChange | null {
    if (index < 0 || index >= this.size || this.isLocked(index)) return null;
    return this.set(index, this.nextReverse(index));
  }

  /** Current shading: true where the player placed a break. */
  shading(): Shading {
    return this.marks.map((m) => m === 'break');
  }

  snapshotMarks(): readonly Mark[] {
    return [...this.marks];
  }

  /**
   * Completion detection: null while placed breaks != board.breaks; otherwise
   * the engine's full verdict (valid or not — the UI renders either).
   */
  completion(): BurnResult | null {
    if (this.breaks !== this.board.breaks) return null;
    return validate(this.board, this.shading());
  }
}
