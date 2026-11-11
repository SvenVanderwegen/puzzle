/**
 * Builds the print files of one Burnfront puzzle book for Amazon KDP.
 *
 * Run: node book/build.mjs [volume] [outDir]
 * Needs book/data/volume-<n>.json (see generate.ts) and Playwright with Chromium.
 * Writes:
 *   burnfront-book-<n>-interior.pdf  A5 pages, no bleed, one ink
 *   burnfront-book-<n>-cover.pdf     back + spine + front in one sheet, with bleed
 *
 * KDP paperback numbers used here (5.83 x 8.27 in trim, black ink on white paper):
 *   interior  page = trim size; inside margin 0.375 in or more up to 150 pages,
 *             outside margins 0.25 in or more
 *   cover     0.125 in bleed on every side; spine = pages x 0.002252 in;
 *             spine text only from 79 pages, 0.0625 in clear on both sides;
 *             barcode box 2 x 1.2 in, bottom right of the back, 0.25 in from the edges
 */
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const here = dirname(fileURLToPath(import.meta.url));
const volume = Number(process.argv[2] ?? 1);
const outDir = resolve(process.argv[3] ?? join(here, 'out'));
const book = JSON.parse(readFileSync(join(here, 'data', `volume-${volume}.json`), 'utf8'));
const puzzles = book.puzzles;

/* ---------- print numbers ---------- */
const TRIM_W = 5.83;
const TRIM_H = 8.27;
const BLEED = 0.125;
const SPINE_PER_PAGE = 0.002252;
const INSIDE = 0.65;
const OUTSIDE = 0.5;
const ANSWERS_PER_PAGE = 12;

/* ---------- page plan ---------- */
const FIRST_PUZZLE_PAGE = 7;
const answerPages = Math.ceil(puzzles.length / ANSWERS_PER_PAGE);
const FIRST_ANSWER_PAGE = FIRST_PUZZLE_PAGE + puzzles.length;
const answerPageOf = (n) => FIRST_ANSWER_PAGE + Math.floor((n - 1) / ANSWERS_PER_PAGE);
let pageCount = FIRST_ANSWER_PAGE + answerPages; /* + the closing page */
if (pageCount % 2) pageCount += 1;

const WORDS = ['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight'];
const volumeWord = WORDS[volume - 1] ?? String(volume);

/* Each volume slides the three squares of the mark one step down the heat ramp. */
const COVER_SETS = [
  { small: '#FFE24A', mid: '#FF7A1F', big: '#CC2162', midText: '#16181B' },
  { small: '#FFC12E', mid: '#EE3F38', big: '#972188', midText: '#16181B' },
  { small: '#FF9A1F', mid: '#CC2162', big: '#63278F', midText: '#FFFFFF' },
  { small: '#FF6F27', mid: '#972188', big: '#3A2B7C', midText: '#FFFFFF' },
];
const coverSet = COVER_SETS[(volume - 1) % COVER_SETS.length];

/* ---------- boards ---------- */
const STAR = 'M12 1.5 14.6 9.4 22.5 12 14.6 14.6 12 22.5 9.4 14.6 1.5 12 9.4 9.4Z';
const star = (size) => `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="#16181B"><path d="${STAR}"/></svg>`;

function burn(rows, cols, spark, breaks) {
  const n = rows * cols;
  const blocked = new Set(breaks);
  const dist = new Array(n).fill(-1);
  const queue = [spark];
  dist[spark] = 0;
  for (let h = 0; h < queue.length; h++) {
    const x = queue[h];
    const r = Math.floor(x / cols);
    const c = x % cols;
    const next = [];
    if (r > 0) next.push(x - cols);
    if (r < rows - 1) next.push(x + cols);
    if (c > 0) next.push(x - 1);
    if (c < cols - 1) next.push(x + 1);
    for (const y of next) {
      if (dist[y] < 0 && !blocked.has(y)) {
        dist[y] = dist[x] + 1;
        queue.push(y);
      }
    }
  }
  return dist;
}

/**
 * One ink. Colour is time in the game; on paper a later minute is a darker grey.
 * mode: 'puzzle' (numbers only), 'solved' (firebreaks and every minute), 'answer' (firebreaks only).
 */
function board(p, { cell, gap = 3, mode = 'puzzle', font = Math.round(cell * 0.46) }) {
  const clues = new Map(p.clues);
  const breaks = mode === 'puzzle' ? [] : p.solution;
  const dist = burn(p.rows, p.cols, p.spark, p.solution);
  const last = Math.max(...dist);
  const grey = (minute) => {
    const v = Math.round(236 - 58 * (last ? minute / last : 0));
    return `rgb(${v},${v},${v - 4})`;
  };
  const cells = [];
  for (let i = 0; i < p.rows * p.cols; i++) {
    if (i === p.spark) {
      cells.push(`<i class="c given" style="background:${grey(0)}">${star(Math.round(cell * 0.5))}</i>`);
    } else if (breaks.includes(i)) {
      cells.push('<i class="c break"></i>');
    } else if (clues.has(i)) {
      const label = mode === 'answer' ? '' : clues.get(i);
      cells.push(`<i class="c given" style="background:${grey(clues.get(i))}">${label}</i>`);
    } else if (mode === 'solved') {
      cells.push(`<i class="c open worked">${dist[i]}</i>`);
    } else {
      cells.push('<i class="c open"></i>');
    }
  }
  const width = p.cols * cell + (p.cols - 1) * gap;
  return `<div class="board" style="grid-template-columns:repeat(${p.cols},${cell}px);gap:${gap}px;width:${width}px;font-size:${font}px;--cell:${cell}px">${cells.join('')}</div>`;
}

const boxes = (count, size = 18) =>
  `<span class="boxes">${Array.from({ length: count }, () => `<b style="width:${size}px;height:${size}px"></b>`).join('')}</span>`;

/* ---------- pages ---------- */
let pageNo = 0;
function page(body, { footer = '', bare = false } = {}) {
  pageNo += 1;
  const side = pageNo % 2 ? 'right' : 'left';
  const foot = bare
    ? ''
    : `<footer><span class="no">${pageNo}</span><span class="mark">burnfront</span><span class="where">${footer}</span></footer>`;
  return `<section class="page ${side}">${body}${foot}</section>`;
}

const LOGO_ONE_INK = `<svg width="64" height="64" viewBox="0 0 60 60"><clipPath id="lg"><rect width="60" height="60" rx="9"/></clipPath><g clip-path="url(#lg)"><rect width="60" height="60" fill="#16181B"/><rect y="20" width="40" height="40" fill="#8A8D92"/><rect y="40" width="20" height="20" fill="#FFFFFF"/></g></svg>`;

const LESSON = { rows: 4, cols: 4, spark: 12, clues: [[4, 2], [14, 6]], solution: [9, 13] };
const WARM_A = { rows: 4, cols: 4, spark: 6, clues: [[0, 7], [2, 1]], solution: [1, 5, 9] };
const WARM_B = { rows: 5, cols: 5, spark: 17, clues: [[2, 5], [3, 6], [13, 2], [15, 4]], solution: [7, 8, 16] };

function titlePage() {
  const row = (label, no) => `<div><span>${label}</span><span class="mono">${no}</span></div>`;
  return page(
    `<div class="title">
      ${LOGO_ONE_INK}
      <h1 class="wordmark">burnfront</h1>
      <p class="sub">${puzzles.length} wildfire logic puzzles</p>
      <p class="mono caps">Book ${volumeWord}</p>
      <nav>
        ${row('How it works', 3)}
        ${row('Your first puzzle, step by step', 4)}
        ${row('Now you try', 5)}
        ${row('How to use this book', 6)}
        ${row('The puzzles', FIRST_PUZZLE_PAGE)}
        ${row('Answers', FIRST_ANSWER_PAGE)}
      </nav>
    </div>`,
    { bare: true },
  );
}

function imprintPage() {
  return page(
    `<div class="imprint">
      <p><strong>Burnfront, book ${volumeWord}</strong><br>${puzzles.length} wildfire logic puzzles</p>
      <p>Every puzzle in this book has exactly one answer, and it can be found without guessing.</p>
      <p>Copyright &copy; [YEAR] [PUBLISHER]. All rights reserved. No part of this book may be copied or shared without written permission.</p>
      <p>ISBN [ISBN]</p>
      <p>A new puzzle every day at burnfront.com</p>
    </div>`,
    { bare: true },
  );
}

function rulesPage() {
  const rule = (b, title, text) => `<div class="rule">${b}<div><h2>${title}</h2><p>${text}</p></div></div>`;
  const open = { ...LESSON, clues: [], solution: [] };
  const blocked = { ...LESSON, clues: [], solution: [8] };
  return page(
    `<h1>How it works</h1>
    <p class="lead">A fire burned through the forest. Crews dug firebreaks to slow it down. All that is left is a few numbers. Your job is to find where the firebreaks were.</p>
    <div class="rules">
      ${rule(board(open, { cell: 30, gap: 2, mode: 'solved', font: 15 }), '1. The fire starts at the star', 'It spreads one square a minute: up, down, left and right. Never diagonally. Each number is the minute the fire arrived.')}
      ${rule(board(blocked, { cell: 30, gap: 2, mode: 'solved', font: 15 }), '2. A firebreak blocks it', 'Fire cannot cross a black square. It has to go around. The square above the firebreak now burns at minute 4 instead of 2.')}
      ${rule(board(LESSON, { cell: 30, gap: 2, font: 15 }), '3. You find the firebreaks', 'A puzzle shows only a few numbers. Shade the firebreaks so that every number is right. Every square you leave open must burn.')}
    </div>`,
    { footer: 'How it works' },
  );
}

function examplePage() {
  const step = (n, text) => `<li><b>${n}</b><span>${text}</span></li>`;
  return page(
    `<h1>Your first puzzle</h1>
    <p class="lead">This small puzzle has 2 firebreaks. Read along, then check the answer on the right.</p>
    <div class="pair">
      <div><span class="mono caps">The puzzle</span>${board(LESSON, { cell: 42 })}<span class="shade">Shade 2 firebreaks ${boxes(2)}</span></div>
      <div><span class="mono caps">The answer</span>${board(LESSON, { cell: 42, mode: 'solved' })}<span class="note">Every minute filled in</span></div>
    </div>
    <ol class="steps">
      ${step(1, '<strong>Count.</strong> The 6 is 2 squares from the star, yet it burned at minute 6. The short way was blocked.')}
      ${step(2, '<strong>Keep the 2.</strong> It is 2 squares up and burned at minute 2. The way up is open.')}
      ${step(3, '<strong>Block the short way.</strong> Shade the square right of the star and the one above it.')}
      ${step(4, '<strong>Check.</strong> The 6 burns at minute 6, the 2 at minute 2, and every open square burns. Solved.')}
    </ol>`,
    { footer: 'Your first puzzle' },
  );
}

function tryPage() {
  return page(
    `<h1>Now you try</h1>
    <p class="lead">Two warm-ups before the book begins. Cover the answers at the bottom first.</p>
    <div class="pair">
      <div><span class="mono caps">Warm-up A</span>${board(WARM_A, { cell: 42 })}<span class="shade">Shade 3 ${boxes(3)}</span></div>
      <div><span class="mono caps">Warm-up B</span>${board(WARM_B, { cell: 38 })}<span class="shade">Shade 3 ${boxes(3)}</span></div>
    </div>
    <p class="tip"><strong>Stuck?</strong> If a number is bigger than its distance from the star, a firebreak blocked the short way.</p>
    <div class="warm-answers">
      <div class="grow"><span class="mono caps">Answers</span><span class="note">The black squares are the firebreaks.</span></div>
      <div class="mini">${board(WARM_A, { cell: 20, gap: 2, mode: 'solved', font: 11 })}<strong>A</strong></div>
      <div class="mini">${board(WARM_B, { cell: 20, gap: 2, mode: 'solved', font: 11 })}<strong>B</strong></div>
    </div>`,
    { footer: 'Now you try' },
  );
}

function usePage() {
  const mark = (inner, cls = 'open') => `<i class="c ${cls} key">${inner}</i>`;
  const row = (m, text) => `<li>${m}<span>${text}</span></li>`;
  return page(
    `<h1>How to use this book</h1>
    <p class="lead">One puzzle on every page. They start small and easy and grow as you go. All you need is a pencil and an eraser.</p>
    <section class="block ruled">
      <h2>What you see</h2>
      <ul class="keys">
        ${row(mark(star(22), 'given'), '<strong>The star.</strong> The fire starts here, at minute 0.')}
        ${row(mark('7', 'given'), '<strong>A number.</strong> The fire arrived here at that minute. A darker square burned later.')}
      </ul>
    </section>
    <section class="block">
      <h2>What you draw</h2>
      <ul class="keys">
        ${row(mark('', 'break'), '<strong>Shade a firebreak.</strong> Tick a box under the puzzle for each one, so you know how many are left.')}
        ${row(mark('<u></u>'), '<strong>Put a dot</strong> in a square you are sure is open.')}
        ${row(mark('4', 'open worked'), '<strong>Write small minutes</strong> in open squares if it helps you count.')}
      </ul>
    </section>
    <section class="tip">
      <h2>Before you look at the answer</h2>
      <p>Is every number right? Does every open square burn? Did you use all the firebreaks? Then it is solved. The answers start on page ${FIRST_ANSWER_PAGE}.</p>
    </section>`,
    { footer: 'How to use this book' },
  );
}

function puzzlePage(p) {
  const cell = Math.min(84, Math.floor((449 - (p.cols - 1) * 4) / p.cols));
  return page(
    `<header class="phead">
      <span class="num">${p.n}</span>
      <span class="mono caps">${p.level} &middot; ${p.rows}&times;${p.cols}</span>
    </header>
    <div class="pboard">${board(p, { cell, gap: 4 })}</div>
    <div class="pfoot">
      <span class="shade">Shade ${p.breaks} firebreaks</span>
      ${boxes(p.breaks, p.breaks > 11 ? 17 : 20)}
    </div>`,
    { footer: `Answer on page ${answerPageOf(p.n)}` },
  );
}

function answersPage(group, first) {
  const items = group
    .map((p) => {
      const cell = Math.floor((108 - (p.cols - 1) * 2) / p.cols);
      return `<div class="ans"><strong>${p.n}</strong>${board(p, { cell, gap: 2, mode: 'answer' })}</div>`;
    })
    .join('');
  const head = first
    ? '<h1>Answers</h1><p class="lead">The black squares are the firebreaks.</p>'
    : '';
  return page(`${head}<div class="answers${first ? ' first' : ''}">${items}</div>`, { footer: 'Answers' });
}

function closingPage() {
  return page(
    `<div class="closing">
      ${LOGO_ONE_INK}
      <h1>Contained.</h1>
      <p class="lead">You finished the book. There is a new puzzle every day at burnfront.com, free to play.</p>
    </div>`,
    { bare: true },
  );
}

const FONTS =
  '<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700;12..96,800&family=Geist:wght@400;500;600&family=Geist+Mono:wght@500&display=swap" rel="stylesheet">';

const INTERIOR_CSS = `
@page { size: ${TRIM_W}in ${TRIM_H}in; margin: 0; }
* { box-sizing: border-box; }
html, body { margin: 0; }
body { font-family: Geist, system-ui, sans-serif; color: #16181B; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.page { width: ${TRIM_W}in; height: ${TRIM_H}in; padding: 0.55in ${OUTSIDE}in 0.45in ${INSIDE}in; display: flex; flex-direction: column; overflow: hidden; break-after: page; background: #FFFFFF; }
.page.left { padding-left: ${OUTSIDE}in; padding-right: ${INSIDE}in; }
h1, h2, p, ol, ul { margin: 0; padding: 0; }
ol, ul { list-style: none; }
h1 { font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 42px; line-height: 1; letter-spacing: -0.035em; }
h2 { font-family: 'Bricolage Grotesque', sans-serif; font-weight: 700; font-size: 21px; line-height: 1.15; letter-spacing: -0.02em; }
.lead { margin-top: 14px; font-size: 17px; line-height: 1.45; text-wrap: pretty; }
.mono { font-family: 'Geist Mono', monospace; font-weight: 500; }
.caps { font-size: 13px; letter-spacing: 0.08em; text-transform: uppercase; }
.note { font-size: 15px; line-height: 1.4; color: #55585E; }
footer { margin-top: auto; display: flex; align-items: center; justify-content: space-between; gap: 16px; font-size: 12px; }
footer .no { font-family: 'Geist Mono', monospace; font-weight: 500; font-size: 13px; }
footer .mark { font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 14px; letter-spacing: -0.03em; }
footer .where { color: #55585E; min-width: 120px; text-align: right; }
.page.left footer { flex-direction: row; }
.page.right footer { flex-direction: row-reverse; }
.page.right footer .where { text-align: left; }

.board { display: grid; flex-shrink: 0; font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; }
.c { display: flex; align-items: center; justify-content: center; height: var(--cell); border-radius: 3px; font-style: normal; }
.c.open { background: #FFFFFF; box-shadow: inset 0 0 0 1.25px #16181B; }
.c.given { box-shadow: inset 0 0 0 1.25px #16181B; }
.c.break { background: #16181B; }
.c.worked { font-weight: 500; color: #55585E; }
.c.key { --cell: 40px; width: 40px; flex-shrink: 0; background: #E4E4DF; font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 20px; }
.c.key.open { background: #FFFFFF; }
.c.key.break { background: #16181B; }
.c.key.worked { font-size: 17px; }
.c.key u { width: 8px; height: 8px; border-radius: 4px; background: #16181B; }
.boxes { display: inline-flex; flex-wrap: wrap; gap: 5px; }
.boxes b { box-shadow: inset 0 0 0 1.5px #16181B; border-radius: 2px; }
.shade { display: flex; align-items: center; gap: 10px; flex-shrink: 0; white-space: nowrap; font-size: 16px; font-weight: 600; }

.title { display: flex; flex-direction: column; height: 100%; padding-top: 0.2in; }
.wordmark { margin-top: 28px; font-size: 92px; line-height: 0.95; letter-spacing: -0.045em; }
.title .sub { margin-top: 12px; font-family: 'Bricolage Grotesque', sans-serif; font-weight: 700; font-size: 28px; letter-spacing: -0.015em; }
.title .caps { margin-top: 10px; font-size: 14px; letter-spacing: 0.1em; }
.title nav { margin-top: auto; border-top: 2px solid #16181B; }
.title nav div { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #16181B; font-size: 18px; }
.imprint { margin-top: auto; display: flex; flex-direction: column; gap: 14px; font-size: 13px; line-height: 1.5; max-width: 320px; }
.closing { margin: auto 0; display: flex; flex-direction: column; gap: 6px; }
.closing h1 { margin-top: 24px; font-size: 64px; }

.rules { margin-top: 22px; padding-top: 22px; display: flex; flex-direction: column; gap: 22px; border-top: 2px solid #16181B; }
.rule { display: flex; align-items: flex-start; gap: 22px; }
.rule div:last-child { display: flex; flex-direction: column; gap: 6px; }
.rule p, .steps li, .keys li, .tip p { font-size: 16px; line-height: 1.4; text-wrap: pretty; }
.pair { margin-top: 20px; display: flex; justify-content: space-between; align-items: flex-start; }
.pair > div { display: flex; flex-direction: column; gap: 10px; }
.steps { margin-top: 22px; padding-top: 20px; display: flex; flex-direction: column; gap: 12px; border-top: 2px solid #16181B; }
.steps li { display: flex; gap: 14px; }
.steps b { flex-shrink: 0; display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 14px; background: #16181B; color: #FFFFFF; font-family: 'Bricolage Grotesque', sans-serif; font-weight: 700; font-size: 16px; }
.tip { margin-top: 22px; padding: 14px 16px; background: #EDEDE9; border-radius: 4px; display: flex; flex-direction: column; gap: 6px; font-size: 16px; line-height: 1.4; }
.warm-answers { margin-top: 22px; padding-top: 16px; display: flex; align-items: flex-start; gap: 24px; border-top: 2px solid #16181B; }
.warm-answers .grow { flex-grow: 1; display: flex; flex-direction: column; gap: 4px; }
.mini { display: flex; flex-direction: column; align-items: center; gap: 6px; font-size: 13px; }
.block { margin-top: 20px; display: flex; flex-direction: column; gap: 12px; }
.block.ruled { margin-top: 22px; padding-top: 18px; border-top: 2px solid #16181B; }
.keys { display: flex; flex-direction: column; gap: 8px; }
.keys li { display: flex; align-items: center; gap: 14px; }

.phead { display: flex; align-items: flex-end; justify-content: space-between; padding-bottom: 10px; border-bottom: 2px solid #16181B; }
.phead .num { font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 60px; line-height: 0.85; letter-spacing: -0.04em; }
.phead .caps { padding-bottom: 2px; }
.pboard { margin-top: 26px; display: flex; justify-content: center; }
.pfoot { margin-top: 22px; display: flex; align-items: center; gap: 12px; }

.answers { display: grid; grid-template-columns: repeat(3, 108px); justify-content: space-between; row-gap: 16px; }
.answers.first { margin-top: 22px; }
.ans { display: flex; flex-direction: column; gap: 5px; font-family: 'Bricolage Grotesque', sans-serif; font-size: 15px; }
.ans .c { border-radius: 1.5px; }
.ans .c.open, .ans .c.given { box-shadow: inset 0 0 0 0.75px #16181B; }
.ans svg { width: 70%; height: 70%; }
`;

function interiorHtml() {
  pageNo = 0;
  const pages = [titlePage(), imprintPage(), rulesPage(), examplePage(), tryPage(), usePage()];
  if (pageNo + 1 !== FIRST_PUZZLE_PAGE) throw new Error('the front matter no longer ends where the puzzles start');
  for (const p of puzzles) pages.push(puzzlePage(p));
  for (let k = 0; k < puzzles.length; k += ANSWERS_PER_PAGE) {
    pages.push(answersPage(puzzles.slice(k, k + ANSWERS_PER_PAGE), k === 0));
  }
  pages.push(closingPage());
  while (pageNo < pageCount) pages.push(page('', { bare: true }));
  if (pageNo !== pageCount) throw new Error(`planned ${pageCount} pages, made ${pageNo}`);
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Burnfront, book ${volumeWord}</title>${FONTS}<style>${INTERIOR_CSS}</style></head><body>${pages.join('\n')}</body></html>`;
}

/* ---------- cover ---------- */
function coverHtml() {
  const spine = pageCount * SPINE_PER_PAGE;
  const width = BLEED * 2 + TRIM_W * 2 + spine;
  const height = BLEED * 2 + TRIM_H;
  const spineText = pageCount >= 79;
  const sample = puzzles.find((p) => p.rows === 6) ?? puzzles[0];
  const colour = (minute, last) => {
    const stops = [[0, [255, 226, 74]], [0.14, [255, 193, 46]], [0.28, [255, 154, 31]], [0.42, [255, 111, 39]], [0.56, [238, 63, 56]], [0.7, [204, 33, 98]], [0.82, [151, 33, 136]], [0.92, [99, 39, 143]], [1, [58, 43, 124]]];
    const t = Math.max(0, Math.min(1, minute / last));
    let rgb = stops[stops.length - 1][1];
    for (let k = 1; k < stops.length; k++) {
      if (t <= stops[k][0]) {
        const a = stops[k - 1];
        const b = stops[k];
        const u = (t - a[0]) / (b[0] - a[0]);
        rgb = [0, 1, 2].map((j) => Math.round(a[1][j] + (b[1][j] - a[1][j]) * u));
        break;
      }
    }
    const lin = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    const lum = 0.2126 * lin(rgb[0]) + 0.7152 * lin(rgb[1]) + 0.0722 * lin(rgb[2]);
    return { bg: `rgb(${rgb.join(',')})`, fg: lum >= 0.199 ? '#16181B' : '#FFFFFF' };
  };
  const last = Math.max(...burn(sample.rows, sample.cols, sample.spark, sample.solution));
  const clues = new Map(sample.clues);
  const cells = [];
  for (let i = 0; i < sample.rows * sample.cols; i++) {
    if (i === sample.spark) cells.push(`<i class="k" style="background:#FFE24A">${star(17)}</i>`);
    else if (clues.has(i)) {
      const c = colour(clues.get(i), last);
      cells.push(`<i class="k" style="background:${c.bg};color:${c.fg}">${clues.get(i)}</i>`);
    } else cells.push('<i class="k blank"></i>');
  }
  const ruleDot = (n, bg, fg = '#16181B') => `<b style="background:${bg};color:${fg}">${n}</b>`;
  const logo = `<svg width="28" height="28" viewBox="0 0 60 60"><clipPath id="cl"><rect width="60" height="60" rx="9"/></clipPath><g clip-path="url(#cl)"><rect width="60" height="60" fill="#CC2162"/><rect y="20" width="40" height="40" fill="#FF7A1F"/><rect y="40" width="20" height="20" fill="#FFE24A"/></g></svg>`;

  const css = `
@page { size: ${width}in ${height}in; margin: 0; }
* { box-sizing: border-box; }
html, body { margin: 0; }
body { width: ${width}in; height: ${height}in; position: relative; overflow: hidden; font-family: Geist, system-ui, sans-serif; color: #16181B; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
h1, h2, p, ol, ul { margin: 0; padding: 0; } ol, ul { list-style: none; }
.back { position: absolute; left: 0; top: 0; width: ${BLEED + TRIM_W}in; height: 100%; background: #F1F1EE; padding: ${BLEED + 0.5}in 0.5in ${BLEED + 0.25}in ${BLEED + 0.5}in; display: flex; flex-direction: column; }
.spine { position: absolute; left: ${BLEED + TRIM_W}in; top: 0; width: ${spine}in; height: 100%; background: ${coverSet.big}; }
.spine .label { position: absolute; left: 50%; top: ${BLEED + 0.5}in; transform-origin: 0 0; transform: rotate(90deg) translateY(-50%); white-space: nowrap; color: #FFFFFF; font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 11px; line-height: 1; letter-spacing: -0.01em; }
.spine .label span { margin-left: 14px; font-family: 'Geist Mono', monospace; font-weight: 500; font-size: 8px; letter-spacing: 0.08em; text-transform: uppercase; }
.spine .band { position: absolute; left: 0; bottom: 0; width: 100%; height: ${3.89 + BLEED}in; background: ${coverSet.mid}; }
.spine .foot { position: absolute; left: 0; bottom: 0; width: 100%; height: ${1.94 + BLEED}in; background: ${coverSet.small}; }
.spine .foot b { position: absolute; left: 0; width: 100%; bottom: ${BLEED + 0.9}in; text-align: center; font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 12px; line-height: 1; }
.front { position: absolute; left: ${BLEED + TRIM_W + spine}in; top: 0; width: ${TRIM_W + BLEED}in; height: 100%; background: ${coverSet.big}; color: #FFFFFF; overflow: hidden; }
.front .mid { position: absolute; left: 0; bottom: 0; width: 3.89in; height: ${3.89 + BLEED}in; background: ${coverSet.mid}; }
.front .small { position: absolute; left: 0; bottom: 0; width: 1.94in; height: ${1.94 + BLEED}in; padding-bottom: ${BLEED}in; background: ${coverSet.small}; color: #16181B; display: flex; align-items: center; justify-content: center; font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 132px; line-height: 1; letter-spacing: -0.05em; }
.front .head { position: absolute; left: 0.5in; top: ${BLEED + 0.5}in; display: flex; flex-direction: column; gap: 10px; }
.front .book { font-family: 'Geist Mono', monospace; font-weight: 500; font-size: 14px; letter-spacing: 0.1em; text-transform: uppercase; }
.front h1 { font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 96px; line-height: 0.95; letter-spacing: -0.045em; }
.front .sub { font-family: 'Bricolage Grotesque', sans-serif; font-weight: 700; font-size: 28px; line-height: 1.15; letter-spacing: -0.015em; }
.front .pitch { position: absolute; left: 0.42in; bottom: ${BLEED + 2.72}in; width: 290px; color: ${coverSet.midText}; font-size: 19px; font-weight: 500; line-height: 1.4; }
.front .promise { position: absolute; left: 4.12in; bottom: ${BLEED + 0.42}in; width: 124px; font-family: 'Geist Mono', monospace; font-weight: 500; font-size: 13px; line-height: 1.5; letter-spacing: 0.06em; text-transform: uppercase; }
.back h2 { font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 36px; line-height: 1; letter-spacing: -0.035em; text-wrap: balance; }
.back .lead { margin-top: 14px; font-size: 17px; line-height: 1.45; text-wrap: pretty; }
.back ol { margin-top: 18px; padding-top: 16px; display: flex; flex-direction: column; gap: 10px; border-top: 1.5px solid #16181B; }
.back ol li { display: flex; align-items: center; gap: 14px; font-size: 17px; line-height: 1.3; }
.back ol b { flex-shrink: 0; display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 4px; font-family: 'Bricolage Grotesque', sans-serif; font-weight: 700; font-size: 18px; }
.try { margin-top: 22px; display: flex; gap: 22px; align-items: flex-start; }
.grid { flex-shrink: 0; display: grid; grid-template-columns: repeat(${sample.cols}, 33px); gap: 3px; font-family: 'Bricolage Grotesque', sans-serif; font-weight: 700; font-size: 16px; }
.k { height: 33px; display: flex; align-items: center; justify-content: center; border-radius: 3px; font-style: normal; }
.k.blank { background: #FFFFFF; box-shadow: inset 0 0 0 1px #9A9A94; }
.try div:last-child { display: flex; flex-direction: column; gap: 10px; }
.try strong { font-family: 'Bricolage Grotesque', sans-serif; font-weight: 700; font-size: 21px; letter-spacing: -0.02em; }
.try span { font-size: 16px; line-height: 1.4; color: #55585E; }
.try ul { margin-top: 4px; display: flex; flex-direction: column; gap: 6px; font-size: 16px; font-weight: 500; }
.bottom { margin-top: auto; display: flex; align-items: flex-end; justify-content: space-between; }
.brand { display: flex; flex-direction: column; gap: 8px; padding-bottom: 0.1in; }
.brand div { display: flex; align-items: center; gap: 8px; font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; font-size: 26px; letter-spacing: -0.035em; }
.brand span { max-width: 200px; font-size: 15px; line-height: 1.4; color: #55585E; }
.barcode { position: absolute; right: 0.25in; bottom: ${BLEED + 0.25}in; width: 2in; height: 1.2in; background: #FFFFFF; }
`;
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Burnfront, book ${volumeWord}, cover</title>${FONTS}<style>${css}</style></head><body>
<div class="back">
  <h2>The fire is out. Where were the firebreaks?</h2>
  <p class="lead">Each puzzle shows a forest after a fire. A number tells you the minute the fire reached that square. Work out where the firebreaks stood. There is one answer, and you never have to guess.</p>
  <ol>
    <li>${ruleDot(1, '#FFE24A')}<span>Fire spreads one square a minute, never diagonally.</span></li>
    <li>${ruleDot(2, '#FF8A22')}<span>It cannot cross a firebreak. It has to go around.</span></li>
    <li>${ruleDot(3, '#CC2162', '#FFFFFF')}<span>Shade the firebreaks. Every other square burns.</span></li>
  </ol>
  <div class="try">
    <div class="grid">${cells.join('')}</div>
    <div>
      <strong>Try this one.</strong>
      <span>${sample.breaks} firebreaks. All you need is a pencil.</span>
      <ul><li>${puzzles.length} puzzles, easy to hard</li><li>Large, clear print</li><li>Answers at the back</li></ul>
    </div>
  </div>
  <div class="bottom">
    <div class="brand"><div>${logo}burnfront</div><span>A new puzzle every day at burnfront.com</span></div>
  </div>
  <div class="barcode"></div>
</div>
<div class="spine">${spineText ? `<div class="label">burnfront<span>${puzzles.length} puzzles</span></div>` : ''}<div class="band"></div><div class="foot">${spineText ? `<b>${volume}</b>` : ''}</div></div>
<div class="front">
  <div class="mid"></div>
  <div class="small">${volume}</div>
  <div class="head"><span class="book">Book ${volumeWord}</span><h1>burnfront</h1><p class="sub">${puzzles.length} wildfire logic puzzles</p></div>
  <p class="pitch">Fire spreads one square a minute. Work out where they stopped it.</p>
  <p class="promise">One answer. No guessing.</p>
</div>
</body></html>`;
}

/* ---------- render ---------- */
mkdirSync(outDir, { recursive: true });
const browser = await chromium.launch();
const tab = await browser.newPage();

async function pdf(html, name, width, height) {
  const file = join(outDir, name);
  writeFileSync(file.replace(/\.pdf$/, '.html'), html);
  await tab.setContent(html, { waitUntil: 'networkidle' });
  await tab.evaluate(() => document.fonts.ready);
  await tab.pdf({ path: file, width: `${width}in`, height: `${height}in`, printBackground: true, preferCSSPageSize: true });
  return file;
}

const spineWidth = pageCount * SPINE_PER_PAGE;
const interior = await pdf(interiorHtml(), `burnfront-book-${volume}-interior.pdf`, TRIM_W, TRIM_H);
const cover = await pdf(coverHtml(), `burnfront-book-${volume}-cover.pdf`, BLEED * 2 + TRIM_W * 2 + spineWidth, BLEED * 2 + TRIM_H);
await browser.close();

console.log(`interior: ${interior} (${pageCount} pages)`);
console.log(`cover:    ${cover} (spine ${spineWidth.toFixed(3)} in)`);
