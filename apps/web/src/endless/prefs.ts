/**
 * Endless persistence (brief: "dials + history persist across reload").
 *
 * Two stores, one storage:
 * - `burnfront.endless.v1` — feature prefs: the explicitly chosen dial (null
 *   until the player picks one, so the rating recommendation stays the
 *   default) and per-tier history (solved count, best/last times).
 * - The shared anonymous-first LocalState (`burnfront.local.v1`) — the hub's
 *   view of endless (tier, inProgress, solvedByTier) stays in sync here.
 * The mid-solve board itself is a game-core SessionSnapshot under
 * ENDLESS_SESSION_KEY (via storage.ts + game-core persistence).
 */
import { loadLocalState, saveLocalState, type StorageLike, type Tier } from '../state/localState';

export const ENDLESS_PREFS_KEY = 'burnfront.endless.v1';
export const ENDLESS_SESSION_KEY = 'burnfront.endless.session.v1';

export interface TierHistory {
  readonly solved: number;
  readonly bestMs: number | null;
  readonly lastMs: number | null;
}

export interface EndlessPrefs {
  readonly v: 1;
  /** Explicit dial choice; null = follow the rating recommendation. */
  readonly dial: Tier | null;
  readonly history: Readonly<Record<Tier, TierHistory>>;
}

const EMPTY_TIER: TierHistory = { solved: 0, bestMs: null, lastMs: null };

export function defaultPrefs(): EndlessPrefs {
  return {
    v: 1,
    dial: null,
    history: { lookout: EMPTY_TIER, crew: EMPTY_TIER, hotshot: EMPTY_TIER },
  };
}

function isTier(value: unknown): value is Tier {
  return value === 'lookout' || value === 'crew' || value === 'hotshot';
}

function isTierHistory(value: unknown): value is TierHistory {
  if (typeof value !== 'object' || value === null) return false;
  const record = value as Record<string, unknown>;
  return (
    typeof record.solved === 'number' &&
    (record.bestMs === null || typeof record.bestMs === 'number') &&
    (record.lastMs === null || typeof record.lastMs === 'number')
  );
}

/** Tolerant load: malformed or versioned-away payloads fall back to defaults. */
export function loadPrefs(storage: StorageLike): EndlessPrefs {
  const raw = storage.getItem(ENDLESS_PREFS_KEY);
  if (raw === null || raw === '') return defaultPrefs();
  try {
    const parsed: unknown = JSON.parse(raw);
    if (typeof parsed !== 'object' || parsed === null) return defaultPrefs();
    const candidate = parsed as Record<string, unknown>;
    if (candidate.v !== 1) return defaultPrefs();
    const defaults = defaultPrefs();
    const history = (candidate.history ?? {}) as Record<string, unknown>;
    return {
      v: 1,
      dial: isTier(candidate.dial) ? candidate.dial : null,
      history: {
        lookout: isTierHistory(history.lookout) ? history.lookout : EMPTY_TIER,
        crew: isTierHistory(history.crew) ? history.crew : EMPTY_TIER,
        hotshot: isTierHistory(history.hotshot) ? history.hotshot : defaults.history.hotshot,
      },
    };
  } catch {
    return defaultPrefs();
  }
}

export function savePrefs(storage: StorageLike, prefs: EndlessPrefs): void {
  storage.setItem(ENDLESS_PREFS_KEY, JSON.stringify(prefs));
}

/** Persist an explicit dial choice. */
export function saveDial(storage: StorageLike, tier: Tier): void {
  savePrefs(storage, { ...loadPrefs(storage), dial: tier });
}

/** Update the per-tier history after a contained board. */
export function recordTierWin(storage: StorageLike, tier: Tier, elapsedMs: number): EndlessPrefs {
  const prefs = loadPrefs(storage);
  const entry = prefs.history[tier];
  const updated: EndlessPrefs = {
    ...prefs,
    history: {
      ...prefs.history,
      [tier]: {
        solved: entry.solved + 1,
        bestMs: entry.bestMs === null ? elapsedMs : Math.min(entry.bestMs, elapsedMs),
        lastMs: elapsedMs,
      },
    },
  };
  savePrefs(storage, updated);
  return updated;
}

/** Sync the hub's endless lane state (tier + mid-solve flag). */
export function markEndlessInProgress(storage: StorageLike, tier: Tier, inProgress: boolean): void {
  const state = loadLocalState(storage);
  saveLocalState(storage, { ...state, endless: { ...state.endless, tier, inProgress } });
}

/** Credit a contained endless board in LocalState; returns the new tier count. */
export function creditEndlessSolve(storage: StorageLike, tier: Tier): number {
  const state = loadLocalState(storage);
  const solved = state.endless.solvedByTier[tier] + 1;
  saveLocalState(storage, {
    ...state,
    endless: {
      ...state.endless,
      tier,
      inProgress: false,
      solvedByTier: { ...state.endless.solvedByTier, [tier]: solved },
    },
  });
  return solved;
}
