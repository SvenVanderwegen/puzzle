/**
 * Generator worker wire protocol. Every request carries a client-issued
 * token; the client drops any response whose token it no longer waits on,
 * which is what makes cancel/regenerate race-free (no worker-side state).
 */
import type { BoardSpec } from '@burnfront/engine';

export interface GenerateRequest {
  readonly kind: 'generate';
  readonly token: number;
  readonly rows: number;
  readonly cols: number;
  readonly breaks: number;
  readonly minClues: number;
  /** sfc32 seed words, drawn from WebCrypto at the app boundary. */
  readonly seed: readonly number[];
}

export interface GenerateResult {
  readonly kind: 'result';
  readonly token: number;
  readonly board: BoardSpec;
  /** engine grade() of the board — SolveSubmission.deduction_steps. */
  readonly deductionSteps: number;
}

export interface GenerateFailure {
  readonly kind: 'failure';
  readonly token: number;
  readonly message: string;
}

export type WorkerResponse = GenerateResult | GenerateFailure;

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

export function isGenerateRequest(value: unknown): value is GenerateRequest {
  return (
    isRecord(value) &&
    value.kind === 'generate' &&
    typeof value.token === 'number' &&
    typeof value.rows === 'number' &&
    typeof value.cols === 'number' &&
    typeof value.breaks === 'number' &&
    typeof value.minClues === 'number' &&
    Array.isArray(value.seed) &&
    value.seed.every((word) => typeof word === 'number')
  );
}

export function isWorkerResponse(value: unknown): value is WorkerResponse {
  if (!isRecord(value) || typeof value.token !== 'number') return false;
  if (value.kind === 'failure') return typeof value.message === 'string';
  return (
    value.kind === 'result' && isRecord(value.board) && typeof value.deductionSteps === 'number'
  );
}
