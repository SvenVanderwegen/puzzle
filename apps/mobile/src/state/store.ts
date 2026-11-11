/**
 * A tiny persisted store: state kept in memory, saved to the phone with
 * expo-sqlite's key-value storage, read by components with useStore().
 */
import { useSyncExternalStore } from 'react';
import { Storage } from 'expo-sqlite/kv-store';

export type Store<T> = {
  get: () => T;
  set: (next: T | ((prev: T) => T)) => void;
  subscribe: (listener: () => void) => () => void;
};

export function createStore<T extends object>(key: string, initial: T): Store<T> {
  let value = initial;
  try {
    const raw = Storage.getItemSync(key);
    if (raw) value = { ...initial, ...JSON.parse(raw) };
  } catch {
    // A damaged entry must never stop the game from opening.
  }
  const listeners = new Set<() => void>();
  return {
    get: () => value,
    set(next) {
      value = typeof next === 'function' ? (next as (prev: T) => T)(value) : next;
      try {
        Storage.setItemSync(key, JSON.stringify(value));
      } catch {
        // Keep playing even if saving fails.
      }
      listeners.forEach((l) => l());
    },
    subscribe(listener) {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
  };
}

export function useStore<T>(store: Store<T>): T {
  return useSyncExternalStore(store.subscribe, store.get, store.get);
}
