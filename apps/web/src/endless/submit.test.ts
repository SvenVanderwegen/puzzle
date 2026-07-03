/**
 * Rated submission pipeline: game-core solve record (endless_spec, REQUIRED
 * deduction_steps, UUID v7 Idempotency-Key — ADR-0020/0021), the state
 * sequence around rating_pending → /me/rating refresh, and the delta text.
 * Also covers the browser gzip/sha256 ports (webDeps) end to end.
 */
import { gunzipSync } from 'node:zlib';
import { describe, expect, it } from 'vitest';
import type { BoardSpec } from '@burnfront/engine';
import { PlaySession } from '@burnfront/game-core';
import type { SolveSubmission } from '@burnfront/game-core';
import type { EndlessApi, RatingData, SubmitOutcome } from './api';
import { seededRng } from './rng';
import { formatDelta, ratingDelta, submitEndlessSolve, type SubmissionState } from './submit';
import { gzipCompressor, webHasher } from './webDeps';

/** The reference demo board (game-core fixtures) — breaks at 8, 11, 17, 22. */
const demoBoard: BoardSpec = {
  rows: 5,
  cols: 5,
  spark: { r: 3, c: 0 },
  breaks: 4,
  clues: [
    { r: 1, c: 4, m: 8 },
    { r: 2, c: 2, m: 5 },
    { r: 3, c: 1, m: 1 },
    { r: 4, c: 1, m: 2 },
    { r: 4, c: 3, m: 8 },
  ],
};

const UUID_V7 = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

function solvedSession(): PlaySession {
  let now = 1_751_576_400_000; // 2026-07-03T21:00:00Z
  const clock = { now: () => now };
  const session = new PlaySession({ board: demoBoard, mode: 'endless', deductionSteps: 17 }, clock);
  session.start();
  now += 61_000;
  for (const index of [8, 11, 17, 22]) session.tap(index);
  return session;
}

const env = { compressor: gzipCompressor, hasher: webHasher, rng: seededRng([9, 9, 9, 9]) };
const clock = { now: () => 1_751_576_461_000 };

interface FakeApi extends EndlessApi {
  readonly submissions: { payload: SolveSubmission; key: string }[];
  readonly ratingCalls: number[];
}

function fakeApi(outcome: SubmitOutcome, rating: RatingData | null): FakeApi {
  const submissions: { payload: SolveSubmission; key: string }[] = [];
  const ratingCalls: number[] = [];
  return {
    submissions,
    ratingCalls,
    submitSolve: (payload, key) => {
      submissions.push({ payload, key });
      return Promise.resolve(outcome);
    },
    fetchRating: () => {
      ratingCalls.push(1);
      return Promise.resolve(rating);
    },
  };
}

async function run(api: EndlessApi): Promise<SubmissionState[]> {
  const states: SubmissionState[] = [];
  await submitEndlessSolve(solvedSession(), api, env, clock, (state) => states.push(state));
  return states;
}

const acceptedPending: SubmitOutcome = {
  kind: 'accepted',
  result: { solve_id: 's-1', valid: true, suspect: false, rating_pending: true },
};

describe('submitEndlessSolve', () => {
  it('submits the full endless payload with a v7 Idempotency-Key, then refreshes the rating', async () => {
    const rating: RatingData = {
      rating: 1512.4,
      rd: 120,
      volatility: 0.06,
      games: 12,
      calibrating: false,
      sparkline: [1500, 1512.4],
    };
    const api = fakeApi(acceptedPending, rating);
    const states = await run(api);

    expect(api.submissions).toHaveLength(1);
    const { payload, key } = api.submissions[0] ?? { payload: null, key: '' };
    if (payload === null) throw new Error('no submission');
    expect(key).toMatch(UUID_V7);
    expect(payload.mode).toBe('endless');
    expect(payload.endless_spec?.spark).toEqual([3, 0]); // wire [r,c], not {r,c}
    expect(payload.endless_spec?.breaks).toBe(4);
    expect(payload.deduction_steps).toBe(17); // REQUIRED for endless (ADR-0020)
    expect(payload).not.toHaveProperty('puzzle_id');
    expect(payload.shaded).toBe('0000000010010000010000100');
    expect(payload.client_ms).toBe(61_000);
    expect(payload.hints).toEqual({ s1: 0, s2: 0, s3: 0 });
    expect(payload.replay_sha256).toMatch(/^[0-9a-f]{64}$/);

    // replay is gzip of the uncompressed JSON the digest covers (ADR-0012).
    const replayJson = gunzipSync(Buffer.from(payload.replay ?? '', 'base64')).toString('ascii');
    const events: unknown = JSON.parse(replayJson);
    expect(Array.isArray(events) && events.length === 4).toBe(true);
    expect(await webHasher.sha256Hex(new TextEncoder().encode(replayJson))).toBe(
      payload.replay_sha256,
    );

    expect(states.map((s) => s.kind)).toEqual(['submitting', 'pending', 'rated']);
    expect(api.ratingCalls).toHaveLength(1);
  });

  it('stays pending when the rating refresh is unavailable', async () => {
    const states = await run(fakeApi(acceptedPending, null));
    expect(states.map((s) => s.kind)).toEqual(['submitting', 'pending']);
  });

  it('degrades to the guest flow on 401', async () => {
    const api = fakeApi({ kind: 'unauthenticated' }, null);
    const states = await run(api);
    expect(states.map((s) => s.kind)).toEqual(['submitting', 'none']);
    expect(api.ratingCalls).toHaveLength(0);
  });

  it('maps 422 and 429 to the error copy keys', async () => {
    const invalid = await run(fakeApi({ kind: 'invalid' }, null));
    expect(invalid.at(-1)).toEqual({ kind: 'error', messageKey: 'error.generic' });
    const limited = await run(fakeApi({ kind: 'rate_limited' }, null));
    expect(limited.at(-1)).toEqual({ kind: 'error', messageKey: 'error.rateLimited' });
  });

  it('turns thrown transport errors into error.generic', async () => {
    const api: EndlessApi = {
      submitSolve: () => Promise.reject(new Error('boom')),
      fetchRating: () => Promise.resolve(null),
    };
    const states = await run(api);
    expect(states.at(-1)).toEqual({ kind: 'error', messageKey: 'error.generic' });
  });
});

describe('rating delta text', () => {
  const base: RatingData = {
    rating: 1512,
    rd: 100,
    volatility: 0.06,
    games: 12,
    calibrating: false,
  };

  it('reads the last sparkline movement', () => {
    expect(ratingDelta({ ...base, sparkline: [1500, 1512.4] })).toBe(12);
    expect(ratingDelta({ ...base, sparkline: [1520, 1512] })).toBe(-8);
    expect(ratingDelta({ ...base, sparkline: [1512] })).toBe(0);
    expect(ratingDelta(base)).toBe(0);
  });

  it('formats signed deltas with the true minus sign', () => {
    expect(formatDelta(9)).toBe('+9');
    expect(formatDelta(0)).toBe('+0');
    expect(formatDelta(-12)).toBe('−12');
  });
});
