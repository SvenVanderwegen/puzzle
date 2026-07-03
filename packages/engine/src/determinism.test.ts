/**
 * Determinism law (CLAUDE.md rule 8): the engine never touches the clock or
 * ambient randomness — time and randomness are injected. Enforced here as a
 * grep over every TypeScript file in the package, plus the zero-runtime-deps
 * assertion for package.json.
 */
import { describe, expect, it } from 'vitest';
import pkgRaw from '../package.json?raw';

// Patterns are split so this file cannot match itself.
const forbidden = [
  { name: 'the ambient clock', re: new RegExp(['Date', 'now'].join(String.raw`\s*\.\s*`)) },
  { name: 'ambient randomness', re: new RegExp(['Math', 'random'].join(String.raw`\s*\.\s*`)) },
];

const sources: Record<string, string> = import.meta.glob(['./**/*.ts', '../vitest.config.ts'], {
  query: '?raw',
  import: 'default',
  eager: true,
});

describe('determinism', () => {
  it('no clock or ambient randomness anywhere in the package', () => {
    const files = Object.keys(sources);
    expect(files.length).toBeGreaterThan(10);
    for (const [file, content] of Object.entries(sources)) {
      for (const { name, re } of forbidden) {
        expect(re.test(content), `${file} uses ${name}`).toBe(false);
      }
    }
  });

  it('package.json declares zero runtime dependencies', () => {
    const pkg = JSON.parse(pkgRaw) as { dependencies?: Record<string, string> };
    expect(pkg.dependencies).toEqual({});
  });
});
