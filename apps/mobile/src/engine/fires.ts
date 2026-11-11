/**
 * Fires without any bundled data: dates, names, unpacking, and the
 * generators for a daily fire and a quick fire. Kept free of JSON imports so
 * node can run it for tests and for scripts/build-fires.ts.
 */
import { DAILY_EPOCH, DAILY_FIRE, QUICK_FIRES, type BoardSize } from '../config.ts';
import { generatePuzzle, type Puzzle } from './generator.ts';
import { PLACE_WORDS, pickPlace } from './names.ts';
import { seedFor, seededRandom } from './random.ts';

export type FireKind = 'daily' | 'campaign' | 'quick';

export type Fire = {
  kind: FireKind;
  /** Daily: the date (YYYY-MM-DD). Campaign: the fire number. Quick: a random id. */
  key: string;
  /** Proper noun, shown as "<place> Fire". */
  place: string;
  /** Daily number or campaign number, 0 for a quick fire. */
  number: number;
  /** Quick fire size, so "Next fire" can offer the same size again. */
  size?: BoardSize;
  puzzle: Puzzle;
};

export type Packed = (number | number[])[];

/** Rebuild a fire from its packed form; burn times are recomputed from the answer. */
export function unpack(row: Packed): Puzzle {
  const [rows, cols, breaks, spark, flat, solution] = row as [number, number, number, number, number[], number[]];
  const clues: [number, number][] = [];
  for (let k = 0; k < flat.length; k += 2) clues.push([flat[k], flat[k + 1]]);
  const n = rows * cols;
  const blocked = new Set(solution);
  const times = new Array<number>(n).fill(-1);
  const queue = [spark];
  times[spark] = 0;
  for (let h = 0; h < queue.length; h++) {
    const x = queue[h];
    const r = Math.floor(x / cols);
    const c = x % cols;
    const next = [r > 0 ? x - cols : -1, r < rows - 1 ? x + cols : -1, c > 0 ? x - 1 : -1, c < cols - 1 ? x + 1 : -1];
    for (const y of next) {
      if (y >= 0 && times[y] < 0 && !blocked.has(y)) {
        times[y] = times[x] + 1;
        queue.push(y);
      }
    }
  }
  return { rows, cols, breaks, spark, clues, solution, times };
}

/** Local calendar date as YYYY-MM-DD. */
export function localDate(d = new Date()): string {
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${d.getFullYear()}-${m}-${day}`;
}

export function daysBetween(from: string, to: string): number {
  const a = Date.UTC(+from.slice(0, 4), +from.slice(5, 7) - 1, +from.slice(8, 10));
  const b = Date.UTC(+to.slice(0, 4), +to.slice(5, 7) - 1, +to.slice(8, 10));
  return Math.round((b - a) / 86400000);
}

export function dailyNumber(date: string): number {
  return daysBetween(DAILY_EPOCH, date) + 1;
}

export function dailyPlace(date: string): string {
  return pickPlace(seededRandom(seedFor('burnfront-app-daily-name-v1', date)));
}

/** Campaign fires each get their own place: one fixed shuffle of the 20 place words. */
export function campaignPlace(n: number): string {
  const rnd = seededRandom(seedFor('burnfront-app-campaign-name-v1', 'order'));
  const order = PLACE_WORDS.map((_, k) => k);
  for (let i = order.length - 1; i > 0; i--) {
    const j = Math.floor(rnd() * (i + 1));
    [order[i], order[j]] = [order[j], order[i]];
  }
  return PLACE_WORDS[order[(n - 1) % order.length]];
}

/** Generate a daily fire on the phone: seeded, with a fixed amount of work. */
export async function generateDaily(date: string): Promise<Fire> {
  const puzzle = await generatePuzzle(DAILY_FIRE.rows, DAILY_FIRE.cols, DAILY_FIRE.breaks, {
    random: seededRandom(seedFor('burnfront-app-daily-terrain-v1', date)),
    workBudget: DAILY_FIRE.workBudget,
    minClues: DAILY_FIRE.minClues,
  });
  return { kind: 'daily', key: date, place: dailyPlace(date), number: dailyNumber(date), puzzle };
}

export async function makeQuick(size: BoardSize): Promise<Fire> {
  const shape = QUICK_FIRES[size];
  const puzzle = await generatePuzzle(shape.rows, shape.cols, shape.breaks, {
    budgetMs: shape.budgetMs,
    minClues: shape.minClues,
  });
  const key = `${Date.now().toString(36)}${Math.floor(Math.random() * 1e6).toString(36)}`;
  return { kind: 'quick', key, size, place: pickPlace(Math.random), number: 0, puzzle };
}
