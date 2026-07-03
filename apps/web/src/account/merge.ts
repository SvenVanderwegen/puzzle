/**
 * WS-20 anonymous→account merge: right after a magic-link consume, the local
 * solve log uploads through POST /me/import (typed contract client). The
 * server re-validates every item and answers with per-item result codes —
 * fabrication caps are entirely server-side. On a 200 the server has ruled
 * on every item (credited, duplicate, dropped alike), so the caller clears
 * the log; on anything else the log stays for a later sign-in to merge.
 */
import type { ApiClient } from '@burnfront/api-client';
import { SOLVE_LOG_LIMIT, type SolveLogEntry } from '../state/localState';

export interface MergeSummary {
  /** Items the account accepted (credited dailies + stats-only endless). */
  readonly solves: number;
  /** The protected streak after the merge (server-capped at 7 imported days). */
  readonly days: number;
}

/** Wire items for importLocalRecord — newest SOLVE_LOG_LIMIT entries only. */
export function toImportItems(log: readonly SolveLogEntry[]): {
  client_solve_id: string;
  mode: 'daily' | 'endless';
  date: string | null;
  shaded: string;
  client_ms: number;
  hints: { s1: number; s2: number; s3: number };
  solved_at: string;
}[] {
  return log.slice(-SOLVE_LOG_LIMIT).map((entry) => ({
    client_solve_id: entry.clientSolveId,
    mode: entry.mode,
    date: entry.date,
    shaded: entry.shaded,
    client_ms: entry.clientMs,
    hints: { s1: entry.hints.s1, s2: entry.hints.s2, s3: entry.hints.s3 },
    solved_at: entry.solvedAt,
  }));
}

/**
 * Uploads the guest log. Returns the merge summary on a 200 (even when the
 * server accepted nothing — the ruling is final and the log may clear), or
 * null when there was nothing to upload or the server did not rule (429):
 * the caller then keeps the log. Network/5xx failures throw (ApiError).
 */
export async function uploadLocalRecord(
  api: ApiClient,
  log: readonly SolveLogEntry[],
): Promise<MergeSummary | null> {
  const items = toImportItems(log);
  if (items.length === 0) return null;
  const result = await api.post('/me/import', { body: { items } });
  if (result.status !== 200) return null;
  const merged = result.data.results.filter(
    (row) => row.status === 'credited' || row.status === 'stats_only',
  ).length;
  return { solves: merged, days: result.data.streak.current };
}
