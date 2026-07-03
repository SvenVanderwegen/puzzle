import { describe, expect, it } from 'vitest';
import {
  defaultLocalState,
  LOCAL_STATE_KEY,
  loadLocalState,
  memoryStorage,
  saveLocalState,
} from './localState';

describe('localState — anonymous-first store', () => {
  it('starts with First Shift pending, guest account, Glicko seed', () => {
    const state = defaultLocalState();
    expect(state.firstShiftDone).toBe(false);
    expect(state.account).toBeNull();
    expect(state.record.rating).toBe(1200);
    expect(state.academy.total).toBe(7);
    expect(state.endless.solvedByTier).toEqual({ lookout: 0, crew: 0, hotshot: 0 });
  });

  it('round-trips through storage', () => {
    const storage = memoryStorage();
    const state = {
      ...defaultLocalState(),
      firstShiftDone: true,
      daily: { date: '2026-07-03', status: 'in_progress' as const, elapsedMs: 161_000 },
      streak: { current: 3, best: 5, lastDailyDate: '2026-07-02' },
    };
    saveLocalState(storage, state);
    expect(loadLocalState(storage)).toEqual(state);
  });

  it('falls back to defaults for missing, corrupt or foreign payloads', () => {
    expect(loadLocalState(memoryStorage())).toEqual(defaultLocalState());

    const corrupt = memoryStorage();
    corrupt.setItem(LOCAL_STATE_KEY, '{not json');
    expect(loadLocalState(corrupt)).toEqual(defaultLocalState());

    const foreign = memoryStorage();
    foreign.setItem(LOCAL_STATE_KEY, JSON.stringify({ v: 99 }));
    expect(loadLocalState(foreign)).toEqual(defaultLocalState());
  });

  it('fills later-added fields over defaults when loading older payloads', () => {
    const storage = memoryStorage();
    storage.setItem(
      LOCAL_STATE_KEY,
      JSON.stringify({ v: 1, firstShiftDone: true, streak: { current: 2 } }),
    );
    const loaded = loadLocalState(storage);
    expect(loaded.firstShiftDone).toBe(true);
    expect(loaded.streak.current).toBe(2);
    expect(loaded.streak.best).toBe(0);
    expect(loaded.record.rating).toBe(1200);
  });
});
