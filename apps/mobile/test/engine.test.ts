/// <reference types="node" />
// Run with: npm test (node's own test runner, no app runtime needed).
import assert from 'node:assert/strict';
import { test } from 'node:test';

// The website's rules, unchanged. The generated fires must pass them.
import { buildAdj, validate } from '../../../resources/js/lib/burnfront-engine.js';
import { CAMPAIGN, QUICK_FIRES } from '../src/config.ts';
import { countSolutions, deductionSolve, generatePuzzle, type Puzzle } from '../src/engine/generator.ts';
import { crc32, seededRandom } from '../src/engine/random.ts';
import { dailyNumber, generateDaily, unpack, type Packed } from '../src/engine/fires.ts';
import { readFileSync } from 'node:fs';
import { hintMove, solvedTimes } from '../src/engine/game.ts';

function check(p: Puzzle) {
  const n = p.rows * p.cols;
  const adj = buildAdj(p.rows, p.cols);
  const breaks = new Uint8Array(n);
  for (const i of p.solution) breaks[i] = 1;
  const d = validate(
    n,
    adj,
    p.spark,
    Int32Array.from(p.clues.map((c) => c[0])),
    Int32Array.from(p.clues.map((c) => c[1])),
    p.breaks,
    breaks,
  );
  assert.ok(d, 'the answer passes the shared validator');
  assert.equal(p.solution.length, p.breaks);
  const pz = {
    R: p.rows,
    C: p.cols,
    n,
    adj,
    spark: p.spark,
    N: p.breaks,
    clueIdx: Int32Array.from(p.clues.map((c) => c[0])),
    clueVal: Int32Array.from(p.clues.map((c) => c[1])),
  };
  assert.equal(countSolutions(pz).count, 1, 'exactly one answer');
  assert.ok(deductionSolve(pz), 'solvable without guessing');
}

test('crc32 matches PHP crc32()', () => {
  assert.equal(crc32('The quick brown fox jumped over the lazy dog.'), 2191738434);
  assert.equal(crc32(''), 0);
});

test('seeded random is deterministic and in [0, 1)', () => {
  const a = seededRandom(42);
  const b = seededRandom(42);
  for (let i = 0; i < 1000; i++) {
    const x = a();
    assert.equal(x, b());
    assert.ok(x >= 0 && x < 1);
  }
});

test('quick fires of every size are valid, unique and need no guessing', async () => {
  for (const shape of Object.values(QUICK_FIRES)) {
    const p = await generatePuzzle(shape.rows, shape.cols, shape.breaks, {
      random: seededRandom(7),
      workBudget: 60,
      minClues: shape.minClues,
    });
    check(p);
  }
});

const campaign: Packed[] = JSON.parse(readFileSync(new URL('../src/data/campaign.json', import.meta.url), 'utf8'));
const daily: { from: string; fires: Packed[] } = JSON.parse(
  readFileSync(new URL('../src/data/daily.json', import.meta.url), 'utf8'),
);

test('the daily fire is the same on every phone', async () => {
  const a = await generateDaily('2026-11-11');
  const b = await generateDaily('2026-11-11');
  assert.deepEqual(a, b);
  assert.equal(a.number, 2);
  assert.equal(dailyNumber('2026-11-10'), 1);
  check(a.puzzle);
  // The bundled copy is only a cache: the phone would build the same board.
  assert.equal(daily.from, '2026-11-10');
  assert.deepEqual(unpack(daily.fires[1]), a.puzzle);
});

test('every bundled fire is valid, unique and needs no guessing', () => {
  assert.equal(campaign.length, CAMPAIGN.length);
  campaign.forEach((row, k) => {
    const p = unpack(row);
    assert.equal(p.rows, CAMPAIGN[k].rows);
    assert.equal(p.breaks, CAMPAIGN[k].breaks);
    check(p);
  });
  for (const row of daily.fires) check(unpack(row));
});

test('a hint fixes a wrong firebreak first, then adds a missing one', async () => {
  const p = unpack(campaign[0]);
  const marks = new Array(p.rows * p.cols).fill(0);
  const wrong = marks.findIndex((_, i) => i !== p.spark && !p.solution.includes(i) && !p.clues.some((c) => c[0] === i));
  marks[wrong] = 1;
  assert.deepEqual(hintMove(p, marks), { cell: wrong, mark: 0 });
  marks[wrong] = 0;
  const h = hintMove(p, marks);
  assert.ok(h && p.solution.includes(h.cell) && h.mark === 1);
  const solved = marks.slice();
  for (const i of p.solution) solved[i] = 1;
  assert.ok(solvedTimes(p, solved));
});
