/**
 * Injected environment of the Endless feature (same pattern as
 * state/runtime.tsx): worker factory, seed source, API surface and the
 * solve-record ports, so tests mock the worker and the api-client while
 * production wires the real ones lazily.
 */
import { createContext, useContext, type ReactElement, type ReactNode } from 'react';
import { createEndlessApi, type EndlessApi } from './api';
import type { WorkerFactory } from './generatorClient';
import { cryptoRng, cryptoSeed } from './rng';
import type { SolveRecordEnv } from './submit';
import { gzipCompressor, webHasher } from './webDeps';
import { createGeneratorWorker } from './workerFactory';

export interface EndlessDeps {
  readonly createWorker: WorkerFactory;
  readonly seedSource: () => readonly number[];
  readonly api: EndlessApi;
  readonly recordEnv: SolveRecordEnv;
}

const EndlessDepsContext = createContext<EndlessDeps | null>(null);

export function EndlessDepsProvider(props: {
  readonly deps: EndlessDeps;
  readonly children: ReactNode;
}): ReactElement {
  return (
    <EndlessDepsContext.Provider value={props.deps}>{props.children}</EndlessDepsContext.Provider>
  );
}

let fallback: EndlessDeps | null = null;

/** Browser default — built lazily so importing this module stays side-effect free. */
function defaultDeps(): EndlessDeps {
  fallback ??= {
    createWorker: createGeneratorWorker,
    seedSource: cryptoSeed,
    api: createEndlessApi(),
    recordEnv: { compressor: gzipCompressor, hasher: webHasher, rng: cryptoRng() },
  };
  return fallback;
}

export function useEndlessDeps(): EndlessDeps {
  return useContext(EndlessDepsContext) ?? defaultDeps();
}
