/**
 * One-shot toast keys carried through router history state (no module-level
 * mutable store; the message lives and dies with its history entry). The
 * login consume flow navigates to the hub with `auth.consumed` (ADR-0003);
 * the WS-20 merge lands with `account.merge.summary` + its ICU params
 * ({solves}, {days}).
 *
 * `HistoryState` is an empty augmentable interface owned by
 * @tanstack/history (not a direct dependency, so we cannot merge into it);
 * the two helpers below are the single typed/untyped seam instead, with the
 * key and params runtime-validated on the way out.
 */
import type { HistoryState } from '@tanstack/react-router';
import type { IcuParams, StringKey } from '../strings';

const FLASH_KEYS = [
  'auth.consumed',
  'account.merge.summary',
] as const satisfies readonly StringKey[];

export type FlashKey = (typeof FLASH_KEYS)[number];

export interface Flash {
  readonly key: FlashKey;
  readonly params?: IcuParams;
}

function isIcuParams(value: unknown): value is IcuParams {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return false;
  return Object.values(value).every(
    (entry) => typeof entry === 'string' || typeof entry === 'number',
  );
}

/** History state for `navigate({ state })` carrying a one-shot toast. */
export function flashState(key: FlashKey, params?: IcuParams): HistoryState {
  return { flash: key, ...(params === undefined ? {} : { flashParams: params }) } as HistoryState;
}

/** The validated toast inside a history state, or null. */
export function flashOf(state: object): Flash | null {
  const value = (state as Record<string, unknown>)['flash'];
  if (typeof value !== 'string' || !(FLASH_KEYS as readonly string[]).includes(value)) return null;
  const rawParams = (state as Record<string, unknown>)['flashParams'];
  return {
    key: value as FlashKey,
    ...(isIcuParams(rawParams) ? { params: rawParams } : {}),
  };
}
