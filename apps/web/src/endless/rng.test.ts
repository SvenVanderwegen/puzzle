/**
 * Seeded RNG: determinism per seed, [0,1) range, degenerate-seed escape,
 * and the crypto-backed sources' shapes.
 */
import { describe, expect, it } from 'vitest';
import { cryptoRng, cryptoSeed, SEED_WORDS, seededRng } from './rng';

describe('seededRng', () => {
  it('is deterministic for a given seed', () => {
    const a = seededRng([1, 2, 3, 4]);
    const b = seededRng([1, 2, 3, 4]);
    const streamA = Array.from({ length: 32 }, () => a());
    const streamB = Array.from({ length: 32 }, () => b());
    expect(streamA).toEqual(streamB);
  });

  it('differs across seeds', () => {
    const a = seededRng([1, 2, 3, 4]);
    const b = seededRng([5, 6, 7, 8]);
    expect(Array.from({ length: 8 }, () => a())).not.toEqual(Array.from({ length: 8 }, () => b()));
  });

  it('stays in [0, 1) and escapes the all-zero seed', () => {
    const rng = seededRng([0, 0, 0, 0]);
    const values = Array.from({ length: 256 }, () => rng());
    for (const value of values) {
      expect(value).toBeGreaterThanOrEqual(0);
      expect(value).toBeLessThan(1);
    }
    // Not a constant stream.
    expect(new Set(values).size).toBeGreaterThan(200);
  });

  it('tolerates short seeds', () => {
    const rng = seededRng([7]);
    expect(rng()).toBeGreaterThanOrEqual(0);
  });
});

describe('crypto-backed sources', () => {
  it('cryptoSeed yields SEED_WORDS 32-bit words', () => {
    const seed = cryptoSeed();
    expect(seed).toHaveLength(SEED_WORDS);
    for (const word of seed) {
      expect(Number.isInteger(word)).toBe(true);
      expect(word).toBeGreaterThanOrEqual(0);
      expect(word).toBeLessThan(2 ** 32);
    }
  });

  it('cryptoRng yields values in [0, 1) across buffer refills', () => {
    const rng = cryptoRng();
    for (let i = 0; i < 200; i++) {
      const value = rng();
      expect(value).toBeGreaterThanOrEqual(0);
      expect(value).toBeLessThan(1);
    }
  });
});
