import { describe, expect, it } from 'vitest';
import { bitsToShading, validate } from '@burnfront/engine';
import { revealSequence } from './replay';
import { demoBoard, demoSolutionBits, demoSparkIndex } from './testing/fixtures';

describe('revealSequence', () => {
  const solution = bitsToShading(demoSolutionBits);

  it('frame 0 is the spark; frames group cells by burn minute', () => {
    const { result, frames } = revealSequence(demoBoard, solution);
    expect(result.valid).toBe(true);
    expect(frames[0]).toEqual({ minute: 0, cells: [demoSparkIndex] });
    const times = validate(demoBoard, solution).times;
    for (const frame of frames) {
      for (const cell of frame.cells) {
        expect(times[cell]).toBe(frame.minute);
      }
    }
  });

  it('covers every burned cell exactly once and never a shaded cell', () => {
    const { frames } = revealSequence(demoBoard, solution);
    const seen = frames.flatMap((f) => [...f.cells]);
    expect(new Set(seen).size).toBe(seen.length);
    expect(seen.length).toBe(25 - demoBoard.breaks); // valid solve: all open cells burn
    for (const cell of seen) expect(solution[cell]).toBe(false);
  });

  it('frames come in ascending minutes with ascending row-major cells', () => {
    const { frames } = revealSequence(demoBoard, solution);
    for (let i = 1; i < frames.length; i++) {
      expect(frames[i]?.minute ?? 0).toBeGreaterThan(frames[i - 1]?.minute ?? 0);
    }
    for (const frame of frames) {
      const sorted = [...frame.cells].sort((a, b) => a - b);
      expect([...frame.cells]).toEqual(sorted);
    }
  });

  it('a shaded spark yields no frames (the fire never starts)', () => {
    const shading = solution.map((b, i) => (i === demoSparkIndex ? true : b));
    const { result, frames } = revealSequence(demoBoard, shading);
    expect(result.valid).toBe(false);
    expect(result.reason).toBe('spark_shaded');
    expect(frames).toEqual([]);
  });

  it('invalid shadings still drive a partial reveal (what the fire did reach)', () => {
    // Wall off the whole second row: cells above it are never reached.
    const bits = '0000011111000000000000000';
    const { result, frames } = revealSequence(demoBoard, bitsToShading(bits));
    expect(result.valid).toBe(false);
    const revealed = new Set(frames.flatMap((f) => [...f.cells]));
    expect(revealed.has(0)).toBe(false); // row 0 sealed off
    expect(revealed.has(demoSparkIndex)).toBe(true);
  });
});
