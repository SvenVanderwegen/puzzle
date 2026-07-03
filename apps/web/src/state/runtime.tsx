/**
 * Injected runtime (clock + storage) as React context, so tests pin time and
 * seed storage without touching globals, and the shell never reads
 * Date.now()/localStorage directly.
 */
import { createContext, useContext, useMemo, type ReactElement, type ReactNode } from 'react';
import { systemClock, type Clock } from './clock';
import { browserStorage, loadLocalState, type LocalState, type StorageLike } from './localState';

export interface Runtime {
  readonly clock: Clock;
  readonly storage: StorageLike;
}

const RuntimeContext = createContext<Runtime | null>(null);

export function RuntimeProvider(props: {
  readonly runtime: Runtime;
  readonly children: ReactNode;
}): ReactElement {
  return <RuntimeContext.Provider value={props.runtime}>{props.children}</RuntimeContext.Provider>;
}

let fallback: Runtime | null = null;

/** Browser default — built lazily so importing this module stays side-effect free. */
function defaultRuntime(): Runtime {
  fallback ??= { clock: systemClock, storage: browserStorage() };
  return fallback;
}

export function useRuntime(): Runtime {
  return useContext(RuntimeContext) ?? defaultRuntime();
}

/** The anonymous-first local state, loaded once per mount. */
export function useLocalState(): LocalState {
  const { storage } = useRuntime();
  return useMemo(() => loadLocalState(storage), [storage]);
}
