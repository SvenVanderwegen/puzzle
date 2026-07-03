/**
 * Ambient declarations for the test environment only (Node modules used by
 * tests; @types/node is not on the dependency allowlist). No runtime code;
 * nothing ships. Same pattern as packages/engine and packages/game-core.
 */

/** Minimal node:fs surface used by the raw-hex tripwire test. */
declare module 'node:fs' {
  export interface DirentLike {
    readonly name: string;
    isDirectory(): boolean;
  }
  export function readdirSync(path: string, options: { withFileTypes: true }): DirentLike[];
  export function readFileSync(path: string, encoding: 'utf8'): string;
}

/** Minimal node:path surface used by tests. */
declare module 'node:path' {
  export function join(...parts: string[]): string;
}

/** Node global used by tests to locate package files (vitest cwd = package). */
declare const process: { cwd(): string };
