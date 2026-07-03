/**
 * One-shot toast keys carried through router history state (no module-level
 * mutable store; the message lives and dies with its history entry). The
 * login consume flow navigates to the hub with `auth.consumed` (ADR-0003);
 * WS-20 adds `account.merge.summary` after the local-record import.
 *
 * `HistoryState` is an empty augmentable interface owned by
 * @tanstack/history (not a direct dependency, so we cannot merge into it);
 * the two helpers below are the single typed/untyped seam instead, with the
 * key runtime-validated on the way out.
 */
import type { HistoryState } from '@tanstack/react-router';
import type { StringKey } from '../strings';

const FLASH_KEYS = ['auth.consumed'] as const satisfies readonly StringKey[];

export type FlashKey = (typeof FLASH_KEYS)[number];

/** History state for `navigate({ state })` carrying a one-shot toast key. */
export function flashState(key: FlashKey): HistoryState {
  return { flash: key } as HistoryState;
}

/** The validated toast key inside a history state, or null. */
export function flashKeyOf(state: object): FlashKey | null {
  const value = (state as Record<string, unknown>)['flash'];
  return typeof value === 'string' && (FLASH_KEYS as readonly string[]).includes(value)
    ? (value as FlashKey)
    : null;
}
