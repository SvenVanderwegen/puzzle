import { describe, expect, it } from 'vitest';
import { generate, validate } from '@burnfront/engine';
import type { BoardSpec } from '@burnfront/engine';
import { CoachState } from './coach';
import type { CoachHost } from './coach';
import { MarksBoard } from './marks';
import { demoBoard, mulberry32 } from './testing/fixtures';

function hostFor(marks: MarksBoard): CoachHost {
  return {
    markAt: (index) => marks.markAt(index),
    applyMark: (index, mark) => {
      marks.set(index, mark);
    },
  };
}

/** WS-13's acceptance idea in miniature: hints alone must reach a solved board. */
function carryToSolve(board: BoardSpec, marks: MarksBoard, coach: CoachState): number {
  let hints = 0;
  for (;;) {
    const hint = coach.requestHint();
    if (hint === null) break;
    hints += 1;
    expect(hints).toBeLessThanOrEqual(3 * board.rows * board.cols);
  }
  return hints;
}

describe('CoachState escalation', () => {
  it('escalates nudge → argument → resolution on an untouched target', () => {
    const marks = new MarksBoard(demoBoard);
    const coach = new CoachState(demoBoard, hostFor(marks));
    const h1 = coach.requestHint();
    const h2 = coach.requestHint();
    const h3 = coach.requestHint();
    expect(h1?.stage).toBe(1);
    expect(h2?.stage).toBe(2);
    expect(h3?.stage).toBe(3);
    expect(h1?.cellIndex).toBe(h3?.cellIndex);
    expect(h1?.applied).toBe(false);
    expect(h3?.applied).toBe(true);
    if (h3 !== null) expect(marks.markAt(h3.cellIndex)).toBe(h3.targetMark);
    expect(coach.hintCounts()).toEqual({ s1: 1, s2: 1, s3: 1 });
  });

  it('hands the certified step to the UI (reason, clue, minute)', () => {
    const marks = new MarksBoard(demoBoard);
    const coach = new CoachState(demoBoard, hostFor(marks));
    const hint = coach.requestHint();
    expect(hint?.step.state).toBe('break');
    expect(hint?.step.reason.kind).toBe('clue_reached_too_fast');
    expect(hint?.step.reason.clue).toEqual({ r: 2, c: 2 });
    expect(hint?.step.reason.minute).toBe(5);
  });

  it('resets to stage 1 when the player makes progress', () => {
    const marks = new MarksBoard(demoBoard);
    const coach = new CoachState(demoBoard, hostFor(marks));
    const first = coach.requestHint();
    expect(first?.stage).toBe(1);
    if (first === null) throw new Error('expected a hint');
    marks.set(first.cellIndex, 'break'); // player follows the nudge
    const second = coach.requestHint();
    expect(second?.stage).toBe(1);
    expect(second?.cellIndex).not.toBe(first.cellIndex);
    expect(coach.hintCounts()).toEqual({ s1: 2, s2: 0, s3: 0 });
  });

  it('targets a wrong break before anything else and resolves it with a dot', () => {
    const marks = new MarksBoard(demoBoard);
    const coach = new CoachState(demoBoard, hostFor(marks));
    marks.set(0, 'break'); // (0,0) is open in the unique solution
    const hint = coach.requestHint();
    expect(hint?.cellIndex).toBe(0);
    expect(hint?.step.state).toBe('open');
    expect(hint?.targetMark).toBe('dot');
    coach.requestHint();
    const resolution = coach.requestHint();
    expect(resolution?.stage).toBe(3);
    expect(marks.markAt(0)).toBe('dot');
    expect(marks.breaksPlaced).toBe(0);
  });

  it('targets a dot on a certified break cell as a contradiction', () => {
    const marks = new MarksBoard(demoBoard);
    const coach = new CoachState(demoBoard, hostFor(marks));
    const first = coach.requestHint();
    if (first === null) throw new Error('expected a hint');
    marks.set(first.cellIndex, 'dot'); // player contradicts the certificate
    const next = coach.requestHint();
    expect(next?.cellIndex).toBe(first.cellIndex);
    expect(next?.targetMark).toBe('break');
  });

  it('carries a no-input player to a solved board (demo board)', () => {
    const marks = new MarksBoard(demoBoard);
    const coach = new CoachState(demoBoard, hostFor(marks));
    const hints = carryToSolve(demoBoard, marks, coach);
    expect(hints).toBe(3 * demoBoard.breaks); // 3 stages per missing break
    expect(marks.breaksPlaced).toBe(demoBoard.breaks);
    expect(marks.completion()?.valid).toBe(true);
    const counts = coach.hintCounts();
    expect(counts).toEqual({ s1: 4, s2: 4, s3: 4 });
    expect(coach.unrated).toBe(true);
  });

  it('carries a no-input player to a solved board (generated board)', () => {
    const puzzle = generate({ rows: 5, cols: 5, breaks: 4 }, mulberry32(7));
    const marks = new MarksBoard(puzzle.board);
    const coach = new CoachState(puzzle.board, hostFor(marks));
    carryToSolve(puzzle.board, marks, coach);
    expect(validate(puzzle.board, marks.shading()).valid).toBe(true);
  });

  it('returns null (and counts nothing) on a non-deducible board', () => {
    const board: BoardSpec = { rows: 2, cols: 2, spark: { r: 0, c: 0 }, breaks: 1, clues: [] };
    const marks = new MarksBoard(board);
    const coach = new CoachState(board, hostFor(marks));
    expect(coach.requestHint()).toBeNull();
    expect(coach.hintCounts()).toEqual({ s1: 0, s2: 0, s3: 0 });
  });

  it('returns null once all breaks are correctly placed', () => {
    const marks = new MarksBoard(demoBoard);
    const coach = new CoachState(demoBoard, hostFor(marks));
    carryToSolve(demoBoard, marks, coach);
    expect(coach.requestHint()).toBeNull();
  });
});

describe('CoachState rating semantics (RATING.md §3)', () => {
  it('projects 1.0 for a clean solve', () => {
    const coach = new CoachState(demoBoard, hostFor(new MarksBoard(demoBoard)));
    expect(coach.projectedScore()).toBe(1.0);
    expect(coach.unrated).toBe(false);
  });

  it('counts only the first s1: two nudges still project 0.85', () => {
    const marks = new MarksBoard(demoBoard);
    const coach = new CoachState(demoBoard, hostFor(marks));
    const first = coach.requestHint();
    if (first === null) throw new Error('expected a hint');
    marks.set(first.cellIndex, 'break');
    coach.requestHint(); // second s1 on the next target
    expect(coach.hintCounts()).toEqual({ s1: 2, s2: 0, s3: 0 });
    expect(coach.projectedScore()).toBeCloseTo(0.85, 10);
  });

  it('projects 0.55 for 1×s1 + 2×s2 (the RATING.md F2 scenario)', () => {
    const coach = new CoachState(demoBoard, hostFor(new MarksBoard(demoBoard)), {
      s1: 1,
      s2: 2,
      s3: 0,
    });
    expect(coach.projectedScore()).toBeCloseTo(0.55, 10);
  });

  it('floors the outcome at 0.5', () => {
    const coach = new CoachState(demoBoard, hostFor(new MarksBoard(demoBoard)), {
      s1: 3,
      s2: 9,
      s3: 0,
    });
    expect(coach.projectedScore()).toBe(0.5);
  });

  it('any stage-3 hint makes the solve unrated', () => {
    const coach = new CoachState(demoBoard, hostFor(new MarksBoard(demoBoard)), {
      s1: 0,
      s2: 0,
      s3: 1,
    });
    expect(coach.unrated).toBe(true);
    expect(coach.projectedScore()).toBeNull();
  });

  it('restores persisted counters', () => {
    const coach = new CoachState(demoBoard, hostFor(new MarksBoard(demoBoard)), {
      s1: 1,
      s2: 1,
      s3: 0,
    });
    expect(coach.hintCounts()).toEqual({ s1: 1, s2: 1, s3: 0 });
    expect(coach.projectedScore()).toBeCloseTo(0.7, 10);
  });
});
