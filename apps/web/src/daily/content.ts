/**
 * Daily board content fetched from the CDN (decisions.md #3:
 * content.burnfront.com). This is immutable static JSON, NOT our API, so it is
 * fetched directly rather than through @burnfront/api-client (rule 2 governs
 * same-origin API traffic). A burnfront.puzzle/1 document nests the board
 * under `board`; anything malformed or unreachable resolves to null so the
 * surface can fall back to the origin-embedded board or a retry.
 */
import { isWireBoard, type WireBoard } from './board';

export interface DailyContent {
  /** Fetch and validate the board at a CDN content URL; null on any failure. */
  fetchBoard(url: string): Promise<WireBoard | null>;
}

/** Browser content port over the injected fetch (defaults to the global). */
export function createBrowserContent(fetchImpl?: typeof globalThis.fetch): DailyContent {
  return {
    async fetchBoard(url) {
      const doFetch = fetchImpl ?? globalThis.fetch;
      if (typeof doFetch !== 'function') return null;
      try {
        const response = await doFetch(url, { credentials: 'omit' });
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
