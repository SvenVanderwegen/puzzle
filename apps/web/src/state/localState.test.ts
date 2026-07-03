import { describe, expect, it } from 'vitest';
import {
  defaultLocalState,
  LOCAL_STATE_KEY,
  loadLocalState,
  memoryStorage,
  saveLocalState,
  SOLVE_LOG_LIMIT,
  withAccount,
  withClearedSolveLog,
  withLoggedSolve,
  withoutAccount,
  type SolveLogEntry,
} from './localState';

function logEntry(overrides: Partial<SolveLogEntry> = {}): SolveLogEntry {
  return {
    clientSolveId: '01980000-0000-7000-8000-000000000001',
    mode: 'daily',
    date: '2026-07-03',
    shaded: '000010010',
    clientMs: 61_000,
    hints: { s1: 0, s2: 0, s3: 0 },
    solvedAt: '2026-07-03T20:00:00.000Z',
    ...overrides,
  };
}

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
    // Pre-WS-14 payloads gain the preference defaults (sound off, product §4).
    expect(loaded.prefs).toEqual({
      sound: false,
      reducedMotion: false,
      hideTimer: false,
      highContrast: false,
    });
  });

  it('withAccount/withoutAccount touch ONLY the signed-in marker', () => {
    const guest = {
      ...defaultLocalState(),
      firstShiftDone: true,
      streak: { current: 3, best: 5, lastDailyDate: '2026-07-02' },
      prefs: { sound: true, reducedMotion: false, hideTimer: true, highContrast: false },
    };
    const signedIn = withAccount(guest, 'crew@example.com');
    expect(signedIn.account).toEqual({ email: 'crew@example.com' });
    expect({ ...signedIn, account: null }).toEqual(guest);

    // Deletion/sign-out semantics: the guest record survives untouched.
    const after = withoutAccount(signedIn);
    expect(after).toEqual(guest);
  });
});

describe('localState — guest solve log (WS-20)', () => {
  it('appends entries, replacing a re-recorded clientSolveId', () => {
    const one = logEntry();
    const two = logEntry({
      clientSolveId: '01980000-0000-7000-8000-000000000002',
      mode: 'endless',
      date: null,
    });
    let state = withLoggedSolve(defaultLocalState(), one);
    state = withLoggedSolve(state, two);
    expect(state.solveLog).toEqual([one, two]);

    const replaced = logEntry({ clientMs: 90_000 });
    state = withLoggedSolve(state, replaced);
    expect(state.solveLog).toEqual([two, replaced]);
  });

  it('caps the log at the import limit, keeping the newest entries', () => {
    let state = defaultLocalState();
    for (let i = 0; i < SOLVE_LOG_LIMIT + 5; i += 1) {
      state = withLoggedSolve(
        state,
        logEntry({ clientSolveId: `01980000-0000-7000-8000-${String(i).padStart(12, '0')}` }),
      );
    }
    expect(state.solveLog).toHaveLength(SOLVE_LOG_LIMIT);
    // The oldest five fell off; the newest survive (streak lives in the tail).
    expect(state.solveLog[0]?.clientSolveId).toBe('01980000-0000-7000-8000-000000000005');
    expect(state.solveLog[SOLVE_LOG_LIMIT - 1]?.clientSolveId).toBe(
      `01980000-0000-7000-8000-${String(SOLVE_LOG_LIMIT + 4).padStart(12, '0')}`,
    );
  });

  it('clears ONLY the log after a merge; the guest record stays', () => {
    const state = {
      ...defaultLocalState(),
      firstShiftDone: true,
      streak: { current: 3, best: 5, lastDailyDate: '2026-07-02' },
      solveLog: [logEntry()],
    };
    const cleared = withClearedSolveLog(state);
    expect(cleared.solveLog).toEqual([]);
    expect({ ...cleared, solveLog: state.solveLog }).toEqual(state);
  });

  it('round-trips through storage and drops malformed persisted entries', () => {
    const storage = memoryStorage();
    const state = withLoggedSolve(defaultLocalState(), logEntry());
    saveLocalState(storage, state);
    expect(loadLocalState(storage)).toEqual(state);

    const tampered = memoryStorage();
    tampered.setItem(
      LOCAL_STATE_KEY,
      JSON.stringify({
        v: 1,
        firstShiftDone: false,
        solveLog: [
          logEntry(),
          { clientSolveId: 42 },
          logEntry({ shaded: 'abc' as unknown as string }),
          'junk',
        ],
      }),
    );
    expect(loadLocalState(tampered).solveLog).toEqual([logEntry()]);
  });

  it('pre-WS-20 payloads load with an empty log', () => {
    const storage = memoryStorage();
    storage.setItem(LOCAL_STATE_KEY, JSON.stringify({ v: 1, firstShiftDone: true }));
    expect(loadLocalState(storage).solveLog).toEqual([]);
  });
});
