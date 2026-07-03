/**
 * Injected runtime (clock + storage + API client) as React context, so tests
 * pin time, seed storage and mock the network without touching globals, and
 * the shell never reads Date.now()/localStorage/fetch directly.
 *
 * Local state is exposed through a small subscribe store (one per storage
 * instance) so every consumer re-renders when WS-14 surfaces write it
 * (sign-in marker, preference toggles).
 */
import {
  createContext,
  useContext,
  useSyncExternalStore,
  type ReactElement,
  type ReactNode,
} from 'react';
import { createApiClient, type ApiClient } from '@burnfront/api-client';
import { systemClock, type Clock } from './clock';
import {
  browserStorage,
  loadLocalState,
  saveLocalState,
  type LocalState,
  type StorageLike,
} from './localState';

export interface Runtime {
  readonly clock: Clock;
  readonly storage: StorageLike;
  /** Typed contract client (CLAUDE.md rule 2); tests inject a mock. */
  readonly api?: ApiClient;
}

const RuntimeContext = createContext<Runtime | null>(null);

export function RuntimeProvider(props: {
  readonly runtime: Runtime;
  readonly children: ReactNode;
}): ReactElement {
  return <RuntimeContext.Provider value={props.runtime}>{props.children}</RuntimeContext.Provider>;
}

/**
 * Sanctum CSRF double-submit token, read from the XSRF-TOKEN cookie the
 * same-origin Laravel app sets (openapi.yaml info.description). A fresh
 * browser has no cookie yet: the client wrapper itself then runs the
 * lead-sanctioned `GET /sanctum/csrf-cookie` bootstrap before the mutating
 * call — see `bootstrapCsrfCookie` in packages/api-client/src/client.ts
 * (tasks/WS-14/STATUS.md decision #2).
 */
function xsrfCookieToken(): string | null {
  const match = /(?:^|;\s*)XSRF-TOKEN=([^;]+)/.exec(document.cookie);
  return match?.[1] === undefined ? null : decodeURIComponent(match[1]);
}

let fallback: Runtime | null = null;
let fallbackApi: ApiClient | null = null;

/** The real client — built lazily so importing this module stays side-effect free. */
function defaultApi(): ApiClient {
  fallbackApi ??= createApiClient({ getCsrfToken: xsrfCookieToken });
  return fallbackApi;
}

/** Browser default runtime. */
function defaultRuntime(): Runtime {
  fallback ??= { clock: systemClock, storage: browserStorage(), api: defaultApi() };
  return fallback;
}

export function useRuntime(): Runtime {
  return useContext(RuntimeContext) ?? defaultRuntime();
}

/** The contract client from the runtime (real in the browser, mock in tests). */
export function useApi(): ApiClient {
  return useRuntime().api ?? defaultApi();
}

export type LocalStateUpdater = (state: LocalState) => LocalState;

interface LocalStateStore {
  readonly get: () => LocalState;
  readonly set: (updater: LocalStateUpdater) => void;
  readonly subscribe: (listener: () => void) => () => void;
}

const stores = new WeakMap<StorageLike, LocalStateStore>();

function storeFor(storage: StorageLike): LocalStateStore {
  const existing = stores.get(storage);
  if (existing !== undefined) return existing;
  let snapshot = loadLocalState(storage);
  const listeners = new Set<() => void>();
  const store: LocalStateStore = {
    get: () => snapshot,
    set: (updater) => {
      snapshot = updater(snapshot);
      saveLocalState(storage, snapshot);
      for (const listener of listeners) listener();
    },
    subscribe: (listener) => {
      listeners.add(listener);
      return () => {
        listeners.delete(listener);
      };
    },
  };
  stores.set(storage, store);
  return store;
}

/** The anonymous-first local state — live: re-renders on every local write. */
export function useLocalState(): LocalState {
  const { storage } = useRuntime();
  const store = storeFor(storage);
  return useSyncExternalStore(store.subscribe, store.get);
}

/** Writes local state (persist + notify every `useLocalState` consumer). */
export function useLocalStateUpdate(): (updater: LocalStateUpdater) => void {
  const { storage } = useRuntime();
  return storeFor(storage).set;
}
