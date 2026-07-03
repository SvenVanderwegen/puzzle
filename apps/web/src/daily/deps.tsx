/**
 * Injected environment of the Daily feature (same pattern as endless/deps.tsx
 * and state/runtime.tsx): the CDN content port, the solve-record ports
 * (gzip/sha256/RNG, shared with endless) and the share capabilities, so tests
 * mock the network and the share sheet while production wires the real ones
 * lazily. The API client itself comes from the runtime (useApi()).
 */
import { createContext, useContext, type ReactElement, type ReactNode } from 'react';
import { cryptoRng } from '../endless/rng';
import type { SolveRecordEnv } from '../endless/submit';
import { gzipCompressor, webHasher } from '../endless/webDeps';
import { createBrowserContent, type DailyContent } from './content';
import { browserShareEnv, type ShareEnv } from './share';

export interface DailyDeps {
  readonly content: DailyContent;
  readonly recordEnv: SolveRecordEnv;
  readonly shareEnv: ShareEnv;
}

const DailyDepsContext = createContext<DailyDeps | null>(null);

export function DailyDepsProvider(props: {
  readonly deps: DailyDeps;
  readonly children: ReactNode;
}): ReactElement {
  return <DailyDepsContext.Provider value={props.deps}>{props.children}</DailyDepsContext.Provider>;
}

let fallback: DailyDeps | null = null;

/** Browser default — built lazily so importing this module stays side-effect free. */
function defaultDeps(): DailyDeps {
  fallback ??= {
    content: createBrowserContent(),
    recordEnv: { compressor: gzipCompressor, hasher: webHasher, rng: cryptoRng() },
    shareEnv: browserShareEnv(),
  };
  return fallback;
}

export function useDailyDeps(): DailyDeps {
  return useContext(DailyDepsContext) ?? defaultDeps();
}
