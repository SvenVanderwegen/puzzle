/**
 * Direct-storage writes for the Daily surface, mirroring endless/prefs.ts:
 * the play surface persists the in-progress record and the streak straight to
 * localStorage (read-modify-write of the freshest persisted state), NOT
 * through the runtime's reactive store — the same discipline localState.ts's
 * appendSolveLog documents, so the guest solve-log sibling never clobbers
 * these writes. The hub reads the reactive store; like endless, it reflects
 * these writes on its next mount (state re-sync is a state-layer concern).
 *
 * Streak credit is local and provisional (product §1, anonymous-first). Only
 * TODAY'S daily moves the streak; archive (past-date) solves never do — the
 * server is authoritative and rules the same way (StreakService: credit only
 * when `daily->date === today`).
 */
import { utcDayBefore } from '../state/clock';
import {
  loadLocalState,
  saveLocalState,
  type DailyProgress,
  type StorageLike,
  type StreakState,
} from '../state/localState';

/** Persist the in-progress daily (hub "Resume — {elapsed}"). */
export function markDailyInProgress(storage: StorageLike, date: string, elapsedMs: number): void {
  const state = loadLocalState(storage);
  const progress: DailyProgress = {
    date,
    status: 'in_progress',
    elapsedMs: Math.max(0, Math.round(elapsedMs)),
  };
  saveLocalState(storage, { ...state, daily: progress });
}

/** Persist the contained daily (final time). Streak is credited separately. */
export function markDailyContained(storage: StorageLike, date: string, containedMs: number): void {
  const state = loadLocalState(storage);
  const ms = Math.max(0, Math.round(containedMs));
  const progress: DailyProgress = {
    date,
    status: 'contained',
    elapsedMs: ms,
    containedMs: ms,
  };
  saveLocalState(storage, { ...state, daily: progress });
}

/**
 * Local streak transition for a contained daily. Only today's incident counts;
 * a continued run needs yesterday's contain, otherwise the streak restarts at
 * 1 (a guest browser has no freeze — freezes live server-side). Idempotent:
 * re-crediting today is a no-op.
 */
export function creditLocalStreak(
  streak: StreakState,
  date: string,
  today: string,
): StreakState {
  if (date !== today) return streak;
  if (streak.lastDailyDate === today) return streak;
  const continues = streak.current > 0 && streak.lastDailyDate === utcDayBefore(today);
  const current = continues ? streak.current + 1 : 1;
  return {
    current,
    best: Math.max(streak.best, current),
    lastDailyDate: today,
  };
}

/** Persist a streak (the locally-credited run, or a server-authoritative one). */
export function writeLocalStreak(storage: StorageLike, streak: StreakState): void {
  const state = loadLocalState(storage);
  saveLocalState(storage, { ...state, streak });
}

/** The freshest persisted streak (reads storage, not the boot-time store). */
export function readLocalStreak(storage: StorageLike): StreakState {
  return loadLocalState(storage).streak;
}
