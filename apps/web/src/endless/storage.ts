/**
 * Adapts the shell's injected StorageLike (state/localState.ts) to
 * game-core's KeyValueStorage persistence port, so Endless session snapshots
 * go through game-core's validated save/load/clear. StorageLike has no
 * removeItem; where the underlying store lacks one, remove() writes an empty
 * tombstone that get() reports as absent.
 */
import type { KeyValueStorage } from '@burnfront/game-core';
import type { StorageLike } from '../state/localState';

export function toKeyValueStorage(storage: StorageLike): KeyValueStorage {
  const removable = storage as StorageLike & { removeItem?: (key: string) => void };
  return {
    get: (key) => {
      const value = storage.getItem(key);
      return value === null || value === '' ? null : value;
    },
    set: (key, value) => {
      storage.setItem(key, value);
    },
    remove: (key) => {
      if (typeof removable.removeItem === 'function') removable.removeItem(key);
      else storage.setItem(key, '');
    },
  };
}
