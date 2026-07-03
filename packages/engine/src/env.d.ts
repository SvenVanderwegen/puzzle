/**
 * Ambient declarations for the test environment only (vitest/vite features
 * and Node globals used by tests). No runtime code; nothing here ships.
 */

/** Vite raw imports (used by tests to load vector files without node:fs). */
declare module '*?raw' {
  const content: string;
  export default content;
}

/** Vite glob imports (used by the determinism grep test). */
interface ImportMeta {
  glob(
    patterns: string | readonly string[],
    options: { readonly query: '?raw'; readonly import: 'default'; readonly eager: true },
  ): Record<string, string>;
}

/** Node's global high-resolution timer (perf tests; not in lib ES2022). */
declare const performance: { now(): number };
