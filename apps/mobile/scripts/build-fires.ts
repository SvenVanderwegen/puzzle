/// <reference types="node" />
/**
 * Builds the fires that ship inside the app, so the phone never has to
 * search for a big board while the player waits:
 *   src/data/campaign.json  the 20 campaign fires
 *   src/data/daily.json     one daily fire per date, starting at DAILY_EPOCH
 *
 * Run: npm run build-fires [days]   (default 400 days)
 * Seeded, so running it again gives the same fires. Dates past the end of
 * daily.json are generated on the phone with the same seed.
 */
import { writeFileSync } from 'node:fs';

import { CAMPAIGN, DAILY_EPOCH, DAILY_FIRE } from '../src/config.ts';
import { generatePuzzle, type Puzzle } from '../src/engine/generator.ts';
import { seedFor, seededRandom } from '../src/engine/random.ts';
import { localDate } from '../src/engine/fires.ts';

type Packed = [rows: number, cols: number, breaks: number, spark: number, clues: number[], solution: number[]];

const pack = (p: Puzzle): Packed => [p.rows, p.cols, p.breaks, p.spark, p.clues.flat(), p.solution];

const days = Number(process.argv[2] ?? 400);

const campaign: Packed[] = [];
for (let n = 1; n <= CAMPAIGN.length; n++) {
  const s = CAMPAIGN[n - 1];
  const p = await generatePuzzle(s.rows, s.cols, s.breaks, {
    random: seededRandom(seedFor('burnfront-app-campaign-terrain-v1', String(n))),
    budgetMs: s.budgetMs * 2,
    minClues: s.minClues,
  });
  campaign.push(pack(p));
  console.log(`campaign ${n}: ${p.rows}x${p.cols}, ${p.clues.length} numbers`);
}
writeFileSync(new URL('../src/data/campaign.json', import.meta.url), JSON.stringify(campaign) + '\n');

const daily: Packed[] = [];
const start = new Date(`${DAILY_EPOCH}T12:00:00`);
for (let k = 0; k < days; k++) {
  const date = localDate(new Date(start.getTime() + k * 86400000));
  const p = await generatePuzzle(DAILY_FIRE.rows, DAILY_FIRE.cols, DAILY_FIRE.breaks, {
    random: seededRandom(seedFor('burnfront-app-daily-terrain-v1', date)),
    workBudget: DAILY_FIRE.workBudget,
    minClues: DAILY_FIRE.minClues,
  });
  daily.push(pack(p));
  if (k % 50 === 0) console.log(`daily ${date}: ${p.clues.length} numbers`);
}
writeFileSync(
  new URL('../src/data/daily.json', import.meta.url),
  JSON.stringify({ from: DAILY_EPOCH, fires: daily }) + '\n',
);
console.log(`wrote ${campaign.length} campaign fires and ${daily.length} daily fires`);
