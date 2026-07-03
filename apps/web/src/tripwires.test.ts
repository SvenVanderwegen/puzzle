/**
 * Package-local tripwires (same pattern as ui-web's tokens.test.ts):
 *  1. NO raw hex anywhere in apps/web sources — colors reach CSS only through
 *     the generated --bf-* custom properties (CLAUDE.md rule 7).
 *  2. NO literal English in JSX outside the strings module — every
 *     user-facing string renders through t() (keyed catalog from COPY.md).
 *     Glyph-only text (★ · × digits) is allowed.
 */
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
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
const sourceFiles = walk(join(root, 'src')).filter(
  (file) => /\.(ts|tsx)$/.test(file) && !/\.test\.(ts|tsx)$/.test(file),
);

describe('tripwire: zero raw hex in apps/web', () => {
  const hexPattern = /#[0-9a-fA-F]{3,8}\b/;
  const scanned = [...sourceFiles, join(root, 'index.html'), join(root, 'vite.config.ts')];

  it('scans a realistic file set', () => {
    expect(sourceFiles.length).toBeGreaterThan(15);
  });

  it.each(scanned.map((file) => [file.slice(root.length + 1)] as const))(
    '%s contains no raw hex color',
    (relative) => {
      const content = readFileSync(join(root, relative), 'utf8');
      const offending = content
        .split('\n')
        .filter((line) => hexPattern.test(line) && !line.includes('data:,'));
      expect(offending).toEqual([]);
    },
  );
});

describe('tripwire: no literal English in JSX outside the strings module', () => {
  // JSX text nodes: letters between a closing and an opening angle bracket
  // with no expression braces — i.e. hard-coded copy.
  const textNode = />[ \t]*[A-Za-z][A-Za-z' .,!?-]+[ \t]*</;
  // Literal user-facing attribute strings (class names etc. excluded).
  const attrText = /(?:aria-label|title|alt|placeholder)=\s*"[^"]*[A-Za-z]{2}/;

  const jsxFiles = sourceFiles.filter(
    (file) => file.endsWith('.tsx') && !file.includes(join('src', 'strings')),
  );

  it('scans the component files', () => {
    expect(jsxFiles.length).toBeGreaterThan(5);
  });

  it.each(jsxFiles.map((file) => [file.slice(root.length + 1)] as const))(
    '%s renders text through t() only',
    (relative) => {
      const content = readFileSync(join(root, relative), 'utf8');
      const offending = content
        .split('\n')
        .filter((line) => textNode.test(line) || attrText.test(line));
      expect(offending).toEqual([]);
    },
  );
});
