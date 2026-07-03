/**
 * Anonymous-first local state (product §1): daily progress, endless session,
 * streak, academy progress and the provisional record all live in
 * localStorage — strictly-necessary storage, no account required. Accounts
 * (WS-14/WS-20) merge this record server-side; they never gate it.
 *
 * Storage is injected so tests and non-browser environments stay pure.
 */
export type Tier = 'lookout' | 'crew' | 'hotshot';

export interface DailyProgress {
  /** UTC date (YYYY-MM-DD) the progress belongs to. */
  readonly date: string;
  readonly status: 'in_progress' | 'contained';
  /** Solve timer so the hub can offer "Resume — {elapsed} elapsed". */
  readonly elapsedMs: number;
  /** Final time when contained. */
  readonly containedMs?: number;
}

export interface EndlessState {
  readonly tier: Tier;
  /** A generated board is mid-solve (hub: "Resume Endless"). */
  readonly inProgress: boolean;
  readonly solvedByTier: Readonly<Record<Tier, number>>;
}

export interface StreakState {
  readonly current: number;
  readonly best: number;
  /** UTC date of the last contained daily, or null. */
  readonly lastDailyDate: string | null;
}

export interface AcademyState {
  readonly done: number;
  readonly total: number;
}

export interface RecordState {
  /** Provisional local Fire Rating (server rating arrives with accounts). */
  readonly rating: number;
  readonly lastDelta: number;
  readonly games: number;
  readonly cleanContains: number;
}

/**
 * Device preferences (product §1 /settings row: sound, reduced motion,
 * hide-timer, high-contrast) — local-only, never synced to the account.
 * Sound is off by default on web until the first solve (product §4).
 */
export interface PrefsState {
  readonly sound: boolean;
  readonly reducedMotion: boolean;
  readonly hideTimer: boolean;
  readonly highContrast: boolean;
}

export interface LocalState {
  readonly v: 1;
  /** First Shift (tutorial) completed — drives the Play button's first state. */
  readonly firstShiftDone: boolean;
  readonly daily: DailyProgress | null;
  readonly endless: EndlessState;
  readonly streak: StreakState;
  readonly academy: AcademyState;
  readonly record: RecordState;
  readonly prefs: PrefsState;
  /** Signed-in marker (WS-14 sets it); null = guest chip everywhere. */
  readonly account: { readonly email: string } | null;
}

export interface StorageLike {
  getItem(key: string): string | null;
  setItem(key: string, value: string): void;
}

export const LOCAL_STATE_KEY = 'burnfront.local.v1';

/** Glicko-2 seed per RATING.md — the provisional local rating starts here too. */
const INITIAL_RATING = 1200;

export function defaultLocalState(): LocalState {
  return {
    v: 1,
    firstShiftDone: false,
    daily: null,
    endless: {
      tier: 'lookout',
      inProgress: false,
      solvedByTier: { lookout: 0, crew: 0, hotshot: 0 },
    },
    streak: { current: 0, best: 0, lastDailyDate: null },
    academy: { done: 0, total: 7 },
    record: { rating: INITIAL_RATING, lastDelta: 0, games: 0, cleanContains: 0 },
    prefs: { sound: false, reducedMotion: false, hideTimer: false, highContrast: false },
    account: null,
  };
}

/** Loads state; malformed/missing/versioned-away payloads fall back to defaults. */
export function loadLocalState(storage: StorageLike): LocalState {
  const raw = storage.getItem(LOCAL_STATE_KEY);
  if (raw === null) return defaultLocalState();
  try {
    const parsed: unknown = JSON.parse(raw);
    if (
      typeof parsed === 'object' &&
      parsed !== null &&
      (parsed as { v?: unknown }).v === 1 &&
      typeof (parsed as { firstShiftDone?: unknown }).firstShiftDone === 'boolean'
    ) {
      // Merge over defaults so later-added fields stay present.
      const defaults = defaultLocalState();
      const candidate = parsed as Partial<LocalState>;
      return {
        ...defaults,
        ...candidate,
        v: 1,
        endless: { ...defaults.endless, ...candidate.endless },
        streak: { ...defaults.streak, ...candidate.streak },
        academy: { ...defaults.academy, ...candidate.academy },
        record: { ...defaults.record, ...candidate.record },
        prefs: { ...defaults.prefs, ...candidate.prefs },
      };
    }
    return defaultLocalState();
  } catch {
    return defaultLocalState();
  }
}

export function saveLocalState(storage: StorageLike, state: LocalState): void {
  storage.setItem(LOCAL_STATE_KEY, JSON.stringify(state));
}

/** Marks the browser as signed in (WS-14 auth); everything else is untouched. */
export function withAccount(state: LocalState, email: string): LocalState {
  return { ...state, account: { email } };
}

/**
 * Drops the signed-in marker ONLY — the anonymous-first record (streak,
 * rating, progress, prefs) stays. Sign-out and account deletion never erase
 * guest state (product §1; the server side is settings.delete.explain).
 */
export function withoutAccount(state: LocalState): LocalState {
  return { ...state, account: null };
}

/** In-memory fallback when localStorage is unavailable (private mode etc.). */
export function memoryStorage(): StorageLike {
  const map = new Map<string, string>();
  return {
    getItem: (key) => map.get(key) ?? null,
    setItem: (key, value) => {
      map.set(key, value);
    },
  };
}

/** The browser store, guarded — storage access can throw in locked-down contexts. */
export function browserStorage(): StorageLike {
  try {
    const probe = globalThis.localStorage;
    probe.getItem(LOCAL_STATE_KEY);
    return probe;
  } catch {
    return memoryStorage();
  }
}
