/**
 * Shared feature-test harness: full app (router + chrome) rendered against
 * an injected runtime — pinned clock, seeded memory storage, mocked API.
 */
import { createMemoryHistory, RouterProvider } from '@tanstack/react-router';
import { render, waitFor } from '@testing-library/react';
import { expect } from 'vitest';
import type { ApiClient } from '@burnfront/api-client';
import { createAppRouter } from '../router';
import type { Clock } from '../state/clock';
import {
  defaultLocalState,
  memoryStorage,
  saveLocalState,
  type LocalState,
  type StorageLike,
} from '../state/localState';
import { RuntimeProvider } from '../state/runtime';

export const TEST_NOW = Date.parse('2026-07-03T21:18:51Z');
export const TEST_TODAY = '2026-07-03';

export function fixedClock(ms: number): Clock {
  return { now: () => ms };
}

export interface RenderAppOptions {
  readonly state?: LocalState;
  readonly api?: ApiClient;
  readonly nowMs?: number;
}

export interface RenderedApp {
  readonly router: ReturnType<typeof createAppRouter>;
  readonly storage: StorageLike;
}

export async function renderAppAt(
  path: string,
  options: RenderAppOptions = {},
): Promise<RenderedApp> {
  const storage = memoryStorage();
  saveLocalState(storage, options.state ?? { ...defaultLocalState(), firstShiftDone: true });
  const router = createAppRouter(createMemoryHistory({ initialEntries: [path] }));
  render(
    <RuntimeProvider
      runtime={{
        clock: fixedClock(options.nowMs ?? TEST_NOW),
        storage,
        ...(options.api === undefined ? {} : { api: options.api }),
      }}
    >
      <RouterProvider router={router} />
    </RuntimeProvider>,
  );
  await waitFor(() => {
    expect(document.querySelector('main h1')).not.toBeNull();
  });
  return { router, storage };
}
