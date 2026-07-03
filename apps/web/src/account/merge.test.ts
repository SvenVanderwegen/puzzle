/**
 * WS-20 merge upload unit tests: wire mapping, the 100-item client-side cap,
 * the 200-final-ruling / keep-log-otherwise split, and the summary counts
 * (credited + stats_only = "solves merged"; streak.current = "days").
 */
import { describe, expect, it } from 'vitest';
import { SOLVE_LOG_LIMIT, type SolveLogEntry } from '../state/localState';
import { mockApi } from '../testing/mockApi';
import { toImportItems, uploadLocalRecord } from './merge';

function entry(i: number, overrides: Partial<SolveLogEntry> = {}): SolveLogEntry {
  return {
    clientSolveId: `01980000-0000-7000-8000-${String(i).padStart(12, '0')}`,
    mode: 'daily',
    date: '2026-07-03',
    shaded: '000010010',
    clientMs: 61_000,
    hints: { s1: 0, s2: 0, s3: 0 },
    solvedAt: '2026-07-03T20:00:00.000Z',
    ...overrides,
  };
}

describe('toImportItems', () => {
  it('maps entries to the ImportItem wire shape', () => {
    expect(toImportItems([entry(1), entry(2, { mode: 'endless', date: null })])).toEqual([
      {
        client_solve_id: '01980000-0000-7000-8000-000000000001',
        mode: 'daily',
        date: '2026-07-03',
        shaded: '000010010',
        client_ms: 61_000,
        hints: { s1: 0, s2: 0, s3: 0 },
        solved_at: '2026-07-03T20:00:00.000Z',
      },
      {
        client_solve_id: '01980000-0000-7000-8000-000000000002',
        mode: 'endless',
        date: null,
        shaded: '000010010',
        client_ms: 61_000,
        hints: { s1: 0, s2: 0, s3: 0 },
        solved_at: '2026-07-03T20:00:00.000Z',
      },
    ]);
  });

  it('caps at the contract maximum, keeping the newest entries', () => {
    const log = Array.from({ length: SOLVE_LOG_LIMIT + 8 }, (_, i) => entry(i));
    const items = toImportItems(log);
    expect(items).toHaveLength(SOLVE_LOG_LIMIT);
    expect(items[0]?.client_solve_id).toBe(log[8]?.clientSolveId);
  });
});

describe('uploadLocalRecord', () => {
  it('returns null without any network call for an empty log', async () => {
    const { api, calls } = mockApi({});
    expect(await uploadLocalRecord(api, [])).toBeNull();
    expect(calls).toEqual([]);
  });

  it('counts credited + stats_only as merged and reads the protected streak', async () => {
    const { api, callsTo } = mockApi({
      'POST /me/import': {
        status: 200,
        data: {
          results: [
            { client_solve_id: 'a', status: 'credited' },
            { client_solve_id: 'b', status: 'credited' },
            { client_solve_id: 'c', status: 'stats_only' },
            { client_solve_id: 'd', status: 'invalid' },
            { client_solve_id: 'e', status: 'duplicate' },
          ],
          credited_days: 2,
          streak: { current: 2, best: 4 },
        },
      },
    });

    const summary = await uploadLocalRecord(api, [entry(1), entry(2), entry(3)]);

    expect(summary).toEqual({ solves: 3, days: 2 });
    expect(callsTo('POST /me/import')).toHaveLength(1);
  });

  it('returns null when the server did not rule (429) so the log is kept', async () => {
    const { api } = mockApi({ 'POST /me/import': { status: 429 } });
    expect(await uploadLocalRecord(api, [entry(1)])).toBeNull();
  });
});
