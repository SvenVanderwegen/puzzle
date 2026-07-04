/**
 * Rated daily submission (ADR-0006/0012/0020/0021): assemble the
 * SolveSubmission with game-core (mode: daily, puzzle_id, REQUIRED
 * replay_sha256 over the UNCOMPRESSED bytes, UUIDv7 Idempotency-Key), persist
 * it as THE ONE pending daily, POST it, then refresh GET /me/rating when the
 * server reports rating_pending. A transport failure keeps the pending record
 * so reconnect retries the SAME key idempotently; a definitive verdict clears
 * it. Guests never reach this module (EndlessPlay-style: the surface skips it).
 */
import { assembleSolveRecord, type PlaySession } from '@burnfront/game-core';
import type { SolveSubmission } from '@burnfront/game-core';
import type { Clock } from '../state/clock';
import type { StorageLike } from '../state/localState';
import type { SolveRecordEnv } from '../endless/submit';
import type { DailyApi, RatingData, SolveResultData } from './api';
import { clearPending, loadPending, savePending } from './pendingSubmission';

export interface AssembledRecord {
  readonly payload: SolveSubmission;
  readonly idempotencyKey: string;
}

export type DailySubmissionState =
  | { readonly kind: 'none' }
  | { readonly kind: 'submitting' }
  /** Queued locally; a reconnect retries the same idempotency key. */
  | { readonly kind: 'offline' }
  /** Session expired between load and submit — caller degrades to the guest path. */
  | { readonly kind: 'unauthenticated' }
  | {
      readonly kind: 'accepted';
      readonly result: SolveResultData;
      readonly rating: RatingData | null;
    }
  | { readonly kind: 'error'; readonly messageKey: 'error.generic' | 'error.rateLimited' };

/** Assemble the daily solve record once (stable key for idempotent retries). */
export async function assembleDailyRecord(
  session: PlaySession,
  env: SolveRecordEnv,
  clock: Clock,
): Promise<AssembledRecord> {
  const record = await assembleSolveRecord(session.solveRecordSource(), {
    compressor: env.compressor,
    hasher: env.hasher,
    rng: env.rng,
    clock,
  });
  return { payload: record.payload, idempotencyKey: record.idempotencyKey };
}

async function dispatch(
  record: AssembledRecord,
  api: DailyApi,
  storage: StorageLike,
  onState: (state: DailySubmissionState) => void,
): Promise<void> {
  const outcome = await api.submitSolve(record.payload, record.idempotencyKey);
  switch (outcome.kind) {
    case 'accepted': {
      clearPending(storage);
      const rating = outcome.result.rating_pending === true ? await api.fetchRating() : null;
      onState({ kind: 'accepted', result: outcome.result, rating });
      return;
    }
    case 'rate_limited':
      clearPending(storage);
      onState({ kind: 'error', messageKey: 'error.rateLimited' });
      return;
    case 'invalid':
      clearPending(storage);
      onState({ kind: 'error', messageKey: 'error.generic' });
      return;
    case 'unauthenticated':
      clearPending(storage);
      onState({ kind: 'unauthenticated' });
      return;
    case 'network_error':
      // Keep the pending record; a reconnect replays the same key.
      onState({ kind: 'offline' });
      return;
  }
}

/** Persist + submit the in-progress daily. */
export async function submitDaily(
  record: AssembledRecord,
  date: string,
  api: DailyApi,
  storage: StorageLike,
  onState: (state: DailySubmissionState) => void,
): Promise<void> {
  savePending(storage, {
    date,
    payload: record.payload,
    idempotencyKey: record.idempotencyKey,
  });
  onState({ kind: 'submitting' });
  await dispatch(record, api, storage, onState);
}

/**
 * Retry the persisted daily submission (reconnect / remount). Returns false
 * when nothing is pending, so callers can skip the state churn.
 */
export async function retryPendingDaily(
  api: DailyApi,
  storage: StorageLike,
  onState: (state: DailySubmissionState) => void,
): Promise<boolean> {
  const pending = loadPending(storage);
  if (pending === null) return false;
  onState({ kind: 'submitting' });
  await dispatch(
    { payload: pending.payload, idempotencyKey: pending.idempotencyKey },
    api,
    storage,
    onState,
  );
  return true;
}
