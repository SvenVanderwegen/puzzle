/**
 * Performance bounds (brief acceptance): 5x5 generation < 50ms, 7x7 < 8s in
 * Node. Timing assertions are deliberately loose (best of several runs) so
 * they gate real regressions, not scheduler noise.
 */
import { describe, expect, it } from 'vitest';
import { generate } from './index';
import { mulberry32 } from './testing/mulberry32';

describe('generation performance', () => {
  it('5x5 (4 breaks) generates in under 50ms', () => {
    // Warm up the JIT before timing.
    generate({ rows: 5, cols: 5, breaks: 4, minClues: 5 }, mulberry32(1000));
    let best = Number.POSITIVE_INFINITY;
    for (const seed of [1001, 1003, 1012, 1014, 1018]) {
      const t0 = performance.now();
      generate({ rows: 5, cols: 5, breaks: 4, minClues: 5 }, mulberry32(seed));
      best = Math.min(best, performance.now() - t0);
    }
    expect(best).toBeLessThan(50);
  });

  it('7x7 (12 breaks) generates in under 8s', () => {
    const t0 = performance.now();
    generate({ rows: 7, cols: 7, breaks: 12, minClues: 12 }, mulberry32(2001));
    expect(performance.now() - t0).toBeLessThan(8000);
  });
});
