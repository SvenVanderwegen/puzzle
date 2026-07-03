/**
 * Boundary sweep (brief acceptance: no DOM/React imports — the repo-wide
 * dependency-cruiser rule lands later; this is the in-package tripwire).
 * Also pins the runtime dependency list to exactly @burnfront/engine.
 */
import { describe, expect, it } from 'vitest';
import pkgRaw from '../package.json?raw';

const sources: Record<string, string> = import.meta.glob('./**/*.ts', {
  query: '?raw',
  import: 'default',
  eager: true,
});

const shipped = Object.entries(sources).filter(
  ([path]) => !path.endsWith('.test.ts') && !path.endsWith('.d.ts'),
);

function stripComments(code: string): string {
  return code.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '');
}

describe('game-core stays framework-agnostic', () => {
  it('finds the source files', () => {
    expect(shipped.length).toBeGreaterThanOrEqual(9);
  });

  it.each(shipped)('%s imports only @burnfront/engine or relative modules', (_path, code) => {
    const specifiers = [...code.matchAll(/(?:^|\n)\s*(?:import|export)[^'"\n]*from\s+'([^']+)'/g)]
      .map((m) => m[1])
      .filter((s): s is string => s !== undefined);
    for (const spec of specifiers) {
      expect(spec === '@burnfront/engine' || spec.startsWith('.'), `bad import ${spec}`).toBe(true);
    }
  });

  it.each(shipped)(
    '%s references no DOM, network or ambient time/randomness globals',
    (_path, code) => {
      const stripped = stripComments(code);
      const banned = [
        /\bdocument\b/,
        /\bwindow\b/,
        /\blocalStorage\b/,
        /\bsessionStorage\b/,
        /\bnavigator\b/,
        /\bfetch\s*\(/,
        /\bXMLHttpRequest\b/,
        /\bWebSocket\b/,
        /Date\s*\.\s*now/,
        /Math\s*\.\s*random/,
        /performance\s*\.\s*now/,
        /\brequire\s*\(/,
      ];
      for (const pattern of banned) {
        expect(pattern.test(stripped), `${pattern.source} found`).toBe(false);
      }
    },
  );

  it('runtime dependencies are exactly @burnfront/engine (workspace)', () => {
    const raw: unknown = JSON.parse(pkgRaw);
    const pkg = raw as { dependencies?: Record<string, string>; devDependencies?: unknown };
    expect(Object.keys(pkg.dependencies ?? {})).toEqual(['@burnfront/engine']);
    expect(pkg.dependencies?.['@burnfront/engine']).toBe('workspace:*');
    expect(pkg.devDependencies).toBeUndefined();
  });
});
