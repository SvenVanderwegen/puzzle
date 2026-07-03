// Production budget gate (ADR-0009 / playbook §5 gate 7, WS-09 scope):
//   1. initial JS (entry + preloaded chunks referenced by index.html)
//      must be <= 200 KB gzipped;
//   2. the built output makes ZERO third-party requests — every URL string
//      in dist/ is either a burnfront.com origin or a known-inert library
//      string (namespaces / console-message links, never fetched);
//   3. the PWA shell is precached (sw.js exists and includes index.html).
// Builds first, then measures. Exits non-zero on any violation.
import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { gzipSync } from 'node:zlib';

const pkgDir = dirname(dirname(fileURLToPath(import.meta.url)));
const dist = join(pkgDir, 'dist');
const BUDGET_BYTES = 200 * 1024;

// Inert URL strings shipped inside allowlisted libraries — grep-verified to
// be message text / XML namespaces, not request targets:
//   www.w3.org        SVG/XML namespace identifiers
//   react.dev         React error-decoder links inside error MESSAGES
//   bit.ly/wb-precache  workbox console.warn text (inlined runtime)
//   localhost         TanStack Router origin fallback for non-browser contexts
const INERT_URL_HOSTS = ['www.w3.org', 'react.dev', 'bit.ly', 'localhost'];

function walk(dir) {
  const files = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) files.push(...walk(path));
    else files.push(path);
  }
  return files;
}

process.stdout.write('Building apps/web…\n');
execFileSync('pnpm', ['exec', 'vite', 'build'], {
  cwd: pkgDir,
  stdio: ['ignore', 'ignore', 'inherit'],
});

let failed = false;

// ---- 1. initial-JS budget -------------------------------------------------
const html = readFileSync(join(dist, 'index.html'), 'utf8');
const initialJs = new Set();
for (const match of html.matchAll(/(?:src|href)="\/?([^"]+\.js)"/g)) {
  initialJs.add(match[1]);
}
let totalGz = 0;
for (const file of initialJs) {
  const path = join(dist, file);
  const gz = gzipSync(readFileSync(path), { level: 9 }).length;
  totalGz += gz;
  process.stdout.write(`  ${file}: ${String(statSync(path).size)} B raw, ${String(gz)} B gz\n`);
}
const kb = (totalGz / 1024).toFixed(2);
if (initialJs.size === 0) {
  process.stderr.write('BUDGET FAIL: no initial JS found in dist/index.html\n');
  failed = true;
} else if (totalGz > BUDGET_BYTES) {
  process.stderr.write(`BUDGET FAIL: initial JS ${kb} KB gz > 200 KB gz (ADR-0009)\n`);
  failed = true;
} else {
  process.stdout.write(`BUDGET OK: initial JS ${kb} KB gz <= 200 KB gz\n`);
}

// ---- 2. zero third-party requests ------------------------------------------
const urlPattern = /https?:\/\/([a-zA-Z0-9.-]+)/g;
for (const file of walk(dist)) {
  const content = readFileSync(file, 'utf8');
  for (const match of content.matchAll(urlPattern)) {
    const host = match[1];
    const allowed =
      host === 'burnfront.com' || host.endsWith('.burnfront.com') || INERT_URL_HOSTS.includes(host);
    if (!allowed) {
      process.stderr.write(
        `THIRD-PARTY FAIL: ${relative(dist, file)} references ${String(match[0])}\n`,
      );
      failed = true;
    }
  }
}
if (!failed) process.stdout.write('THIRD-PARTY OK: no non-burnfront request origins in dist\n');

// ---- 3. PWA shell precached -------------------------------------------------
const swPath = join(dist, 'sw.js');
if (!existsSync(swPath)) {
  process.stderr.write('PWA FAIL: dist/sw.js missing\n');
  failed = true;
} else if (!readFileSync(swPath, 'utf8').includes('index.html')) {
  process.stderr.write('PWA FAIL: sw.js does not precache index.html\n');
  failed = true;
} else {
  process.stdout.write('PWA OK: sw.js precaches the shell\n');
}

process.exit(failed ? 1 : 0);
