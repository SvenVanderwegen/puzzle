/**
 * Pure game logic on top of the shared rules: what the board shows, which
 * numbers are flagged, what a hint does. No React here, so it is testable.
 */
import { bfs, buildAdj, validate } from './rules.ts';
import type { Puzzle } from './generator.ts';

/** 0 empty, 1 firebreak, 2 marked clear (a dot). */
export type Mark = 0 | 1 | 2;

const adjCache = new Map<string, number[][]>();
function adjFor(p: Puzzle): number[][] {
  const k = `${p.rows}x${p.cols}`;
  let a = adjCache.get(k);
  if (!a) {
    a = buildAdj(p.rows, p.cols) as number[][];
    adjCache.set(k, a);
  }
  return a;
}

export function emptyMarks(p: Puzzle): Mark[] {
  return new Array(p.rows * p.cols).fill(0);
}

export function clueMap(p: Puzzle): Map<number, number> {
  return new Map(p.clues);
}

export function countBreaks(marks: readonly number[]): number {
  let k = 0;
  for (const m of marks) if (m === 1) k++;
  return k;
}

/** Minute each cell burns with the current firebreaks, -1 if it never burns. */
export function burnTimes(p: Puzzle, marks: readonly number[]): Int32Array {
  return bfs(p.rows * p.cols, adjFor(p), p.spark, (i: number) => marks[i] !== 1);
}

/** The shared validator: burn times if the marking contains the fire, else false. */
export function solvedTimes(p: Puzzle, marks: readonly number[]): Int32Array | false {
  const n = p.rows * p.cols;
  const breaks = new Uint8Array(n);
  for (let i = 0; i < n; i++) if (marks[i] === 1) breaks[i] = 1;
  return validate(
    n,
    adjFor(p),
    p.spark,
    Int32Array.from(p.clues.map((c) => c[0])),
    Int32Array.from(p.clues.map((c) => c[1])),
    p.breaks,
    breaks,
  );
}

/** Last minute of the fire in the answer: the end of the colour ramp. */
export function lastMinute(p: Puzzle): number {
  let m = 1;
  for (const t of p.times) if (t > m) m = t;
  return m;
}

/**
 * Numbers that are certainly wrong right now. A firebreak only ever delays
 * the fire, so a number that already burns too late (or not at all) can
 * never come right without taking a firebreak away. With every firebreak
 * placed, any mismatch counts.
 */
export function wrongClues(p: Puzzle, marks: readonly number[], flagLate: boolean): Set<number> {
  const out = new Set<number>();
  const full = countBreaks(marks) >= p.breaks;
  if (!flagLate && !full) return out;
  const d = burnTimes(p, marks);
  for (const [cell, minute] of p.clues) {
    const late = d[cell] < 0 || d[cell] > minute;
    if ((flagLate && late) || (full && d[cell] !== minute)) out.add(cell);
  }
  return out;
}

export type MessageKey =
  | { key: 'play.msg.start' }
  | { key: 'play.msg.toGo'; count: number }
  | { key: 'play.msg.ringed' }
  | { key: 'play.msg.allPlaced'; count: number }
  | { key: 'play.msg.tooMany'; count: number };

export function message(p: Puzzle, marks: readonly number[], wrong: Set<number>): MessageKey {
  const count = countBreaks(marks);
  if (count > p.breaks) return { key: 'play.msg.tooMany', count: p.breaks };
  if (count === p.breaks) return { key: 'play.msg.allPlaced', count: p.breaks };
  if (wrong.size > 0) return { key: 'play.msg.ringed' };
  if (count === 0) return { key: 'play.msg.start' };
  return { key: 'play.msg.toGo', count: p.breaks - count };
}

/** One step toward the answer: remove a wrong firebreak first, else add a missing one. */
export function hintMove(p: Puzzle, marks: readonly number[]): { cell: number; mark: Mark } | null {
  const answer = new Set(p.solution);
  for (let i = 0; i < marks.length; i++) if (marks[i] === 1 && !answer.has(i)) return { cell: i, mark: 0 };
  for (const i of p.solution) if (marks[i] !== 1) return { cell: i, mark: 1 };
  return null;
}

/** How many cells caught fire in each minute: the spoiler-free burn curve. */
export function burnCurve(p: Puzzle): number[] {
  const out = new Array(lastMinute(p) + 1).fill(0);
  for (const t of p.times) if (t >= 0) out[t]++;
  return out;
}

const BLOCKS = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];

export function curveBlocks(curve: number[]): string {
  const max = Math.max(1, ...curve);
  return curve.map((v) => BLOCKS[Math.min(7, Math.round((v / max) * 7))]).join('');
}
