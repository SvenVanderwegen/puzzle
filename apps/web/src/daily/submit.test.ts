/**
 * Daily submission orchestration: pending persistence, the outcome ladder, the
 * rating refresh on rating_pending, and — the offline-replay guarantee — a
 * reconnect retry that reuses the SAME UUIDv7 Idempotency-Key.
 */
import { describe, expect, it, vi } from 'vitest';
import type { SolveSubmission } from '@burnfront/game-core';
import { memoryStorage } from '../state/localState';
import type { DailyApi, RatingData, SolveResultData, SubmitOutcome } from './api';
import { loadPending } from './pendingSubmission';
import {
  retryPendingDaily,
  submitDaily,
  type AssembledRecord,
  type DailySubmissionState,
} from './submit';

const DATE = '2026-07-08';
const KEY = '0192f000-0000-7000-8000-000000000abc';

function record(): AssembledRecord {
  const payload: SolveSubmission = {
    mode: 'daily',
    puzzle_id: 'bf1-5x5-000001',
    shaded: '0000000010010000010000100',
    client_ms: 161_000,
    started_at: '2026-07-08T12:00:00.000Z',
    hints: { s1: 0, s2: 0, s3: 0 },
    undo_count: 0,
  };
  return { payload, idempotencyKey: KEY };
}

const acceptedResult: SolveResultData = {
  solve_id: 'sv-1',
  valid: true,
  suspect: false,
  reason: 'ok',
  rating_pending: true,
  streak: { current: 3, best: 5 },
  daily: { rank: 4, percentile: 88, solved_count: 40 },
};

const rating: RatingData = {
  rating: 1512,
  rd: 90,
  volatility: 0.06,
  games: 12,
  calibrating: false,
  sparkline: [1500, 1512],
};

interface FakeApi extends DailyApi {
  readonly submits: { payload: SolveSubmission; key: string }[];
  ratingFetches: number;
}

function fakeApi(outcomes: SubmitOutcome | SubmitOutcome[]): FakeApi {
  const queue = Array.isArray(outcomes) ? [...outcomes] : [outcomes];
  const submits: { payload: SolveSubmission; key: string }[] = [];
  const api: FakeApi = {
    submits,
    ratingFetches: 0,
    getDaily: () => Promise.resolve({ kind: 'not_found' }),
    startDaily: () => Promise.resolve('stamped'),
    submitSolve: (payload, key) => {
      submits.push({ payload, key });
      const next = queue.length > 1 ? queue.shift() : queue[0];
      if (next === undefined) throw new Error('fakeApi: empty outcome queue');
      return Promise.resolve(next);
    },
    fetchRating: () => {
      api.ratingFetches += 1;
      return Promise.resolve(rating);
    },
  };
  return api;
}

async function collect(
  run: (onState: (s: DailySubmissionState) => void) => Promise<unknown>,
): Promise<DailySubmissionState[]> {
  const states: DailySubmissionState[] = [];
  await run((s) => states.push(s));
  return states;
}

describe('submitDaily', () => {
  it('accepts, clears pending, and refreshes the rating on rating_pending', async () => {
    const storage = memoryStorage();
    const api = fakeApi({ kind: 'accepted', result: acceptedResult });
    const states = await collect((onState) =>
      submitDaily(record(), DATE, api, storage, onState),
    );
    expect(states[0]).toEqual({ kind: 'submitting' });
    const last = states.at(-1);
    expect(last?.kind).toBe('accepted');
    if (last?.kind === 'accepted') {
      expect(last.result.daily?.percentile).toBe(88);
      expect(last.rating).toEqual(rating);
    }
    expect(api.ratingFetches).toBe(1);
    expect(loadPending(storage)).toBeNull();
  });

  it('keeps the pending record on a transport failure', async () => {
    const storage = memoryStorage();
    const api = fakeApi({ kind: 'network_error' });
    const states = await collect((onState) =>
      submitDaily(record(), DATE, api, storage, onState),
    );
    expect(states[states.length - 1]).toEqual({ kind: 'offline' });
    const pending = loadPending(storage);
    expect(pending?.idempotencyKey).toBe(KEY);
    expect(pending?.date).toBe(DATE);
  });

  it('maps rate limiting and expiry, clearing pending', async () => {
    const s1 = memoryStorage();
    await submitDaily(record(), DATE, fakeApi({ kind: 'rate_limited' }), s1, () => {});
    expect(loadPending(s1)).toBeNull();

    const s2 = memoryStorage();
    const states = await collect((onState) =>
      submitDaily(record(), DATE, fakeApi({ kind: 'unauthenticated' }), s2, onState),
    );
    expect(states[states.length - 1]).toEqual({ kind: 'unauthenticated' });
    expect(loadPending(s2)).toBeNull();
  });
});

describe('retryPendingDaily (reconnect)', () => {
  it('replays the persisted record with the SAME idempotency key', async () => {
    const storage = memoryStorage();
    // First attempt fails offline, leaving a pending record.
    const offlineApi = fakeApi({ kind: 'network_error' });
    await submitDaily(record(), DATE, offlineApi, storage, () => {});
    expect(offlineApi.submits[0]?.key).toBe(KEY);

    // Reconnect: a fresh api accepts; the key must be identical (idempotent).
    const onlineApi = fakeApi({ kind: 'accepted', result: acceptedResult });
    const retried = await retryPendingDaily(onlineApi, storage, () => {});
    expect(retried).toBe(true);
    expect(onlineApi.submits).toHaveLength(1);
    expect(onlineApi.submits[0]?.key).toBe(KEY);
    expect(loadPending(storage)).toBeNull();
  });

  it('is a no-op when nothing is pending', async () => {
    const onState = vi.fn();
    const done = await retryPendingDaily(fakeApi({ kind: 'network_error' }), memoryStorage(), onState);
    expect(done).toBe(false);
    expect(onState).not.toHaveBeenCalled();
  });
});
