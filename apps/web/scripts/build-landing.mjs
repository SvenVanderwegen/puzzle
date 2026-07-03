// build-landing.mjs — builds the WS-15 landing artifacts committed under
// api/resources/landing/ (Blade owns `/` per ADR-0009; Laravel serves these):
//
//   hero.js    ONE deferred ES module (vite.landing.config.ts) — budget ≤90KB gz
//   board.css  tokens (:root --bf-*) + ui-web component CSS, inlined by Blade
//              as critical CSS (generated from contracts/design-tokens.json
//              via ui-web's tokens.ts — the "shared, not duplicated" bridge)
//   hero.json  the landing hero fixture — vector gen-0014 VERBATIM from
//              contracts/vectors/generate.v1.jsonl (vectors are law)
//   strip.json the replay-strip fixture — vector gen-0049 + per-minute
//              burnRamp colors precomputed with ui-web's burnColor()
//
//   pnpm build:landing   regenerate in place
//   pnpm budget:landing  rebuild to a scratch dir, fail on drift or budget
//                        breach, print the measured gz numbers (CI gate)
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { gzipSync } from 'node:zlib';
import { build, createServer } from 'vite';

const HERO_ID = 'gen-0014'; // Lookout 5×5, 4 breaks, 3 clues, max minute 10
const STRIP_ID = 'gen-0049'; // Hotshot 7×7, 12 breaks, max minute 18
const JS_BUDGET_BYTES = 90 * 1024; // ADR-0009: deferred landing JS ≤ 90KB gz

const appDir = dirname(dirname(fileURLToPath(import.meta.url)));
const repoRoot = resolve(appDir, '..', '..');
const committedDir = join(repoRoot, 'api', 'resources', 'landing');
const check = process.argv.includes('--check');
const outDir = check ? mkdtempSync(join(tmpdir(), 'bf-landing-')) : committedDir;

/** @param {string} id */
function readVector(id) {
  const jsonl = readFileSync(join(repoRoot, 'contracts', 'vectors', 'generate.v1.jsonl'), 'utf8');
  for (const line of jsonl.split('\n')) {
    if (line.trim() === '') continue;
    const entry = JSON.parse(line);
    if (entry.id === id) return entry;
  }
  throw new Error(`vector ${id} not found in contracts/vectors/generate.v1.jsonl`);
}

/** Row-major-sorted {r,c,m} clue list from a vector entry. */
function clues(entry) {
  return [...entry.clues]
    .sort((a, b) => a.r - b.r || a.c - b.c)
    .map(({ r, c, m }) => ({ r, c, m }));
}

// ---- 1. hero.js (vite, single ES module) ----------------------------------
await build({
  configFile: join(appDir, 'vite.landing.config.ts'),
  logLevel: 'warn',
  build: { outDir },
});

// ---- 2. board.css + burnColor via the vite SSR loader ---------------------
// (ui-web is TS source; a dev-server ssrLoadModule is the supported way to
// execute it from a script without a second build.)
const server = await createServer({
  configFile: false,
  root: appDir,
  logLevel: 'warn',
  server: { middlewareMode: true },
});
let boardCss;
let burnColor;
try {
  const mod = await server.ssrLoadModule('/src/landing/boardCss.ts');
  boardCss = mod.landingBoardCss();
  burnColor = mod.burnColor;
} finally {
  await server.close();
}
writeFileSync(join(outDir, 'board.css'), `${boardCss.trim()}\n`);

// ---- 3. fixture JSON from the vectors --------------------------------------
const hero = readVector(HERO_ID);
const heroJson = {
  id: hero.id,
  board: {
    rows: hero.rows,
    cols: hero.cols,
    spark: { r: hero.spark[0], c: hero.spark[1] },
    breaks: hero.breaks,
    clues: clues(hero),
  },
  solution: hero.solution,
  times: hero.times,
};
writeFileSync(join(outDir, 'hero.json'), `${JSON.stringify(heroJson, null, 2)}\n`);

const strip = readVector(STRIP_ID);
const maxMinute = Math.max(...strip.times);
const stripJson = {
  id: strip.id,
  rows: strip.rows,
  cols: strip.cols,
  spark: { r: strip.spark[0], c: strip.spark[1] },
  breaks: strip.breaks,
  clues: clues(strip),
  solution: strip.solution,
  times: strip.times,
  maxMinute,
  // colors[m] = burnRamp background for a cell that burns at minute m.
  colors: Array.from({ length: maxMinute + 1 }, (_, m) => burnColor(m, maxMinute)),
};
writeFileSync(join(outDir, 'strip.json'), `${JSON.stringify(stripJson, null, 2)}\n`);

// ---- 4. measure + (in --check mode) verify ---------------------------------
const artifacts = ['hero.js', 'board.css', 'hero.json', 'strip.json'];
let failed = false;
for (const name of artifacts) {
  const built = readFileSync(join(outDir, name));
  const gz = gzipSync(built, { level: 9 }).length;
  process.stdout.write(
    `  ${name}: ${String(built.length)} B raw, ${String(gz)} B gz (${(gz / 1024).toFixed(2)} KB)\n`,
  );
  if (name === 'hero.js' && gz > JS_BUDGET_BYTES) {
    process.stderr.write(
      `BUDGET FAIL: hero.js ${String(gz)} B gz > ${String(JS_BUDGET_BYTES)} B (ADR-0009 90KB landing budget)\n`,
    );
    failed = true;
  }
  if (check) {
    let committed = null;
    try {
      committed = readFileSync(join(committedDir, name));
    } catch {
      process.stderr.write(`FRESHNESS FAIL: api/resources/landing/${name} is missing\n`);
      failed = true;
    }
    if (committed !== null && !committed.equals(built)) {
      process.stderr.write(
        `FRESHNESS FAIL: api/resources/landing/${name} is stale — run: pnpm --filter @burnfront/web build:landing\n`,
      );
      failed = true;
    }
  }
}
if (check) rmSync(outDir, { recursive: true, force: true });
process.exit(failed ? 1 : 0);
