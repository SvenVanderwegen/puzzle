/**
 * Test-only fixtures and fakes (excluded from coverage; never shipped).
 * Environment-free on purpose — the no-DOM sweep covers this file too.
 */
import type { BoardSpec, Rng } from '@burnfront/engine';
import type { Clock, Compressor } from '../types';

/** The README demo board (reference/firebreak.py demo_puzzle). */
export const demoBoard: BoardSpec = {
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

/** Its unique solution: breaks at (1,3), (2,1), (3,2), (4,2). */
export const demoSolutionBits = '0000000010010000010000100';
export const demoBreakIndices = [8, 11, 17, 22];
export const demoSparkIndex = 15;
export const demoClueIndices = [9, 12, 16, 21, 23];

/** Deterministic, manually advanced clock. */
export class FakeClock implements Clock {
  private t: number;

  constructor(startMs = 0) {
    this.t = startMs;
  }

  now(): number {
    return this.t;
  }

  advance(ms: number): void {
    this.t += ms;
  }

  set(ms: number): void {
    this.t = ms;
  }
}

/** Seeded PRNG (same construction as the engine's test helper). */
export function mulberry32(seed: number): Rng {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/** Identity "compression" — the injected-interface stand-in for tests. */
export const identityCompressor: Compressor = {
  compress: (data) => data,
};
