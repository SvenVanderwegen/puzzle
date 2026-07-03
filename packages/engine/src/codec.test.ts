/**
 * Puzzle-code codec law: decodePuzzle(encodePuzzle(b)) deep-equals b with
 * canonical clue order; decodePuzzle throws on malformed input (incl. fuzz).
 */
import { describe, expect, it } from 'vitest';
import { decodePuzzle, encodePuzzle, generate } from './index';
import type { BoardSpec } from './index';
import { mulberry32 } from './testing/mulberry32';

/** The README demo board (reference/firebreak.py demo_puzzle). */
const demoBoard: BoardSpec = {
  rows: 5,
  cols: 5,
  spark: { r: 3, c: 0 },
  breaks: 4,
  clues: [
    { r: 1, c: 4, m: 8 },
    { r: 2, c: 2, m: 5 },
    { r: 3, c: 1, m: 1 },
    { r: 4, c: 1, m: 2 },
    { r: 4, c: 3, m: 8 },
  ],
};

describe('encodePuzzle / decodePuzzle', () => {
  it('locks the v1 code format', () => {
    expect(encodePuzzle(demoBoard)).toBe('fb1:5x5:4:15:9.8,12.5,16.1,21.2,23.8');
  });

  it('round-trips the demo board', () => {
    expect(decodePuzzle(encodePuzzle(demoBoard))).toEqual(demoBoard);
  });

  it('round-trips generated boards', () => {
    for (const seed of [5, 6, 7]) {
      const { board } = generate({ rows: 5, cols: 5, breaks: 4 }, mulberry32(seed));
      expect(decodePuzzle(encodePuzzle(board))).toEqual(board);
    }
  });

  it('round-trips a clue-less board', () => {
    const board: BoardSpec = { rows: 1, cols: 2, spark: { r: 0, c: 0 }, breaks: 0, clues: [] };
    expect(encodePuzzle(board)).toBe('fb1:1x2:0:0:-');
    expect(decodePuzzle('fb1:1x2:0:0:-')).toEqual(board);
  });

  it('canonicalizes clue order on encode', () => {
    const shuffled: BoardSpec = { ...demoBoard, clues: [...demoBoard.clues].reverse() };
    expect(encodePuzzle(shuffled)).toBe(encodePuzzle(demoBoard));
    expect(decodePuzzle(encodePuzzle(shuffled))).toEqual(demoBoard);
  });

  it('encodePuzzle rejects malformed boards', () => {
    expect(() => encodePuzzle({ ...demoBoard, breaks: -1 })).toThrow(/breaks/);
    expect(() => encodePuzzle({ ...demoBoard, spark: { r: 9, c: 0 } })).toThrow(/spark/);
    expect(() =>
      encodePuzzle({ ...demoBoard, clues: [...demoBoard.clues, { r: 1, c: 4, m: 3 }] }),
    ).toThrow(/duplicate/);
  });

  it('decodePuzzle throws on malformed codes', () => {
    const bad = [
      '',
      'fb1',
      'fb2:5x5:4:15:-',
      'fb1:5x5:4:15',
      'fb1:5x5:4:15:',
      'fb1:5x5:4:15:-,',
      'fb1:05x5:4:15:-', // leading zero
      'fb1:5x5:4:15:9.8,', // trailing comma
      'fb1:5x5:4:15:9.', // missing minute
      ' fb1:5x5:4:15:-',
      'fb1:5x5:4:15:- ',
      'fb1:0x5:4:0:-', // empty board
      'fb1:65x5:4:0:-', // dimension cap
      'fb1:5x5:26:15:-', // breaks > cells
      'fb1:5x5:4:25:-', // spark out of bounds
      'fb1:5x5:4:15:25.1', // clue out of bounds
      'fb1:5x5:4:15:15.1', // clue on the spark
      'fb1:5x5:4:15:12.5,9.8', // not strictly increasing
      'fb1:5x5:4:15:9.8,9.9', // duplicate clue cell
    ];
    for (const code of bad) {
      expect(() => decodePuzzle(code), JSON.stringify(code)).toThrow();
    }
  });

  it('fuzz: mutated valid codes either throw or re-encode identically', () => {
    const rng = mulberry32(99);
    const alphabet = '0123456789.,:-xfb';
    const codes = [
      encodePuzzle(demoBoard),
      'fb1:1x2:0:0:-',
      encodePuzzle(generate({ rows: 4, cols: 4, breaks: 3 }, mulberry32(8)).board),
    ];
    let accepted = 0;
    let rejected = 0;
    for (const code of codes) {
      for (let pos = 0; pos < code.length; pos++) {
        for (let k = 0; k < 4; k++) {
          const ch = alphabet[Math.floor(rng() * alphabet.length)] ?? '0';
          if (ch === code[pos]) continue;
          const mutated = code.slice(0, pos) + ch + code.slice(pos + 1);
          let decoded: BoardSpec | null = null;
          try {
            decoded = decodePuzzle(mutated);
          } catch {
            rejected += 1;
          }
          if (decoded !== null) {
            accepted += 1;
            // Anything accepted must be a well-formed board in canonical form.
            expect(encodePuzzle(decoded), mutated).toBe(mutated);
          }
        }
      }
    }
    expect(rejected).toBeGreaterThan(0);
    expect(accepted + rejected).toBeGreaterThan(100);
  });
});
