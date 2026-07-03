import { describe, expect, it } from 'vitest';
import {
  clearSnapshot,
  isSessionSnapshot,
  loadSnapshot,
  MemoryStorage,
  saveSnapshot,
} from './persistence';
import { PlaySession } from './session';
import { demoBoard, demoBreakIndices, FakeClock } from './testing/fixtures';

describe('MemoryStorage', () => {
  it('gets, sets and removes', () => {
    const s = new MemoryStorage();
    expect(s.get('k')).toBeNull();
    s.set('k', 'v');
    expect(s.get('k')).toBe('v');
    s.set('k', 'v2');
    expect(s.get('k')).toBe('v2');
    s.remove('k');
    expect(s.get('k')).toBeNull();
  });
});

describe('session snapshot round-trip (acceptance)', () => {
  it('restores marks, timer, hints, undo count and event log', () => {
    const clock = new FakeClock(1_751_500_000_000);
    const storage = new MemoryStorage();
    const s = new PlaySession({ board: demoBoard, mode: 'daily', puzzleId: 'inc-0917' }, clock);
    s.start();
    clock.advance(42_000);
    s.tap(0);
    s.tap(demoBreakIndices[0] ?? 8);
    s.tapReverse(2);
    s.undo();
    s.requestHint(); // one s1
    saveSnapshot(storage, 'burnfront:session:daily', s.snapshot());

    const loaded = loadSnapshot(storage, 'burnfront:session:daily');
    expect(loaded).not.toBeNull();
    if (loaded === null) throw new Error('unreachable');
    const clock2 = new FakeClock(1_751_500_100_000);
    const restored = PlaySession.restore(loaded, clock2);

    expect(restored.mode).toBe('daily');
    expect(restored.puzzleId).toBe('inc-0917');
    for (let i = 0; i < 25; i++) expect(restored.markAt(i)).toBe(s.markAt(i));
    expect(restored.breaksPlaced).toBe(s.breaksPlaced);
    expect(restored.elapsedMs()).toBe(42_000);
    expect(restored.timerState).toBe('paused'); // restored sessions resume explicitly
    expect(restored.hintCounts()).toEqual({ s1: 1, s2: 0, s3: 0 });
    expect(restored.undoCount).toBe(1);
    expect(restored.replayEvents()).toEqual(s.replayEvents());
    expect(restored.startedAt).toBe(1_751_500_000_000);

    // The restored session keeps playing and keeps logging.
    restored.resume();
    clock2.advance(1000);
    expect(restored.elapsedMs()).toBe(43_000);
    restored.tap(3);
    expect(restored.replayEvents().length).toBe(s.replayEvents().length + 1);
  });

  it('a snapshot taken mid-stroke closes the stroke first', () => {
    const s = new PlaySession({ board: demoBoard, mode: 'pack', puzzleId: 'p1' }, new FakeClock());
    s.start();
    s.strokeBegin(0);
    s.strokeEnter(1);
    const snap = s.snapshot();
    expect(snap.marks[0]).toBe('1');
    expect(snap.marks[1]).toBe('1');
    expect(s.canUndo).toBe(true); // the stroke became one history group
  });

  it('clearSnapshot removes the stored session', () => {
    const storage = new MemoryStorage();
    const s = new PlaySession({ board: demoBoard, mode: 'pack', puzzleId: 'p1' }, new FakeClock());
    saveSnapshot(storage, 'k', s.snapshot());
    expect(loadSnapshot(storage, 'k')).not.toBeNull();
    clearSnapshot(storage, 'k');
    expect(loadSnapshot(storage, 'k')).toBeNull();
  });
});

describe('loadSnapshot robustness', () => {
  it('returns null for a missing key', () => {
    expect(loadSnapshot(new MemoryStorage(), 'nope')).toBeNull();
  });

  it('returns null for corrupt JSON', () => {
    const storage = new MemoryStorage();
    storage.set('k', '{not json');
    expect(loadSnapshot(storage, 'k')).toBeNull();
  });

  it('returns null for structurally foreign data', () => {
    const storage = new MemoryStorage();
    storage.set('k', JSON.stringify({ hello: 'world' }));
    expect(loadSnapshot(storage, 'k')).toBeNull();
  });
});

describe('isSessionSnapshot', () => {
  const valid = (): unknown => {
    const s = new PlaySession(
      { board: demoBoard, mode: 'endless', deductionSteps: 19 },
      new FakeClock(),
    );
    return JSON.parse(JSON.stringify(s.snapshot()));
  };

  it('accepts a real snapshot', () => {
    expect(isSessionSnapshot(valid())).toBe(true);
  });

  it.each([
    ['non-object', 42],
    ['wrong version', { ...(valid() as object), version: 2 }],
    ['bad mode', { ...(valid() as object), mode: 'zen' }],
    ['bad puzzleId', { ...(valid() as object), puzzleId: 7 }],
    ['bad deductionSteps', { ...(valid() as object), deductionSteps: 'many' }],
    ['bad board', { ...(valid() as object), board: { rows: 5 } }],
    ['bad board clues', { ...(valid() as object), board: { ...demoBoard, clues: [{ r: 0 }] } }],
    ['bad marks chars', { ...(valid() as object), marks: '3'.repeat(25) }],
    ['marks/board size mismatch', { ...(valid() as object), marks: '000' }],
    ['bad elapsed', { ...(valid() as object), elapsedMs: 'soon' }],
    ['bad startedAt', { ...(valid() as object), startedAtMs: 'now' }],
    ['bad hints', { ...(valid() as object), hints: { s1: 0, s2: 0 } }],
    ['bad undoCount', { ...(valid() as object), undoCount: null }],
    ['bad events', { ...(valid() as object), events: [[1, 2]] }],
    ['bad event mark code', { ...(valid() as object), events: [[1, 2, 9]] }],
  ])('rejects %s', (_label, value) => {
    expect(isSessionSnapshot(value)).toBe(false);
  });

  it('accepts a null startedAtMs (session saved before start)', () => {
    const snapshot = { ...(valid() as object), startedAtMs: null };
    expect(isSessionSnapshot(snapshot)).toBe(true);
  });
});
