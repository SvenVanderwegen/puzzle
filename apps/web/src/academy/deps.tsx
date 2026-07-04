/**
 * Injected environment of the Academy feature (same pattern as
 * state/runtime.tsx and endless/deps.tsx): the tutorial_step analytics sink and
 * the RNG that seeds pack-solve idempotency keys. Production wires the browser
 * defaults lazily; tests inject a spy sink and a seeded RNG.
 *
 * The api-client and clock come from the shared runtime (useApi/useRuntime), so
 * they are NOT duplicated here.
 */
import { createContext, useContext, type ReactElement, type ReactNode } from 'react';
import type { Rng } from '@burnfront/engine';
import { cryptoRng } from '../endless/rng';
import { noopTutorialStep, type TutorialStepSink } from './events';

export interface AcademyDeps {
  readonly onTutorialStep: TutorialStepSink;
  /** Randomness for pack-solve UUID v7 keys (never Math.random). */
  readonly rng: Rng;
}

const AcademyDepsContext = createContext<AcademyDeps | null>(null);

export function AcademyDepsProvider(props: {
  readonly deps: AcademyDeps;
  readonly children: ReactNode;
}): ReactElement {
  return (
    <AcademyDepsContext.Provider value={props.deps}>{props.children}</AcademyDepsContext.Provider>
  );
}

let fallback: AcademyDeps | null = null;

/** Browser default — built lazily so importing this module stays side-effect free. */
function defaultDeps(): AcademyDeps {
  fallback ??= { onTutorialStep: noopTutorialStep, rng: cryptoRng() };
  return fallback;
}

export function useAcademyDeps(): AcademyDeps {
  return useContext(AcademyDepsContext) ?? defaultDeps();
}
