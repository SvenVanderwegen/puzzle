/**
 * Guest→account nudge decision (product §1 — exactly three placements,
 * never modal-blocking):
 *  1. post-first-solve card footer, one line (streak.guestNote);
 *  2. streak day 3+, post-solve: THE primary nudge (streak.protect) — the
 *     user now has something to lose. Past 7 days it switches to the capped
 *     variant (streak.protect.capped): the WS-20 merge carries at most the
 *     trailing 7 days, and the nudge must not promise more than the merge
 *     delivers;
 *  3. the persistent Guest chip in the chrome header (AppChrome, wired to
 *     /login).
 * This module decides 1 vs 2 for the post-solve stats card; signed-in users
 * are never nudged.
 */
import type { LocalState } from '../state/localState';

export type PostSolveNudgeKind =
  | 'guest-note'
  | 'streak-protect'
  | 'streak-protect-capped'
  | null;

const PROTECT_FROM_STREAK = 3;

/** The merge carries at most this many trailing streak days (WS-20 cap). */
const MERGE_CARRY_DAYS = 7;

export function decidePostSolveNudge(state: LocalState): PostSolveNudgeKind {
  if (state.account !== null) return null;
  if (state.streak.current > MERGE_CARRY_DAYS) return 'streak-protect-capped';
  if (state.streak.current >= PROTECT_FROM_STREAK) return 'streak-protect';
  return 'guest-note';
}
