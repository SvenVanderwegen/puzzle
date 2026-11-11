/// <reference types="node" />
/**
 * Generates the puzzles of one printed Burnfront book.
 *
 * Run: node --experimental-strip-types --no-warnings book/generate.ts [volume]
 * Writes book/data/volume-<n>.json. Seeded per volume and puzzle number, with
 * a fixed amount of work, so running it again gives the same book.
 *
 * Uses the app's generator, so every puzzle has exactly one answer that can
 * be found without guessing. Each one is checked again here before it is
 * written.
 */
import { writeFileSync } from 'node:fs';

import { countSolutions, generatePuzzle, type Puzzle } from '../apps/mobile/src/engine/generator.ts';
import { seedFor, seededRandom } from '../apps/mobile/src/engine/random.ts';
import { bfs, buildAdj } from '../apps/mobile/src/engine/rules.ts';

type Step = { upTo: number; rows: number; cols: number; breaks: number; extraClues: number; level: string };

/** Easy to hard. `extraClues` keeps more numbers on the early boards. */
const CURVE: Step[] = [
  { upTo: 10, rows: 5, cols: 5, breaks: 3, extraClues: 3, level: 'Easy' },
  { upTo: 25, rows: 5, cols: 5, breaks: 4, extraClues: 1, level: 'Easy' },
  { upTo: 40, rows: 6, cols: 6, breaks: 6, extraClues: 2, level: 'Medium' },
  { upTo: 55, rows: 6, cols: 6, breaks: 8, extraClues: 0, level: 'Medium' },
  { upTo: 70, rows: 7, cols: 7, breaks: 9, extraClues: 2, level: 'Hard' },
  { upTo: 85, rows: 7, cols: 7, breaks: 11, extraClues: 0, level: 'Hard' },
  { upTo: 100, rows: 8, cols: 8, breaks: 13, extraClues: 0, level: 'Very hard' },
];

const volume = Number(process.argv[2] ?? 1);
const total = CURVE[CURVE.length - 1].upTo;

/** Independent check: the stored answer burns every open square and meets every number. */
function verify(p: Puzzle): void {
  const n = p.rows * p.cols;
  const adj = buildAdj(p.rows, p.cols);
  const shaded = new Uint8Array(n);
  for (const i of p.solution) shaded[i] = 1;
  if (p.solution.length !== p.breaks) throw new Error('wrong number of firebreaks');
  const dist = bfs(n, adj, p.spark, (i: number) => !shaded[i]);
  for (let i = 0; i < n; i++) if (!shaded[i] && dist[i] < 0) throw new Error(`square ${i} never burns`);
  for (const [cell, minute] of p.clues) if (dist[cell] !== minute) throw new Error(`number at ${cell} is wrong`);
}

const puzzles = [];
const seen = new Set<string>();
for (let k = 1; k <= total; k++) {
  const step = CURVE.find((s) => k <= s.upTo)!;
  let p: Puzzle;
  for (let attempt = 0; ; attempt++) {
    p = await generatePuzzle(step.rows, step.cols, step.breaks, {
      random: seededRandom(seedFor('burnfront-book-terrain-v1', `${volume}:${k}:${attempt}`)),
      workBudget: 400,
      minClues: step.breaks + step.extraClues,
    });
    const key = JSON.stringify([p.rows, p.spark, p.solution]);
    if (!seen.has(key)) {
      seen.add(key);
      break;
    }
  }
  verify(p);
  puzzles.push({
    n: k,
    level: step.level,
    rows: p.rows,
    cols: p.cols,
    breaks: p.breaks,
    spark: p.spark,
    clues: p.clues,
    solution: p.solution,
    times: p.times,
  });
  console.log(`${k}: ${p.rows}x${p.cols}, ${p.breaks} firebreaks, ${p.clues.length} numbers`);
}

writeFileSync(
  new URL(`./data/volume-${volume}.json`, import.meta.url),
  JSON.stringify({ volume, puzzles }) + '\n',
);
console.log(`wrote ${puzzles.length} puzzles for volume ${volume}`);
