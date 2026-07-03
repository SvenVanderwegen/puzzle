/**
 * Rated endless submission (ADR-0006 / ADR-0020 / ADR-0021): assemble the
 * SolveSubmission with game-core (endless_spec wire board, REQUIRED
 * deduction_steps, UUID v7 Idempotency-Key), POST it, then refresh
 * GET /me/rating once the server reports rating_pending (the submit response
 * carries no board or user rating — the refresh is the display source).
 * Guests never reach this module; EndlessPlay skips submission cleanly.
 */
import type { Rng } from '@burnfront/engine';
import {
  assembleSolveRecord,
  type Compressor,
  type Hasher,
  type PlaySession,
} from '@burnfront/game-core';
import type { Clock } from '../state/clock';
import type { EndlessApi, RatingData } from './api';

export interface SolveRecordEnv {
  readonly compressor: Compressor;
  readonly hasher: Hasher;
  readonly rng: Rng;
}

export type SubmissionState =
  | { readonly kind: 'none' }
  | { readonly kind: 'submitting' }
  /** Accepted; Glicko-2 job queued (rating_pending) — refresh in flight/failed. */
  | { readonly kind: 'pending' }
  | { readonly kind: 'rated'; readonly rating: RatingData }
  | { readonly kind: 'error'; readonly messageKey: 'error.generic' | 'error.rateLimited' };

export async function submitEndlessSolve(
  session: PlaySession,
  api: EndlessApi,
  env: SolveRecordEnv,
  clock: Clock,
  onState: (state: SubmissionState) => void,
): Promise<void> {
  try {
    onState({ kind: 'submitting' });
    const record = await assembleSolveRecord(session.solveRecordSource(), {
      compressor: env.compressor,
      hasher: env.hasher,
      rng: env.rng,
      clock,
    });
    const outcome = await api.submitSolve(record.payload, record.idempotencyKey);
    if (outcome.kind === 'unauthenticated') {
      // Session expired between load and solve: degrade to the guest flow.
      onState({ kind: 'none' });
      return;
    }
    if (outcome.kind === 'rate_limited') {
      onState({ kind: 'error', messageKey: 'error.rateLimited' });
      return;
    }
    if (outcome.kind === 'invalid') {
      onState({ kind: 'error', messageKey: 'error.generic' });
      return;
    }
    onState({ kind: 'pending' });
    const rating = await api.fetchRating();
    if (rating !== null) onState({ kind: 'rated', rating });
  } catch {
    onState({ kind: 'error', messageKey: 'error.generic' });
  }
}

/** Last rating movement from the sparkline (oldest first); 0 when unknown. */
export function ratingDelta(rating: RatingData): number {
  const sparkline = rating.sparkline;
  if (sparkline === undefined || sparkline.length < 2) return 0;
  const last = sparkline[sparkline.length - 1] ?? 0;
  const previous = sparkline[sparkline.length - 2] ?? 0;
  return Math.round(last - previous);
}

/** "+9" / "−12" — signed delta text (numerals, not copy; same as the hub). */
export function formatDelta(delta: number): string {
  return delta >= 0 ? `+${String(delta)}` : `−${String(Math.abs(delta))}`;
}
