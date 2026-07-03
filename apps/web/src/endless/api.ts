/**
 * Endless's narrow API surface over the generated @burnfront/api-client
 * (CLAUDE.md rule 2 — no hand-written fetch). Submissions POST /solves with
 * the game-core-assembled SolveSubmission + UUID v7 Idempotency-Key
 * (ADR-0021); the rating refresh reads GET /me/rating.
 */
import { ApiError, createApiClient, type ApiClient, type components } from '@burnfront/api-client';
import type { SolveSubmission } from '@burnfront/game-core';

export type SolveResultData = components['schemas']['SolveResult'];
export type RatingData = components['schemas']['Rating'];

export type SubmitOutcome =
  | { readonly kind: 'accepted'; readonly result: SolveResultData }
  | { readonly kind: 'unauthenticated' }
  | { readonly kind: 'invalid' }
  | { readonly kind: 'rate_limited' };

export interface EndlessApi {
  submitSolve(payload: SolveSubmission, idempotencyKey: string): Promise<SubmitOutcome>;
  /** Null when the rating is unavailable (guest, offline, server error). */
  fetchRating(): Promise<RatingData | null>;
}

/** Sanctum's XSRF-TOKEN cookie, decoded; null outside a browser session. */
export function csrfTokenFromCookie(): string | null {
  if (typeof document === 'undefined') return null;
  const match = /(?:^|;\s*)XSRF-TOKEN=([^;]+)/.exec(document.cookie);
  return match?.[1] === undefined ? null : decodeURIComponent(match[1]);
}

type WireSubmission = components['schemas']['SolveSubmission'];

/** game-core payload → generated wire type (fresh mutable arrays/tuples). */
export function toWireSubmission(payload: SolveSubmission): WireSubmission {
  return {
    mode: payload.mode,
    ...(payload.puzzle_id !== undefined ? { puzzle_id: payload.puzzle_id } : {}),
    ...(payload.endless_spec !== undefined
      ? {
          endless_spec: {
            rows: payload.endless_spec.rows,
            cols: payload.endless_spec.cols,
            spark: [payload.endless_spec.spark[0], payload.endless_spec.spark[1]],
            breaks: payload.endless_spec.breaks,
            clues: payload.endless_spec.clues.map((clue) => ({ ...clue })),
          },
        }
      : {}),
    shaded: payload.shaded,
    client_ms: payload.client_ms,
    started_at: payload.started_at,
    hints: { ...payload.hints },
    undo_count: payload.undo_count,
    ...(payload.replay !== undefined ? { replay: payload.replay } : {}),
    ...(payload.replay_sha256 !== undefined ? { replay_sha256: payload.replay_sha256 } : {}),
    ...(payload.deduction_steps !== undefined ? { deduction_steps: payload.deduction_steps } : {}),
  };
}

export function createEndlessApi(
  client: ApiClient = createApiClient({ getCsrfToken: csrfTokenFromCookie }),
): EndlessApi {
  return {
    async submitSolve(payload, idempotencyKey) {
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
    },
    async fetchRating() {
      try {
        const result = await client.get('/me/rating');
        return result.status === 200 ? result.data : null;
      } catch (error) {
        if (error instanceof ApiError) return null;
        throw error;
      }
    },
  };
}
