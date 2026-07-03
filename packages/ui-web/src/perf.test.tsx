/**
 * Input-to-paint budget (brief acceptance): a tap's mark must be painted
 * into the DOM in under 50ms. Marks apply synchronously inside the discrete
 * pointer event — React flushes before the handler returns — so measuring
 * around fireEvent captures the full input-to-paint path in happy-dom.
 * performance.now here is test-only (the determinism lint covers
 * engine/game-core sources, not tests).
 */
import { fireEvent } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { freeCellIndices, pointerTap, renderBoard } from './testing/helpers';

function median(values: number[]): number {
  const sorted = [...values].sort((a, b) => a - b);
  const mid = Math.floor(sorted.length / 2);
  const a = sorted[mid];
  if (a === undefined) throw new Error('no samples');
  return a;
}

describe('input-to-paint', () => {
  it('paints a tapped mark in under 50ms', () => {
    const { session, cells } = renderBoard();

    // Warm-up: first interactions pay module/JIT costs, not paint costs.
    const warm = cells[0];
    if (warm === undefined) throw new Error('missing cell');
    pointerTap(warm); // -> break
    pointerTap(warm); // -> dot
    pointerTap(warm); // -> empty

    const samples: number[] = [];
    for (const index of freeCellIndices(session)) {
      const cell = cells[index];
      if (cell === undefined) throw new Error('missing cell');
      const before = performance.now();
      fireEvent.pointerDown(cell, { button: 0 });
      const painted = cell.classList.contains('bf-cell--break');
      const after = performance.now();
      fireEvent.pointerUp(cell, { button: 0 });
      expect(painted).toBe(true);
      samples.push(after - before);
    }

    expect(samples.length).toBeGreaterThanOrEqual(15);
    expect(median(samples)).toBeLessThan(50);
  });
});
