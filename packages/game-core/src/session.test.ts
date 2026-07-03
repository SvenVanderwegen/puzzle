import { describe, expect, it } from 'vitest';
import { MarksBoard } from './marks';
import { PlaySession } from './session';
import { MARK_FROM_CODE } from './types';
import type { ReplayEvent } from './types';
import { demoBoard, demoBreakIndices, demoSparkIndex, FakeClock } from './testing/fixtures';

function dailySession(clock: FakeClock): PlaySession {
  return new PlaySession({ board: demoBoard, mode: 'daily', puzzleId: 'daily-2026-07-03' }, clock);
}

/** Re-apply a replay log onto a fresh board (the acceptance round-trip). */
function replayOnto(events: readonly ReplayEvent[]): MarksBoard {
  const board = new MarksBoard(demoBoard);
  for (const [, index, code] of events) {
    const mark = MARK_FROM_CODE[code];
    if (mark === undefined) throw new Error('bad mark code');
    board.set(index, mark);
  }
  return board;
}

describe('PlaySession gestures and event log', () => {
  it('logs tap cycles with elapsed timestamps', () => {
    const clock = new FakeClock(50_000);
    const s = dailySession(clock);
    s.start();
    clock.advance(1000);
    expect(s.tap(0)).toBe(true);
    clock.advance(500);
    expect(s.tap(0)).toBe(true);
    clock.advance(500);
    expect(s.tap(0)).toBe(true);
    expect(s.replayEvents()).toEqual([
      [1000, 0, 1],
      [1500, 0, 2],
      [2000, 0, 0],
    ]);
  });

  it('taps on locked cells change nothing and log nothing', () => {
    const s = dailySession(new FakeClock());
    s.start();
    expect(s.tap(demoSparkIndex)).toBe(false);
    expect(s.tapReverse(demoSparkIndex)).toBe(false);
    expect(s.replayEvents()).toEqual([]);
    expect(s.canUndo).toBe(false);
  });

  it('drag-paint applies the anchor cycle result across entered cells', () => {
    const s = dailySession(new FakeClock());
    s.start();
    s.strokeBegin(0);
    s.strokeEnter(1);
    s.strokeEnter(2);
    s.strokeEnd();
    expect([s.markAt(0), s.markAt(1), s.markAt(2)]).toEqual(['break', 'break', 'break']);
    expect(s.breaksPlaced).toBe(3);
  });

  it('reverse strokes paint the reverse-cycle mark (dot from empty)', () => {
    const s = dailySession(new FakeClock());
    s.start();
    s.strokeBegin(0, true);
    s.strokeEnter(1);
    s.strokeEnd();
    expect([s.markAt(0), s.markAt(1)]).toEqual(['dot', 'dot']);
  });

  it('strokes skip locked cells and cells already carrying the mark', () => {
    const s = dailySession(new FakeClock());
    s.start();
    s.tap(1); // already a break before the stroke
    s.strokeBegin(0);
    s.strokeEnter(demoSparkIndex);
    s.strokeEnter(1);
    s.strokeEnter(2);
    s.strokeEnd();
    expect(s.markAt(demoSparkIndex)).toBe('empty');
    expect(s.markAt(1)).toBe('break');
    // one event for the tap, two for the stroke (cell 1 was skipped)
    expect(s.replayEvents().length).toBe(3);
  });

  it('a stroke beginning on a locked cell is inert', () => {
    const s = dailySession(new FakeClock());
    s.start();
    s.strokeBegin(demoSparkIndex);
    s.strokeEnter(0);
    s.strokeEnd();
    expect(s.markAt(0)).toBe('empty');
    expect(s.canUndo).toBe(false);
  });

  it('strokeEnter without an open stroke is a no-op', () => {
    const s = dailySession(new FakeClock());
    s.start();
    s.strokeEnter(0);
    expect(s.markAt(0)).toBe('empty');
  });
});

describe('PlaySession undo/redo', () => {
  it('undoes a whole stroke as one entry and redoes it', () => {
    const s = dailySession(new FakeClock());
    s.start();
    s.strokeBegin(0);
    s.strokeEnter(1);
    s.strokeEnter(2);
    s.strokeEnd();
    s.tap(3);
    expect(s.undo()).toBe(true); // the tap
    expect(s.markAt(3)).toBe('empty');
    expect(s.undo()).toBe(true); // the whole stroke
    expect([s.markAt(0), s.markAt(1), s.markAt(2)]).toEqual(['empty', 'empty', 'empty']);
    expect(s.undo()).toBe(false);
    expect(s.redo()).toBe(true);
    expect([s.markAt(0), s.markAt(1), s.markAt(2)]).toEqual(['break', 'break', 'break']);
    expect(s.undoCount).toBe(2);
  });

  it('a new gesture clears the redo stack', () => {
    const s = dailySession(new FakeClock());
    s.start();
    s.tap(0);
    s.undo();
    s.tap(1);
    expect(s.canRedo).toBe(false);
    expect(s.redo()).toBe(false);
  });

  it('undo and redo are recorded in the replay log', () => {
    const s = dailySession(new FakeClock());
    s.start();
    s.tap(0);
    s.undo();
    s.redo();
    expect(s.replayEvents()).toEqual([
      [0, 0, 1],
      [0, 0, 0],
      [0, 0, 1],
    ]);
  });
});

describe('PlaySession completion and coach wiring', () => {
  it('exposes the engine verdict when the last break lands', () => {
    const s = dailySession(new FakeClock());
    s.start();
    for (const i of demoBreakIndices) s.tap(i);
    const result = s.completion();
    expect(result?.valid).toBe(true);
    expect(result?.reason).toBe('ok');
    expect(s.shading().filter(Boolean).length).toBe(4);
  });

  it('stage-3 coach marks are applied, logged, and undoable', () => {
    const s = dailySession(new FakeClock());
    s.start();
    const h1 = s.requestHint();
    s.requestHint();
    const h3 = s.requestHint();
    expect(h3?.applied).toBe(true);
    if (h1 === null || h3 === null) throw new Error('expected hints');
    expect(s.markAt(h3.cellIndex)).toBe('break');
    expect(s.replayEvents()).toEqual([[0, h3.cellIndex, 1]]);
    expect(s.undo()).toBe(true);
    expect(s.markAt(h3.cellIndex)).toBe('empty');
    expect(s.hintCounts()).toEqual({ s1: 1, s2: 1, s3: 1 });
    expect(s.unrated).toBe(true);
    expect(s.projectedScore()).toBeNull();
  });
});

describe('PlaySession timer wiring', () => {
  it('records started_at on the first start only', () => {
    const clock = new FakeClock(1_750_000_000_000);
    const s = dailySession(clock);
    expect(s.startedAt).toBeNull();
    s.start();
    expect(s.startedAt).toBe(1_750_000_000_000);
    clock.advance(9000);
    s.pause();
    s.resume();
    expect(s.startedAt).toBe(1_750_000_000_000);
  });

  it('auto-pauses through the document-hidden hook', () => {
    const clock = new FakeClock();
    const s = dailySession(clock);
    s.start();
    clock.advance(100);
    s.setHidden(true);
    expect(s.timerState).toBe('paused');
    clock.advance(10_000);
    s.setHidden(false);
    expect(s.timerState).toBe('running');
    expect(s.elapsedMs()).toBe(100);
  });
});

describe('PlaySession replay round-trip (acceptance)', () => {
  it('re-applying the event log reproduces the final board state', () => {
    const clock = new FakeClock();
    const s = dailySession(clock);
    s.start();
    // A messy solve: strokes, wrong marks, undos, redos, coach resolution.
    s.strokeBegin(0);
    s.strokeEnter(1);
    s.strokeEnd();
    clock.advance(1200);
    s.tap(demoBreakIndices[0] ?? 8);
    s.tapReverse(2);
    s.undo();
    s.undo();
    s.redo();
    s.requestHint();
    s.requestHint();
    s.requestHint(); // stage 3 applies a certified mark
    clock.advance(700);
    s.tap(demoBreakIndices[3] ?? 22);
    const replayed = replayOnto(s.replayEvents());
    for (let i = 0; i < 25; i++) {
      expect(replayed.markAt(i)).toBe(s.markAt(i));
    }
    expect(replayed.breaksPlaced).toBe(s.breaksPlaced);
  });

  it('event timestamps are monotonically non-decreasing', () => {
    const clock = new FakeClock();
    const s = dailySession(clock);
    s.start();
    s.tap(0);
    clock.advance(10);
    s.tap(1);
    clock.advance(10);
    s.undo();
    const times = s.replayEvents().map((e) => e[0]);
    expect([...times].sort((a, b) => a - b)).toEqual(times);
  });
});

describe('PlaySession solve-record source', () => {
  it('throws before start()', () => {
    const s = dailySession(new FakeClock());
    expect(() => s.solveRecordSource()).toThrow(/before start/);
  });

  it('collects mode, timing, hints, undo count and events', () => {
    const clock = new FakeClock(1_750_000_000_000);
    const s = dailySession(clock);
    s.start();
    clock.advance(60_000);
    for (const i of demoBreakIndices) s.tap(i);
    s.undo();
    s.redo();
    const src = s.solveRecordSource();
    expect(src.mode).toBe('daily');
    expect(src.puzzleId).toBe('daily-2026-07-03');
    expect(src.endlessSpec).toBeUndefined();
    expect(src.clientMs).toBe(60_000);
    expect(src.startedAtMs).toBe(1_750_000_000_000);
    expect(src.undoCount).toBe(1);
    expect(src.events.length).toBe(6);
    expect(src.shading.filter(Boolean).length).toBe(4);
  });

  it('endless sessions carry their board as the endless spec', () => {
    const s = new PlaySession(
      { board: demoBoard, mode: 'endless', deductionSteps: 19 },
      new FakeClock(),
    );
    s.start();
    const src = s.solveRecordSource();
    expect(src.endlessSpec).toBe(demoBoard);
    expect(src.deductionSteps).toBe(19);
    expect(src.puzzleId).toBeUndefined();
  });
});
