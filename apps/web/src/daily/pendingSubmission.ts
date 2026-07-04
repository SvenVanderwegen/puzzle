/**
 * The single in-progress daily submission, persisted across reloads so an
 * offline solve is retried on reconnect with the SAME UUIDv7 Idempotency-Key
 * (contracts/openapi.yaml submitSolve is idempotent per key; the server
 * replays the stored response on a duplicate). Exactly ONE daily is ever
 * pending — a fresh assembled record for a new date overwrites the slot, and
 * a definitive server verdict (accepted / rejected / rate-limited) clears it.
 */
import type { SolveSubmission } from '@burnfront/game-core';
import type { StorageLike } from '../state/localState';

const PENDING_KEY = 'burnfront.daily.pending.v1';

export interface PendingDailySubmission {
  /** UTC incident date this record contains. */
  readonly date: string;
  /** The assembled POST /solves body (mode: daily). */
  readonly payload: SolveSubmission;
  /** The UUIDv7 header value — stable across retries (idempotency identity). */
  readonly idempotencyKey: string;
}

function isPending(value: unknown): value is PendingDailySubmission {
  if (typeof value !== 'object' || value === null) return false;
  const record = value as Record<string, unknown>;
  return (
    typeof record.date === 'string' &&
    typeof record.idempotencyKey === 'string' &&
    typeof record.payload === 'object' &&
    record.payload !== null &&
    (record.payload as { mode?: unknown }).mode === 'daily'
  );
}

export function savePending(storage: StorageLike, pending: PendingDailySubmission): void {
  storage.setItem(PENDING_KEY, JSON.stringify(pending));
}

export function loadPending(storage: StorageLike): PendingDailySubmission | null {
  const raw = storage.getItem(PENDING_KEY);
  if (raw === null || raw === '') return null;
  try {
    const parsed: unknown = JSON.parse(raw);
    return isPending(parsed) ? parsed : null;
  } catch {
    return null;
  }
}

export function clearPending(storage: StorageLike): void {
  storage.setItem(PENDING_KEY, '');
}
