/**
 * Ambient declarations for the test environment only (vitest/vite features
 * and Node modules/globals used by tests). No runtime code; nothing ships.
 */

/** Vite raw imports (tests load contracts/openapi.yaml without node:fs). */
declare module '*?raw' {
  const content: string;
  export default content;
}

/** Vite glob imports (used by the boundary sweep test). */
interface ImportMeta {
  glob(
    patterns: string | readonly string[],
    options: { readonly query: '?raw'; readonly import: 'default'; readonly eager: true },
  ): Record<string, string>;
}

/** Minimal node:crypto surface used by tests (a REAL sha256 for the hasher). */
declare module 'node:crypto' {
  export function createHash(algorithm: 'sha256'): {
    update(data: Uint8Array): { digest(encoding: 'hex'): string };
  };
}

/** Minimal node:zlib surface used by tests (a REAL gzip for the compressor). */
declare module 'node:zlib' {
  export function gzipSync(data: Uint8Array): Uint8Array;
  export function gunzipSync(data: Uint8Array): Uint8Array;
}

/** Base64 globals (Node ≥ 16 and browsers; tests only). */
declare function atob(data: string): string;
declare function btoa(data: string): string;
