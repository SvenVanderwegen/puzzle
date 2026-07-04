/**
 * Wire board → engine BoardSpec: the spark [r,c] pair becomes {r,c}, and
 * malformed content resolves to null (defensive, never throws into render).
 */
import { describe, expect, it } from 'vitest';
import { isWireBoard, wireBoardToSpec } from './board';

const good = {
  rows: 5,
  cols: 5,
  spark: [3, 0],
  breaks: 4,
  clues: [
    { r: 1, c: 4, m: 8 },
    { r: 2, c: 2, m: 5 },
  ],
};

describe('wireBoardToSpec', () => {
  it('converts a valid wire board, spark pair → object', () => {
    const spec = wireBoardToSpec(good);
    expect(spec).not.toBeNull();
    expect(spec?.spark).toEqual({ r: 3, c: 0 });
    expect(spec?.rows).toBe(5);
    expect(spec?.breaks).toBe(4);
    expect(spec?.clues).toEqual(good.clues);
  });

  it('rejects malformed shapes', () => {
    expect(wireBoardToSpec(null)).toBeNull();
    expect(wireBoardToSpec({ ...good, spark: [3] })).toBeNull();
    expect(wireBoardToSpec({ ...good, clues: [] })).toBeNull();
    expect(wireBoardToSpec({ ...good, rows: 2 })).toBeNull();
    expect(wireBoardToSpec({ ...good, breaks: 0 })).toBeNull();
    expect(wireBoardToSpec({ ...good, clues: [{ r: 1, c: 2 }] })).toBeNull();
  });
});

describe('isWireBoard', () => {
  it('guards the essentials', () => {
    expect(isWireBoard(good)).toBe(true);
    expect(isWireBoard({})).toBe(false);
    expect(isWireBoard({ ...good, spark: 'x' })).toBe(false);
  });
});
