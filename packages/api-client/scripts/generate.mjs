// Regenerates src/types.gen.ts from contracts/openapi.yaml (openapi-typescript),
// prettier-formatted so the committed file passes format:check byte-identically.
// `--check` regenerates to a temp dir and diffs — the CI freshness gate
// (playbook §5 gate 5), same pattern as the engine's vector conformance.
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const pkgDir = dirname(dirname(fileURLToPath(import.meta.url)));
const spec = join(pkgDir, '..', '..', 'contracts', 'openapi.yaml');
const prettierrc = join(pkgDir, '..', '..', '.prettierrc');
const target = join(pkgDir, 'src', 'types.gen.ts');
const check = process.argv.includes('--check');

const HEADER = `// GENERATED from contracts/openapi.yaml by openapi-typescript — DO NOT EDIT.
// Regenerate: pnpm --filter @burnfront/api-client generate
// Freshness is CI-verified: pnpm --filter @burnfront/api-client generate:check
`;

function generate(outFile) {
  execFileSync('pnpm', ['exec', 'openapi-typescript', spec, '-o', outFile], {
    cwd: pkgDir,
    stdio: ['ignore', 'ignore', 'inherit'],
  });
  const generated = readFileSync(outFile, 'utf8');
  writeFileSync(outFile, HEADER + generated);
  // Explicit --config and --parser: the --check temp file lives outside the
  // repo, where prettier would otherwise resolve neither.
  execFileSync(
    'pnpm',
    ['exec', 'prettier', '--config', prettierrc, '--parser', 'typescript', '--write', outFile],
    {
      cwd: pkgDir,
      stdio: ['ignore', 'ignore', 'inherit'],
    },
  );
}

if (check) {
  const dir = mkdtempSync(join(tmpdir(), 'bf-api-client-'));
  const fresh = join(dir, 'types.gen.ts');
  try {
    generate(fresh);
    if (readFileSync(fresh, 'utf8') !== readFileSync(target, 'utf8')) {
      process.stderr.write(
        'generate:check FAIL — src/types.gen.ts is stale against contracts/openapi.yaml.\n' +
          'Run: pnpm --filter @burnfront/api-client generate\n',
      );
      process.exit(1);
    }
    process.stdout.write('generate:check OK — types.gen.ts matches contracts/openapi.yaml.\n');
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
} else {
  generate(target);
  process.stdout.write('Wrote src/types.gen.ts from contracts/openapi.yaml.\n');
}
