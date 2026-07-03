/**
 * Property test (brief acceptance): random operation sequences against
 * PlaySession, with a mirror model of snapshot states, asserting:
 *   - undo(do(s)) == s and redo(undo(s')) == s' (marks-level equality);
 *   - undo/redo return false (and change nothing) when their stack is empty;
 *   - spark/clue cells never carry a mark;
 *   - breaksPlaced always equals the number of 'break' marks;
 *   - undoing everything returns to the empty board.
 */
import { describe, expect, it } from 'vitest';
import { marksToString } from './marks';
import { PlaySession } from './session';
import type { Mark } from './types';
import {
  demoBoard,
  demoClueIndices,
  demoSparkIndex,
  FakeClock,
  mulberry32,
} from './testing/fixtures';

const N = demoBoard.rows * demoBoard.cols;
const LOCKED = new Set([demoSparkIndex, ...demoClueIndices]);

function currentMarks(s: PlaySession): string {
  const marks: Mark[] = [];
  for (let i = 0; i < N; i++) marks.push(s.markAt(i));
  return marksToString(marks);
}

function assertInvariants(s: PlaySession): void {
  let breaks = 0;
  for (let i = 0; i < N; i++) {
    const mark = s.markAt(i);
    if (LOCKED.has(i)) expect(mark).toBe('empty');
    if (mark === 'break') breaks += 1;
  }
  expect(s.breaksPlaced).toBe(breaks);
}

describe('undo/redo property test', () => {
  it.each([11, 23, 47, 101, 977])('random op sequence holds all invariants (seed %i)', (seed) => {
    const rng = mulberry32(seed);
    const clock = new FakeClock();
    const s = new PlaySession({ board: demoBoard, mode: 'pack', puzzleId: 'pack-a-01' }, clock);
    s.start();
    const cell = (): number => Math.floor(rng() * N);
    // Mirror model: past = states BEFORE each live group, future for redo.
    const past: string[] = [];
    const future: string[] = [];
    let cur = currentMarks(s);
    const doOp = (apply: () => void): void => {
      const before = cur;
      apply();
      const after = currentMarks(s);
      if (after !== before) {
        past.push(before);
        future.length = 0;
        cur = after;
      }
    };
    for (let op = 0; op < 400; op++) {
      clock.advance(Math.floor(rng() * 50));
      const roll = rng();
      if (roll < 0.3) {
        doOp(() => void s.tap(cell()));
      } else if (roll < 0.45) {
        doOp(() => void s.tapReverse(cell()));
      } else if (roll < 0.65) {
        doOp(() => {
          s.strokeBegin(cell(), rng() < 0.5);
          const len = Math.floor(rng() * 5);
          for (let k = 0; k < len; k++) s.strokeEnter(cell());
          s.strokeEnd();
        });
      } else if (roll < 0.85) {
        const expected = past.length > 0;
        const before = cur;
        expect(s.undo()).toBe(expected);
        if (expected) {
          future.push(before);
          cur = past.pop() ?? before;
        }
        expect(currentMarks(s)).toBe(cur);
      } else {
        const expected = future.length > 0;
        const before = cur;
        expect(s.redo()).toBe(expected);
        if (expected) {
          past.push(before);
          cur = future.pop() ?? before;
        }
        expect(currentMarks(s)).toBe(cur);
      }
      assertInvariants(s);
    }
    // Drain the undo stack: the board must return to empty.
    while (s.undo()) {
      /* drain */
    }
    expect(currentMarks(s)).toBe('0'.repeat(N));
    // Exhausted redo is idempotent: replay it fully twice, second is a no-op.
    while (s.redo()) {
      /* drain */
    }
    const replayed = currentMarks(s);
    expect(s.redo()).toBe(false);
    expect(currentMarks(s)).toBe(replayed);
    assertInvariants(s);
  });
});
