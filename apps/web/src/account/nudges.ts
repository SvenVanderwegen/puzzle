/**
 * Guest→account nudge decision (product §1 — exactly three placements,
 * never modal-blocking):
 *  1. post-first-solve card footer, one line (streak.guestNote);
 *  2. streak day 3+, post-solve: THE primary nudge (streak.protect) — the
 *     user now has something to lose;
 *  3. the persistent Guest chip in the chrome header (AppChrome, wired to
 *     /login).
 * This module decides 1 vs 2 for the post-solve stats card; signed-in users
 * are never nudged.
 */
import type { LocalState } from '../state/localState';

export type PostSolveNudgeKind = 'guest-note' | 'streak-protect' | null;

const PROTECT_FROM_STREAK = 3;

export function decidePostSolveNudge(state: LocalState): PostSolveNudgeKind {
  if (state.account !== null) return null;
  if (state.streak.current >= PROTECT_FROM_STREAK) return 'streak-protect';
  return 'guest-note';
}
