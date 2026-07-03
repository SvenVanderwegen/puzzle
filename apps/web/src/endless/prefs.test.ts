/**
 * Dial + per-tier history persistence, LocalState sync (hub lane contract)
 * and the StorageLike → game-core KeyValueStorage adapter.
 */
import { describe, expect, it } from 'vitest';
import { loadLocalState, memoryStorage, type StorageLike } from '../state/localState';
import {
  creditEndlessSolve,
  defaultPrefs,
  ENDLESS_PREFS_KEY,
  loadPrefs,
  markEndlessInProgress,
  recordTierWin,
  saveDial,
} from './prefs';
import { toKeyValueStorage } from './storage';

describe('endless prefs', () => {
  it('defaults to no dial (rating recommendation applies) and empty history', () => {
    const prefs = loadPrefs(memoryStorage());
    expect(prefs).toEqual(defaultPrefs());
    expect(prefs.dial).toBeNull();
    expect(prefs.history.crew).toEqual({ solved: 0, bestMs: null, lastMs: null });
  });

  it('persists the dial across reload', () => {
    const storage = memoryStorage();
    saveDial(storage, 'hotshot');
    expect(loadPrefs(storage).dial).toBe('hotshot');
  });

  it('tracks per-tier history: solved count, best and last times', () => {
    const storage = memoryStorage();
    recordTierWin(storage, 'crew', 90_000);
    recordTierWin(storage, 'crew', 60_000);
    const updated = recordTierWin(storage, 'crew', 75_000);
    expect(updated.history.crew).toEqual({ solved: 3, bestMs: 60_000, lastMs: 75_000 });
    expect(updated.history.lookout.solved).toBe(0);
    expect(loadPrefs(storage).history.crew.solved).toBe(3);
  });

  it('falls back to defaults on malformed or foreign payloads', () => {
    const storage = memoryStorage();
    storage.setItem(ENDLESS_PREFS_KEY, 'not json');
    expect(loadPrefs(storage)).toEqual(defaultPrefs());
    storage.setItem(ENDLESS_PREFS_KEY, JSON.stringify({ v: 2, dial: 'crew' }));
    expect(loadPrefs(storage)).toEqual(defaultPrefs());
    storage.setItem(
      ENDLESS_PREFS_KEY,
      JSON.stringify({ v: 1, dial: 'volcano', history: { crew: { solved: 'many' } } }),
    );
    const prefs = loadPrefs(storage);
    expect(prefs.dial).toBeNull();
    expect(prefs.history.crew.solved).toBe(0);
  });
});

describe('LocalState sync (hub lane contract)', () => {
  it('markEndlessInProgress drives the Resume Endless button state', () => {
    const storage = memoryStorage();
    markEndlessInProgress(storage, 'hotshot', true);
    let state = loadLocalState(storage);
    expect(state.endless.tier).toBe('hotshot');
    expect(state.endless.inProgress).toBe(true);
    markEndlessInProgress(storage, 'hotshot', false);
    state = loadLocalState(storage);
    expect(state.endless.inProgress).toBe(false);
  });

  it('creditEndlessSolve bumps the tier count and clears inProgress', () => {
    const storage = memoryStorage();
    markEndlessInProgress(storage, 'crew', true);
    expect(creditEndlessSolve(storage, 'crew')).toBe(1);
    expect(creditEndlessSolve(storage, 'crew')).toBe(2);
    const state = loadLocalState(storage);
    expect(state.endless.solvedByTier).toEqual({ lookout: 0, crew: 2, hotshot: 0 });
    expect(state.endless.inProgress).toBe(false);
  });
});

describe('toKeyValueStorage adapter', () => {
  it('adapts get/set and tombstones remove() on removeItem-less stores', () => {
    const storage = memoryStorage();
    const kv = toKeyValueStorage(storage);
    expect(kv.get('k')).toBeNull();
    kv.set('k', 'v');
    expect(kv.get('k')).toBe('v');
    kv.remove('k');
    expect(kv.get('k')).toBeNull();
  });

  it('uses the underlying removeItem when present', () => {
    const map = new Map<string, string>();
    let removed = '';
    const store: StorageLike & { removeItem(key: string): void } = {
      getItem: (key) => map.get(key) ?? null,
      setItem: (key, value) => void map.set(key, value),
      removeItem: (key) => {
        removed = key;
        map.delete(key);
      },
    };
    const kv = toKeyValueStorage(store);
    kv.set('a', '1');
    kv.remove('a');
    expect(removed).toBe('a');
    expect(kv.get('a')).toBeNull();
  });
});
