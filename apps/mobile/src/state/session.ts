/**
 * The fire being played right now. Saved on the phone, so a player who
 * leaves the app finds the board exactly as they left it.
 */
import { countBreaks, emptyMarks, hintMove, solvedTimes, type Mark } from '@/engine/game';
import type { Fire } from '@/engine/puzzles';

import { recordWin } from './progress';
import { createStore, useStore } from './store';

export type Session = {
  fire: Fire | null;
  marks: Mark[];
  undo: [cell: number, prev: Mark][];
  hints: number;
  /** Time played before the current stretch. */
  elapsedMs: number;
  /** When the current stretch started, 0 while paused or not started. */
  runningSince: number;
  solved: boolean;
  finalMs: number;
  solvedAt: number;
};

const blank: Session = {
  fire: null,
  marks: [],
  undo: [],
  hints: 0,
  elapsedMs: 0,
  runningSince: 0,
  solved: false,
  finalMs: 0,
  solvedAt: 0,
};

export const sessionStore = createStore<Session>('session', blank);
export const useSession = () => useStore(sessionStore);

export function elapsed(s: Session, now = Date.now()): number {
  if (s.solved) return s.finalMs;
  return s.elapsedMs + (s.runningSince ? now - s.runningSince : 0);
}

/** Start a fire, or keep the one in progress if it is the same fire. */
export function startFire(fire: Fire) {
  const s = sessionStore.get();
  if (s.fire && s.fire.kind === fire.kind && s.fire.key === fire.key && !s.solved) return;
  sessionStore.set({ ...blank, fire, marks: emptyMarks(fire.puzzle) });
}

/** `full`: every firebreak is down, so the tap marked the square clear instead. */
export type MoveOutcome = 'placed' | 'cleared' | 'dotted' | 'full' | 'solved' | 'none';

function apply(cell: number, mark: Mark, isHint: boolean): MoveOutcome {
  const s = sessionStore.get();
  if (!s.fire || s.solved) return 'none';
  const marks = s.marks.slice();
  const prev = marks[cell];
  if (prev === mark) return 'none';
  marks[cell] = mark;
  const now = Date.now();
  const next: Session = {
    ...s,
    marks,
    undo: [...s.undo, [cell, prev]],
    hints: s.hints + (isHint ? 1 : 0),
    runningSince: s.runningSince || now,
  };
  if (solvedTimes(s.fire.puzzle, marks)) {
    next.solved = true;
    next.solvedAt = now;
    next.finalMs = elapsed(next, now);
    next.runningSince = 0;
    sessionStore.set(next);
    recordWin(s.fire, { ms: next.finalMs, hints: next.hints });
    return 'solved';
  }
  sessionStore.set(next);
  return mark === 1 ? 'placed' : mark === 2 ? 'dotted' : 'cleared';
}

/** A tap cycles empty → firebreak → clear → empty. The firebreak supply is real. */
export function tapCell(cell: number): MoveOutcome {
  const s = sessionStore.get();
  if (!s.fire || s.solved) return 'none';
  const p = s.fire.puzzle;
  if (cell === p.spark || p.clues.some((c) => c[0] === cell)) return 'none';
  const cur = s.marks[cell];
  let next = ((cur + 1) % 3) as Mark;
  if (next === 1 && countBreaks(s.marks) >= p.breaks) next = 2;
  if (cur === 0 && next === 2) {
    apply(cell, 2, false);
    return 'full';
  }
  return apply(cell, next, false);
}

export function applyHint(): MoveOutcome {
  const s = sessionStore.get();
  if (!s.fire || s.solved) return 'none';
  const move = hintMove(s.fire.puzzle, s.marks);
  return move ? apply(move.cell, move.mark, true) : 'none';
}

export function undoMove() {
  const s = sessionStore.get();
  if (!s.fire || s.solved || !s.undo.length) return;
  const undo = s.undo.slice();
  const [cell, prev] = undo.pop()!;
  const marks = s.marks.slice();
  marks[cell] = prev;
  sessionStore.set({ ...s, marks, undo });
}

export function resetFire() {
  const s = sessionStore.get();
  if (!s.fire) return;
  sessionStore.set({ ...blank, fire: s.fire, marks: emptyMarks(s.fire.puzzle) });
}

/** Stop the clock while the app is in the background. */
export function pauseClock() {
  const s = sessionStore.get();
  if (!s.runningSince || s.solved) return;
  sessionStore.set({ ...s, elapsedMs: elapsed(s), runningSince: 0 });
}

export function resumeClock() {
  const s = sessionStore.get();
  if (s.runningSince || s.solved || !s.undo.length) return;
  sessionStore.set({ ...s, runningSince: Date.now() });
}
