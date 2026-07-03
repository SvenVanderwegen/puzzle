/**
 * Daily board content fetched from the CDN (decisions.md #3:
 * content.burnfront.com). This is immutable, cross-origin STATIC JSON, not our
 * API: CLAUDE.md rule 2 (and the apps/web fetch lint ban) governs same-origin
 * API traffic, which must go through @burnfront/api-client — a static-asset GET
 * to the content CDN is outside that surface (there is no api-client path for
 * it). The single global-fetch reference is isolated here behind an injectable
 * port so the rest of the SPA never touches fetch and every test injects a
 * fake. A burnfront.puzzle/1 document nests the board under `board`; anything
 * malformed or unreachable resolves to null so the surface can fall back to the
 * origin-embedded board or a retry. See STATUS.md decision on the rule-2 scope.
 */
import { isWireBoard, type WireBoard } from './board';

/** The narrow fetch shape the content port needs (URL in, Response out). */
export type ContentFetch = (url: string) => Promise<Response>;

export interface DailyContent {
  /** Fetch and validate the board at a CDN content URL; null on any failure. */
  fetchBoard(url: string): Promise<WireBoard | null>;
}

/** The browser's fetch, resolved once behind the port (never used elsewhere). */
function globalFetch(): ContentFetch | null {
  // eslint-disable-next-line no-restricted-properties -- cross-origin CDN static content, not the API (rule 2 scope); isolated behind this port
  const f = typeof globalThis.fetch === 'function' ? globalThis.fetch.bind(globalThis) : null;
  return f;
}

/** Browser content port over the injected fetch (defaults to the global). */
export function createBrowserContent(fetchImpl?: ContentFetch): DailyContent {
  return {
    async fetchBoard(url) {
      const doFetch = fetchImpl ?? globalFetch();
      if (doFetch === null) return null;
      try {
        const response = await doFetch(url);
        if (!response.ok) return null;
        const doc: unknown = await response.json();
        if (typeof doc !== 'object' || doc === null) return null;
        const board = (doc as { board?: unknown }).board;
        return isWireBoard(board) ? board : null;
      } catch {
        return null;
      }
    },
  };
}
