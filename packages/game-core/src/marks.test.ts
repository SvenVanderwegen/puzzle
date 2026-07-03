import { describe, expect, it } from 'vitest';
import { MarksBoard, marksFromString, marksToString } from './marks';
import type { Mark } from './types';
import { demoBoard, demoBreakIndices, demoClueIndices, demoSparkIndex } from './testing/fixtures';

describe('MarksBoard tap cycles', () => {
  it('cycles forward empty → break → dot → empty', () => {
    const b = new MarksBoard(demoBoard);
    expect(b.cycleForward(0)).toEqual({ index: 0, from: 'empty', to: 'break' });
    expect(b.cycleForward(0)).toEqual({ index: 0, from: 'break', to: 'dot' });
    expect(b.cycleForward(0)).toEqual({ index: 0, from: 'dot', to: 'empty' });
    expect(b.markAt(0)).toBe('empty');
  });

  it('cycles reverse empty → dot → break → empty', () => {
    const b = new MarksBoard(demoBoard);
    expect(b.cycleReverse(0)?.to).toBe('dot');
    expect(b.cycleReverse(0)?.to).toBe('break');
    expect(b.cycleReverse(0)?.to).toBe('empty');
  });

  it('exposes the would-be next marks', () => {
    const b = new MarksBoard(demoBoard);
    expect(b.nextForward(0)).toBe('break');
    expect(b.nextReverse(0)).toBe('dot');
  });
});

describe('MarksBoard guards', () => {
  it('never marks the spark or clue cells', () => {
    const b = new MarksBoard(demoBoard);
    for (const locked of [demoSparkIndex, ...demoClueIndices]) {
      expect(b.isLocked(locked)).toBe(true);
      expect(b.set(locked, 'break')).toBeNull();
      expect(b.cycleForward(locked)).toBeNull();
      expect(b.cycleReverse(locked)).toBeNull();
      expect(b.markAt(locked)).toBe('empty');
    }
  });

  it('rejects out-of-range indices', () => {
    const b = new MarksBoard(demoBoard);
    expect(b.set(-1, 'break')).toBeNull();
    expect(b.set(25, 'break')).toBeNull();
    expect(() => b.markAt(25)).toThrow(/out of range/);
  });

  it('returns null for a no-op set', () => {
    const b = new MarksBoard(demoBoard);
    expect(b.set(0, 'empty')).toBeNull();
    b.set(0, 'dot');
    expect(b.set(0, 'dot')).toBeNull();
  });
});

describe('MarksBoard break counting and completion', () => {
  it('tracks breaksPlaced through set/unset', () => {
    const b = new MarksBoard(demoBoard);
    b.set(0, 'break');
    b.set(1, 'break');
    expect(b.breaksPlaced).toBe(2);
    b.set(0, 'dot');
    expect(b.breaksPlaced).toBe(1);
    b.set(1, 'empty');
    expect(b.breaksPlaced).toBe(0);
  });

  it('completion is null until the break count matches', () => {
    const b = new MarksBoard(demoBoard);
    const [first, ...rest] = demoBreakIndices;
    expect(first).toBeDefined();
    for (const i of rest) b.set(i, 'break');
    expect(b.completion()).toBeNull();
    if (first !== undefined) b.set(first, 'break');
    const result = b.completion();
    expect(result?.valid).toBe(true);
    expect(result?.reason).toBe('ok');
  });

  it('exposes the engine verdict for a wrong full shading', () => {
    const b = new MarksBoard(demoBoard);
    for (const i of [0, 1, 2, 3]) b.set(i, 'break');
    const result = b.completion();
    expect(result).not.toBeNull();
    expect(result?.valid).toBe(false);
  });

  it('shading marks exactly the break cells', () => {
    const b = new MarksBoard(demoBoard);
    for (const i of demoBreakIndices) b.set(i, 'break');
    b.set(0, 'dot'); // dots are not breaks
    const shading = b.shading();
    expect(shading.filter(Boolean).length).toBe(4);
    expect(demoBreakIndices.every((i) => shading[i] === true)).toBe(true);
  });
});

describe('marks serialization', () => {
  it('round-trips marks strings', () => {
    const marks: Mark[] = ['empty', 'break', 'dot', 'empty'];
    expect(marksToString(marks)).toBe('0120');
    expect(marksFromString('0120')).toEqual(marks);
  });

  it('rejects malformed strings', () => {
    expect(() => marksFromString('01x')).toThrow(/invalid character/);
    expect(() => marksFromString('013')).toThrow(/invalid character/);
  });

  it('restores initial marks, ignoring locked positions', () => {
    const marks = new Array<Mark>(25).fill('empty');
    marks[0] = 'break';
    marks[demoSparkIndex] = 'break'; // corrupt snapshot: must be dropped
    const b = new MarksBoard(demoBoard, marks);
    expect(b.markAt(0)).toBe('break');
    expect(b.markAt(demoSparkIndex)).toBe('empty');
    expect(b.breaksPlaced).toBe(1);
  });

  it('rejects initial marks of the wrong length', () => {
    expect(() => new MarksBoard(demoBoard, ['empty'])).toThrow(/length/);
  });
});
