// Generates src/strings/strings.gen.ts FROM contracts/COPY.md (the frozen
// catalog) so runtime code never parses markdown. `--check` regenerates to a
// temp dir and diffs — the CI freshness gate, same pattern as the vectors
// and api-client generate:check.
//
// COPY.md grammar handled here:
//   - `key` — value                       (one bullet, one key)
//   - `a` — x · `b` — y                   (compact multi-key bullets)
//   - `.2` — y                            (sibling shorthand: previous key's
//                                          last segment replaced)
//   **bold** markers are stripped; ` · ` inside ICU {braces} is preserved
//   (the split only happens before a backticked key).
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const pkgDir = dirname(dirname(fileURLToPath(import.meta.url)));
const copyMd = join(pkgDir, '..', '..', 'contracts', 'COPY.md');
const prettierrc = join(pkgDir, '..', '..', '.prettierrc');
const target = join(pkgDir, 'src', 'strings', 'strings.gen.ts');
const check = process.argv.includes('--check');

export function parseCopyMd(markdown) {
  /** @type {Array<[string, string]>} */
  const entries = [];
  let previousKey = '';
  for (const rawLine of markdown.split('\n')) {
    const line = rawLine.trim();
    if (!line.startsWith('- ') || !line.includes('`')) continue;
    // Split compact bullets only where a backticked key follows the separator.
    const segments = line.slice(2).split(/\s·\s(?=`)/);
    for (const segment of segments) {
      const match = /^`((?:[\w-]+(?:\.[\w-]+)*)|(?:\.[\w-]+))`\s+—\s+(.*)$/s.exec(segment.trim());
      if (match === null) continue;
      let key = match[1];
      if (key.startsWith('.')) {
        // Sibling shorthand: replace the previous key's last segment(s).
        const parent = previousKey.split('.').slice(0, -1).join('.');
        key = parent + key;
      }
      previousKey = key;
      const value = match[2].replaceAll('**', '').trim();
      entries.push([key, value]);
    }
  }
  return entries;
}

function render(entries) {
  const lines = entries.map(
    ([key, value]) => `  ${JSON.stringify(key)}: ${JSON.stringify(value)},`,
  );
  return `// GENERATED from contracts/COPY.md — DO NOT EDIT (CLAUDE.md rule 7).
// Regenerate: pnpm --filter @burnfront/web strings:generate
// Freshness is CI-verified: pnpm --filter @burnfront/web strings:check
// Interpolation (\`{braces}\`, ICU plural/select) lives in ./icu.ts.

export const catalog = {
${lines.join('\n')}
} as const;

export type CatalogKey = keyof typeof catalog;
`;
}

function generate(outFile) {
  const entries = parseCopyMd(readFileSync(copyMd, 'utf8'));
  if (entries.length < 50) {
    throw new Error(`Suspiciously few catalog entries parsed (${String(entries.length)}).`);
  }
  writeFileSync(outFile, render(entries));
  execFileSync(
    'pnpm',
    ['exec', 'prettier', '--config', prettierrc, '--parser', 'typescript', '--write', outFile],
    { cwd: pkgDir, stdio: ['ignore', 'ignore', 'inherit'] },
  );
}

if (check) {
  const dir = mkdtempSync(join(tmpdir(), 'bf-strings-'));
  const fresh = join(dir, 'strings.gen.ts');
  try {
    generate(fresh);
    if (readFileSync(fresh, 'utf8') !== readFileSync(target, 'utf8')) {
      process.stderr.write(
        'strings:check FAIL — src/strings/strings.gen.ts is stale against contracts/COPY.md.\n' +
          'Run: pnpm --filter @burnfront/web strings:generate\n',
      );
      process.exit(1);
    }
    process.stdout.write('strings:check OK — strings.gen.ts matches contracts/COPY.md.\n');
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
} else {
  generate(target);
  process.stdout.write('Wrote src/strings/strings.gen.ts from contracts/COPY.md.\n');
}
