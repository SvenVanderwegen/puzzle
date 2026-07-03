/**
 * Worker-boundary tripwire (brief: "main thread never blocked during
 * generation"): the engine's heavy solvers may only be VALUE-imported by the
 * worker module, and the worker module may only be referenced by the worker
 * factory (as a `new Worker(new URL(...))` split point). Everything else on
 * the main thread talks protocol messages.
 */
import { readdirSync, readFileSync } from 'node:fs';
import { join, sep } from 'node:path';
import { describe, expect, it } from 'vitest';

function walk(dir: string): string[] {
  const files: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) files.push(...walk(path));
    else files.push(path);
  }
  return files;
}

const root = process.cwd();
const WORKER_MODULE = join('src', 'endless', 'generator.worker.ts');
const WORKER_FACTORY = join('src', 'endless', 'workerFactory.ts');

const sourceFiles = walk(join(root, 'src')).filter(
  (file) => /\.(ts|tsx)$/.test(file) && !/\.test\.(ts|tsx)$/.test(file),
);

/** Named engine exports that run solver work (never on the main thread). */
const HEAVY_ENGINE_EXPORTS = ['generate', 'grade', 'deduce', 'countSolutions'];

function heavyEngineImports(content: string): string[] {
  const found: string[] = [];
  const importPattern = /import\s*(type\s*)?\{([^}]*)\}\s*from\s*'@burnfront\/engine'/g;
  for (const match of content.matchAll(importPattern)) {
    if (match[1] !== undefined) continue; // type-only import statement
    const specifiers = (match[2] ?? '')
      .split(',')
      .map((s) => s.trim())
      .filter((s) => s !== '' && !s.startsWith('type '));
    for (const specifier of specifiers) {
      const name = specifier.split(/\s+as\s+/)[0]?.trim() ?? '';
      if (HEAVY_ENGINE_EXPORTS.includes(name)) found.push(name);
    }
  }
  if (/import\s+\*\s+as\s+\w+\s+from\s*'@burnfront\/engine'/.test(content)) {
    found.push('* (namespace import)');
  }
  return found;
}

describe('tripwire: generation runs only in the worker', () => {
  it('scans a realistic file set', () => {
    expect(sourceFiles.some((file) => file.endsWith(WORKER_MODULE))).toBe(true);
    expect(sourceFiles.length).toBeGreaterThan(20);
  });

  it.each(sourceFiles.map((file) => [file.slice(root.length + 1)] as const))(
    '%s value-imports no engine solver outside the worker',
    (relative) => {
      const content = readFileSync(join(root, relative), 'utf8');
      const heavy = heavyEngineImports(content);
      if (relative === WORKER_MODULE) return; // the one allowed importer
      expect(heavy).toEqual([]);
    },
  );

  it('the worker module itself imports generate and grade (positive control)', () => {
    const content = readFileSync(join(root, WORKER_MODULE), 'utf8');
    expect(heavyEngineImports(content)).toEqual(expect.arrayContaining(['generate', 'grade']));
  });

  it('only the worker factory references the worker module', () => {
    for (const file of sourceFiles) {
      const relative = file.slice(root.length + 1);
      if (relative === WORKER_MODULE || relative === WORKER_FACTORY) continue;
      const content = readFileSync(file, 'utf8');
      expect(
        content.includes('generator.worker'),
        `${relative} must not reference the worker module`,
      ).toBe(false);
    }
    const factory = readFileSync(join(root, WORKER_FACTORY), 'utf8');
    expect(factory).toContain("new Worker(new URL('./generator.worker.ts', import.meta.url)");
  });

  it('worker paths use posix separators in this scan', () => {
    // Sanity for the path comparisons above on non-posix hosts.
    expect(WORKER_MODULE.split(sep).join('/')).toBe('src/endless/generator.worker.ts');
  });
});
