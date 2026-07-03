/**
 * The Daily surface's narrow API over the generated @burnfront/api-client
 * (CLAUDE.md rule 2 — no hand-written fetch to our own origin). Three
 * operations from contracts/openapi.yaml:
 *  - getDaily     GET  /daily/{date}        — metadata + stats (+ embedded
 *                                             `puzzle` under origin fallback)
 *  - startDaily   POST /daily/{date}/start  — the anti-cheat fetch anchor
 *  - submitSolve  POST /solves              — idempotent via UUIDv7 header
 *
 * Content JSON on the CDN is NOT an API call — it is fetched separately (see
 * ./content.ts); only same-origin API traffic goes through the client.
 */
import { ApiError, type ApiClient, type components } from '@burnfront/api-client';
import type { SolveSubmission } from '@burnfront/game-core';
import { toWireSubmission } from '../endless/api';

export type DailyData = components['schemas']['Daily'];
export type SolveResultData = components['schemas']['SolveResult'];
export type RatingData = components['schemas']['Rating'];

export type GetDailyOutcome =
  | { readonly kind: 'ok'; readonly daily: DailyData }
  /** No incident published for this date (includes future dates). */
  | { readonly kind: 'not_found' }
  /** Network/server failure — the caller may fall back or retry. */
  | { readonly kind: 'error' };

export type StartDailyOutcome = 'stamped' | 'unauthenticated' | 'not_found' | 'error';

export type SubmitOutcome =
  | { readonly kind: 'accepted'; readonly result: SolveResultData }
  | { readonly kind: 'unauthenticated' }
  | { readonly kind: 'invalid' }
  | { readonly kind: 'rate_limited' }
  /** Transport failure — retryable with the SAME idempotency key. */
  | { readonly kind: 'network_error' };

export interface DailyApi {
  getDaily(date: string): Promise<GetDailyOutcome>;
  startDaily(date: string): Promise<StartDailyOutcome>;
  submitSolve(payload: SolveSubmission, idempotencyKey: string): Promise<SubmitOutcome>;
  /** GET /me/rating for the post-solve chip; null when unavailable. */
  fetchRating(): Promise<RatingData | null>;
}

/** Wraps the runtime's typed contract client in the Daily operations. */
export function createDailyApi(client: ApiClient): DailyApi {
  return {
    async getDaily(date) {
      try {
        const result = await client.get('/daily/{date}', { path: { date } });
        if (result.status === 200) return { kind: 'ok', daily: result.data };
        return { kind: 'not_found' };
      } catch (error) {
        if (error instanceof ApiError) return { kind: 'error' };
        return { kind: 'error' };
      }
    },
    async startDaily(date) {
      try {
        const result = await client.post('/daily/{date}/start', { path: { date } });
        if (result.status === 204) return 'stamped';
        if (result.status === 401) return 'unauthenticated';
        return 'not_found';
      } catch {
        return 'error';
      }
    },
    async submitSolve(payload, idempotencyKey) {
      try {
        const result = await client.post('/solves', {
          header: { 'Idempotency-Key': idempotencyKey },
          body: toWireSubmission(payload),
        });
        if (result.status === 200 || result.status === 201) {
          return { kind: 'accepted', result: result.data };
        }
        if (result.status === 401) return { kind: 'unauthenticated' };
        if (result.status === 429) return { kind: 'rate_limited' };
        return { kind: 'invalid' };
      } catch {
        // A status outside the contract (≥500, ApiError) or a transport error:
        // keep the record and retry the SAME key on reconnect (idempotent).
        return { kind: 'network_error' };
      }
    },
    async fetchRating() {
      try {
        const result = await client.get('/me/rating');
        return result.status === 200 ? result.data : null;
      } catch {
        return null;
      }
    },
  };
}
