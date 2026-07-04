/**
 * Local daily state: streak transitions (continue / restart / archive no-op /
 * idempotent) and the direct-storage progress writes.
 */
import { describe, expect, it } from 'vitest';
import { defaultLocalState, loadLocalState, memoryStorage, saveLocalState } from '../state/localState';
import type { StreakState } from '../state/localState';
import {
  creditLocalStreak,
  markDailyContained,
  markDailyInProgress,
  readLocalStreak,
  writeLocalStreak,
} from './localDaily';

const TODAY = '2026-07-08';
const YESTERDAY = '2026-07-07';

function streak(overrides: Partial<StreakState> = {}): StreakState {
  return { current: 0, best: 0, lastDailyDate: null, ...overrides };
}

describe('creditLocalStreak', () => {
  it('starts a run from nothing', () => {
    expect(creditLocalStreak(streak(), TODAY, TODAY)).toEqual({
      current: 1,
      best: 1,
      lastDailyDate: TODAY,
    });
  });

  it('continues a run from yesterday', () => {
    const next = creditLocalStreak(
      streak({ current: 4, best: 6, lastDailyDate: YESTERDAY }),
      TODAY,
      TODAY,
    );
    expect(next).toEqual({ current: 5, best: 6, lastDailyDate: TODAY });
  });

  it('restarts after a gap (a guest browser has no freeze)', () => {
    const next = creditLocalStreak(
      streak({ current: 9, best: 9, lastDailyDate: '2026-07-05' }),
      TODAY,
      TODAY,
    );
    expect(next.current).toBe(1);
    expect(next.best).toBe(9);
  });

  it('does not credit an archive (past-date) solve', () => {
    const before = streak({ current: 3, best: 3, lastDailyDate: YESTERDAY });
    expect(creditLocalStreak(before, '2026-07-02', TODAY)).toBe(before);
  });

  it('is idempotent for today', () => {
    const already = streak({ current: 2, best: 2, lastDailyDate: TODAY });
    expect(creditLocalStreak(already, TODAY, TODAY)).toBe(already);
  });
});

describe('direct-storage progress', () => {
  it('marks in-progress then contained', () => {
    const storage = memoryStorage();
    saveLocalState(storage, { ...defaultLocalState(), firstShiftDone: true });

    markDailyInProgress(storage, TODAY, 41_000);
    expect(loadLocalState(storage).daily).toEqual({
      date: TODAY,
      status: 'in_progress',
      elapsedMs: 41_000,
    });

    markDailyContained(storage, TODAY, 161_000);
    expect(loadLocalState(storage).daily).toEqual({
      date: TODAY,
      status: 'contained',
      elapsedMs: 161_000,
      containedMs: 161_000,
    });
  });

  it('reads and writes the streak against the freshest persisted state', () => {
    const storage = memoryStorage();
    saveLocalState(storage, { ...defaultLocalState(), firstShiftDone: true });
    writeLocalStreak(storage, { current: 7, best: 7, lastDailyDate: TODAY });
    expect(readLocalStreak(storage)).toEqual({ current: 7, best: 7, lastDailyDate: TODAY });
  });
});
