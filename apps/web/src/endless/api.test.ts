/**
 * Endless API surface: wire conversion ([r,c] positions per vectors/README),
 * status mapping over an injected fetch, and the CSRF cookie reader.
 */
import { describe, expect, it, vi } from 'vitest';
import { createApiClient } from '@burnfront/api-client';
import type { SolveSubmission } from '@burnfront/game-core';
import { createEndlessApi, csrfTokenFromCookie, toWireSubmission } from './api';

const payload: SolveSubmission = {
  mode: 'endless',
  endless_spec: {
    rows: 5,
    cols: 5,
    spark: [3, 0],
    breaks: 4,
    clues: [
      { r: 1, c: 4, m: 8 },
      { r: 3, c: 1, m: 1 },
    ],
  },
  shaded: '0000000010010000010000100',
  client_ms: 61_000,
  started_at: '2026-07-03T21:00:00.000Z',
  hints: { s1: 0, s2: 0, s3: 0 },
  undo_count: 2,
  replay: 'AAAA',
  replay_sha256: 'ab'.repeat(32),
  deduction_steps: 17,
};

describe('toWireSubmission', () => {
  it('carries endless_spec with [r,c] spark and deduction_steps through', () => {
    const wire = toWireSubmission(payload);
    expect(wire.mode).toBe('endless');
    expect(wire.endless_spec?.spark).toEqual([3, 0]);
    expect(wire.endless_spec?.clues).toEqual(payload.endless_spec?.clues);
    expect(wire.deduction_steps).toBe(17);
    expect(wire.replay_sha256).toBe(payload.replay_sha256);
    expect(wire).not.toHaveProperty('puzzle_id');
  });

  it('omits absent optionals for daily-shaped payloads', () => {
    const wire = toWireSubmission({
      mode: 'daily',
      puzzle_id: 'p-1',
      shaded: '01',
      client_ms: 1,
      started_at: '2026-07-03T21:00:00.000Z',
      hints: { s1: 0, s2: 0, s3: 0 },
      undo_count: 0,
    });
    expect(wire.puzzle_id).toBe('p-1');
    expect(wire).not.toHaveProperty('endless_spec');
    expect(wire).not.toHaveProperty('deduction_steps');
    expect(wire).not.toHaveProperty('replay');
  });
});

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  });
}

function apiOver(fake: (url: string, init?: RequestInit) => Promise<Response>) {
  return createEndlessApi(createApiClient({ fetch: fake as typeof globalThis.fetch }));
}

describe('createEndlessApi', () => {
  it('POSTs /solves with the Idempotency-Key header and maps 201 to accepted', async () => {
    const seen: { url?: string; init?: RequestInit | undefined } = {};
    const api = apiOver((url, init) => {
      seen.url = url;
      seen.init = init;
      return Promise.resolve(
        jsonResponse(201, { solve_id: 's-1', valid: true, suspect: false, rating_pending: true }),
      );
    });
    const outcome = await api.submitSolve(payload, '01980aa3-5f00-7abc-8def-0123456789ab');
    expect(seen.url).toBe('/api/v1/solves');
    expect(seen.init?.method).toBe('POST');
    const headers = new Headers(seen.init?.headers);
    expect(headers.get('idempotency-key')).toBe('01980aa3-5f00-7abc-8def-0123456789ab');
    const body = seen.init?.body;
    if (typeof body !== 'string') throw new Error('expected a JSON string body');
    const sent: unknown = JSON.parse(body);
    expect(sent).toMatchObject({ mode: 'endless', deduction_steps: 17 });
    expect(outcome.kind).toBe('accepted');
    if (outcome.kind !== 'accepted') throw new Error('expected accepted');
    expect(outcome.result.rating_pending).toBe(true);
  });

  it('maps 401/422/429 to their outcomes', async () => {
    const statuses = [401, 422, 429];
    const api = apiOver(() => {
      const status = statuses.shift() ?? 500;
      return Promise.resolve(jsonResponse(status, { message: 'nope', errors: {} }));
    });
    expect((await api.submitSolve(payload, 'k')).kind).toBe('unauthenticated');
    expect((await api.submitSolve(payload, 'k')).kind).toBe('invalid');
    expect((await api.submitSolve(payload, 'k')).kind).toBe('rate_limited');
  });

  it('fetchRating returns the rating on 200 and null on 401 or 5xx', async () => {
    const rating = { rating: 1512.4, rd: 120, volatility: 0.06, games: 12, calibrating: false };
    const responses = [jsonResponse(200, rating), jsonResponse(401, { message: 'no' })];
    const api = apiOver(() =>
      Promise.resolve(responses.shift() ?? new Response('boom', { status: 500 })),
    );
    expect(await api.fetchRating()).toMatchObject({ rating: 1512.4, games: 12 });
    expect(await api.fetchRating()).toBeNull();
    expect(await api.fetchRating()).toBeNull();
  });
});

describe('csrfTokenFromCookie', () => {
  it('reads and decodes the XSRF-TOKEN cookie', () => {
    document.cookie = 'other=1';
    document.cookie = `XSRF-TOKEN=${encodeURIComponent('abc=/+123')}`;
    expect(csrfTokenFromCookie()).toBe('abc=/+123');
  });

  it('returns null when absent', () => {
    const spy = vi.spyOn(document, 'cookie', 'get').mockReturnValue('');
    expect(csrfTokenFromCookie()).toBeNull();
    spy.mockRestore();
  });
});
