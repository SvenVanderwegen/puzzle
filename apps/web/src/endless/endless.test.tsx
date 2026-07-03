/**
 * Endless feature flows on the real router shell with a mocked worker and
 * api-client: generation loading copy (rotation), play-to-contain for guests
 * (local-only stats) and signed-in users (rated submission → rating refresh),
 * cancel/regenerate races through the UI, dial + history persistence, resume
 * from the persisted snapshot, and instant "next" via pre-generation.
 */
import { createMemoryHistory, RouterProvider } from '@tanstack/react-router';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { BoardSpec } from '@burnfront/engine';
import type { SolveSubmission } from '@burnfront/game-core';
import { createAppRouter } from '../router';
import type { Clock } from '../state/clock';
import {
  defaultLocalState,
  loadLocalState,
  memoryStorage,
  saveLocalState,
  type LocalState,
  type StorageLike,
} from '../state/localState';
import { RuntimeProvider } from '../state/runtime';
import { t } from '../strings';
import type { EndlessApi, RatingData, SubmitOutcome } from './api';
import { EndlessDepsProvider, type EndlessDeps } from './deps';
import type { WorkerLike } from './generatorClient';
import { loadPrefs, saveDial } from './prefs';
import type { GenerateRequest } from './protocol';
import { seededRng } from './rng';

const NOW = Date.parse('2026-07-03T21:18:51Z');

/** The reference demo board — its unique solution shades cells 8, 11, 17, 22. */
const demoBoard: BoardSpec = {
  rows: 5,
  cols: 5,
  spark: { r: 3, c: 0 },
  breaks: 4,
  clues: [
    { r: 1, c: 4, m: 8 },
    { r: 2, c: 2, m: 5 },
    { r: 3, c: 1, m: 1 },
    { r: 4, c: 1, m: 2 },
    { r: 4, c: 3, m: 8 },
  ],
};
const BREAK_CELLS = [8, 11, 17, 22];

/** Worker double: echoes tokens with a shape-matching canned board. */
class FakeWorker implements WorkerLike {
  auto: boolean;
  readonly requests: GenerateRequest[] = [];
  terminated = false;
  private readonly listeners: ((event: { data: unknown }) => void)[] = [];
  private pending: GenerateRequest[] = [];

  constructor(auto = true) {
    this.auto = auto;
  }

  postMessage(message: unknown): void {
    const request = message as GenerateRequest;
    this.requests.push(request);
    if (this.auto) {
      queueMicrotask(() => {
        this.respond(request);
      });
    } else {
      this.pending.push(request);
    }
  }

  addEventListener(_type: 'message', listener: (event: { data: unknown }) => void): void {
    this.listeners.push(listener);
  }

  terminate(): void {
    this.terminated = true;
  }

  flush(): void {
    const queued = this.pending;
    this.pending = [];
    for (const request of queued) this.respond(request);
  }

  private respond(request: GenerateRequest): void {
    const board: BoardSpec =
      request.rows === 5
        ? demoBoard
        : {
            rows: request.rows,
            cols: request.cols,
            spark: { r: 0, c: 0 },
            breaks: request.breaks,
            clues: [
              { r: request.rows - 1, c: request.cols - 1, m: request.rows + request.cols - 2 },
            ],
          };
    const data = { kind: 'result', token: request.token, board, deductionSteps: 17 };
    for (const listener of this.listeners) listener({ data });
  }
}

interface FakeApi extends EndlessApi {
  readonly submissions: { payload: SolveSubmission; key: string }[];
  ratingFetches: number;
}

function fakeApi(
  outcome: SubmitOutcome = {
    kind: 'accepted',
    result: { solve_id: 's-1', valid: true, suspect: false, rating_pending: true },
  },
  rating: RatingData | null = {
    rating: 1512.4,
    rd: 120,
    volatility: 0.06,
    games: 12,
    calibrating: false,
    sparkline: [1500, 1512.4],
  },
): FakeApi {
  const submissions: { payload: SolveSubmission; key: string }[] = [];
  const api: FakeApi = {
    submissions,
    ratingFetches: 0,
    submitSolve: (payload, key) => {
      submissions.push({ payload, key });
      return Promise.resolve(outcome);
    },
    fetchRating: () => {
      api.ratingFetches += 1;
      return Promise.resolve(rating);
    },
  };
  return api;
}

interface Harness {
  readonly storage: StorageLike;
  readonly worker: FakeWorker;
  readonly api: FakeApi;
  readonly unmount: () => void;
}

async function renderPlay(
  options: {
    path?: string;
    state?: LocalState;
    storage?: StorageLike;
    worker?: FakeWorker;
    api?: FakeApi;
  } = {},
): Promise<Harness> {
  const storage = options.storage ?? memoryStorage();
  if (options.storage === undefined) {
    saveLocalState(storage, options.state ?? { ...defaultLocalState(), firstShiftDone: true });
  }
  const worker = options.worker ?? new FakeWorker();
  const api = options.api ?? fakeApi();
  const deps: EndlessDeps = {
    createWorker: () => worker,
    seedSource: () => [1, 2, 3, 4],
    api,
    recordEnv: {
      compressor: { compress: (data) => data },
      hasher: { sha256Hex: () => 'ab'.repeat(32) },
      rng: seededRng([5, 6, 7, 8]),
    },
  };
  const clock: Clock = { now: () => NOW };
  const router = createAppRouter(
    createMemoryHistory({ initialEntries: [options.path ?? '/play'] }),
  );
  const { unmount } = render(
    <RuntimeProvider runtime={{ clock, storage }}>
      <EndlessDepsProvider deps={deps}>
        <RouterProvider router={router} />
      </EndlessDepsProvider>
    </RuntimeProvider>,
  );
  await waitFor(() => {
    expect(document.querySelector('main h1')).not.toBeNull();
  });
  return { storage, worker, api, unmount };
}

async function containBoard(): Promise<void> {
  const cells = await screen.findAllByRole('gridcell');
  expect(cells).toHaveLength(25);
  for (const index of BREAK_CELLS) {
    const cell = cells[index];
    if (cell === undefined) throw new Error(`missing cell ${String(index)}`);
    fireEvent.pointerDown(cell, { button: 0 });
    fireEvent.pointerUp(cell, { button: 0 });
  }
}

beforeEach(() => {
  // Reduced motion: BurnReplay renders its stepper (no animation timers).
  vi.spyOn(window, 'matchMedia').mockImplementation(
    (query: string) =>
      ({
        matches: query.includes('prefers-reduced-motion'),
        media: query,
        onchange: null,
        addListener: () => undefined,
        removeListener: () => undefined,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
        dispatchEvent: () => false,
      }) as MediaQueryList,
  );
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.useRealTimers();
});

describe('generation loading', () => {
  it('shows the fairness loading copy while the worker generates', async () => {
    const worker = new FakeWorker(false);
    await renderPlay({ worker });
    expect(screen.getByTestId('endless-loading')).toHaveTextContent(t('play.loading.endless.1'));
    expect(screen.queryByRole('grid')).not.toBeInTheDocument();
  });

  it('keeps the page interactive during generation: dial switch cancels and re-generates', async () => {
    const worker = new FakeWorker(false);
    await renderPlay({ worker });
    // Default tier for a 1200 rating is crew.
    expect(worker.requests[0]).toMatchObject({ rows: 6, cols: 6, breaks: 8 });

    fireEvent.click(screen.getByRole('button', { name: 'Hotshot 7×7' }));
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Hotshot 7×7' })).toHaveAttribute(
        'aria-pressed',
        'true',
      );
    });
    expect(worker.requests[1]).toMatchObject({ rows: 7, cols: 7, breaks: 12 });

    // Both answers arrive; the superseded crew board must NOT surface.
    act(() => {
      worker.flush();
    });
    const grid = await screen.findByRole('grid');
    expect(grid.querySelectorAll('[role="gridcell"]')).toHaveLength(49);
  });

  it('recommends the rating-band tier on the dials', async () => {
    await renderPlay();
    await screen.findByRole('grid');
    const chips = [...screen.getByTestId('tier-dials').querySelectorAll('button')];
    expect(chips.map((chip) => chip.getAttribute('data-recommended'))).toEqual([
      'false',
      'true',
      'false',
    ]);
  });
});

describe('guest play-to-contain', () => {
  it('mounts the board, replays the burn and shows local-only stats', async () => {
    const { api, storage } = await renderPlay({ path: '/play?tier=lookout' });
    await containBoard();

    // The burn replay + stats card land; no submission for guests.
    expect(await screen.findByTestId('burn-replay')).toBeInTheDocument();
    const stats = screen.getByTestId('endless-stats');
    expect(stats).toHaveTextContent(t('play.stats.time', { time: '0:00' }));
    expect(stats).toHaveTextContent(t('play.stats.clean'));
    expect(stats).toHaveTextContent(t('hub.endless.solved', { n: 1 }));
    expect(stats).toHaveTextContent(t('streak.guestNote'));
    expect(api.submissions).toHaveLength(0);
    expect(screen.queryByTestId('rating-pending')).not.toBeInTheDocument();

    // Local history persisted for the hub lane.
    const local = loadLocalState(storage);
    expect(local.endless.solvedByTier.lookout).toBe(1);
    expect(local.endless.inProgress).toBe(false);
    expect(loadPrefs(storage).history.lookout).toMatchObject({ solved: 1 });
  });

  it('flags an invalid full shading with the report copy, then clears it on edit', async () => {
    await renderPlay({ path: '/play?tier=lookout' });
    const cells = await screen.findAllByRole('gridcell');
    for (const index of [0, 1, 2, 3]) {
      const cell = cells[index];
      if (cell === undefined) throw new Error('missing cell');
      fireEvent.pointerDown(cell, { button: 0 });
      fireEvent.pointerUp(cell, { button: 0 });
    }
    expect(screen.getByText(t('play.wrong', { n: 4 }))).toBeInTheDocument();
    expect(screen.queryByTestId('burn-replay')).not.toBeInTheDocument();

    const cell = cells[0];
    if (cell === undefined) throw new Error('missing cell');
    fireEvent.pointerDown(cell, { button: 0 }); // break → dot: back to 3 breaks
    fireEvent.pointerUp(cell, { button: 0 });
    expect(screen.queryByText(t('play.wrong', { n: 4 }))).not.toBeInTheDocument();
  });
});

describe('signed-in play-to-contain (rated, ADR-0006)', () => {
  const signedIn: LocalState = {
    ...defaultLocalState(),
    firstShiftDone: true,
    account: { email: 'crew@example.com' },
  };

  it('submits the endless solve and refreshes the rating chip', async () => {
    const api = fakeApi();
    await renderPlay({ path: '/play?tier=lookout', state: signedIn, api });
    await containBoard();

    const chip = await screen.findByTestId('rating-chip');
    expect(chip).toHaveTextContent(t('play.stats.ratingDelta', { rating: 1512, delta: '+12' }));
    expect(api.ratingFetches).toBe(1);

    expect(api.submissions).toHaveLength(1);
    const submission = api.submissions[0];
    if (submission === undefined) throw new Error('no submission');
    expect(submission.key).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    );
    expect(submission.payload.mode).toBe('endless');
    expect(submission.payload.endless_spec?.spark).toEqual([3, 0]);
    expect(submission.payload.deduction_steps).toBe(17);
    expect(submission.payload.shaded).toBe('0000000010010000010000100');
    expect(submission.payload).not.toHaveProperty('puzzle_id');
    // No guest nudge for account holders.
    expect(screen.getByTestId('endless-stats')).not.toHaveTextContent(t('streak.guestNote'));
  });

  it('shows the calibrating line during the first ten rated solves', async () => {
    const api = fakeApi(undefined, {
      rating: 1490,
      rd: 300,
      volatility: 0.06,
      games: 5,
      calibrating: true,
    });
    await renderPlay({ path: '/play?tier=lookout', state: signedIn, api });
    await containBoard();
    const chip = await screen.findByTestId('rating-chip');
    expect(chip).toHaveTextContent(t('play.stats.calibrating', { n: 5 }));
  });

  it('keeps the pending indicator when the rating refresh is unavailable', async () => {
    const api = fakeApi(undefined, null);
    await renderPlay({ path: '/play?tier=lookout', state: signedIn, api });
    await containBoard();
    // Glyph-only until a COPY.md key lands (see tasks/WS-11/STATUS.md).
    expect(await screen.findByTestId('rating-pending')).toHaveTextContent(
      t('endless.rating.pending'),
    );
    expect(screen.queryByTestId('rating-chip')).not.toBeInTheDocument();
  });

  it('surfaces a rejected submission with the error copy', async () => {
    const api = fakeApi({ kind: 'invalid' }, null);
    await renderPlay({ path: '/play?tier=lookout', state: signedIn, api });
    await containBoard();
    expect(await screen.findByText(t('error.generic'))).toBeInTheDocument();
  });
});

describe('next board and regenerate', () => {
  it('pre-generates during play so "next" is instant (no new active request)', async () => {
    const { worker } = await renderPlay({ path: '/play?tier=lookout' });
    await containBoard();
    await screen.findByTestId('endless-stats');
    // Initial board (1) + pre-generated next (2), already cached.
    expect(worker.requests).toHaveLength(2);

    fireEvent.click(
      screen.getByRole('button', { name: t('endless.new', { tier: 'Lookout 5×5' }) }),
    );
    // The cached board mounts; only the NEXT prefetch hits the worker.
    expect(await screen.findByRole('grid')).toBeInTheDocument();
    await waitFor(() => {
      expect(worker.requests).toHaveLength(3);
    });
    expect(screen.queryByTestId('endless-stats')).not.toBeInTheDocument();
  });

  it('regenerates mid-solve via the keep-burning control', async () => {
    const { worker } = await renderPlay({ path: '/play?tier=lookout' });
    await screen.findByRole('grid');
    const before = worker.requests.length;
    fireEvent.click(
      screen.getByRole('button', { name: t('endless.new', { tier: 'Lookout 5×5' }) }),
    );
    expect(await screen.findByRole('grid')).toBeInTheDocument();
    await waitFor(() => {
      expect(worker.requests.length).toBeGreaterThan(before);
    });
  });

  it('survives rapid sequential regenerates without a stale board or crash', async () => {
    const { worker } = await renderPlay({ path: '/play?tier=lookout' });
    const name = t('endless.new', { tier: 'Lookout 5×5' });
    for (let i = 0; i < 3; i++) {
      const button = await screen.findByRole('button', { name });
      fireEvent.click(button);
      expect(await screen.findByRole('grid')).toBeInTheDocument();
    }
    expect(screen.getAllByRole('grid')).toHaveLength(1);
    expect(worker.requests.length).toBeGreaterThanOrEqual(4);
  });
});

describe('persistence across reload', () => {
  it('remembers an explicitly chosen dial', async () => {
    const storage = memoryStorage();
    saveLocalState(storage, { ...defaultLocalState(), firstShiftDone: true });
    const first = await renderPlay({ storage });
    fireEvent.click(await screen.findByRole('button', { name: 'Hotshot 7×7' }));
    await waitFor(() => {
      expect(loadPrefs(storage).dial).toBe('hotshot');
    });
    first.unmount();

    await renderPlay({ storage, path: '/play' });
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Hotshot 7×7' })).toHaveAttribute(
        'aria-pressed',
        'true',
      );
    });
  });

  it('restores the mid-solve board (marks intact) from the snapshot', async () => {
    const storage = memoryStorage();
    saveLocalState(storage, { ...defaultLocalState(), firstShiftDone: true });
    const first = await renderPlay({ storage, path: '/play?tier=lookout' });
    const cells = await screen.findAllByRole('gridcell');
    const cell = cells[8];
    if (cell === undefined) throw new Error('missing cell');
    fireEvent.pointerDown(cell, { button: 0 });
    fireEvent.pointerUp(cell, { button: 0 });
    expect(loadLocalState(storage).endless.inProgress).toBe(true);
    first.unmount();

    const second = await renderPlay({ storage, path: '/play?tier=lookout' });
    const grid = await screen.findByRole('grid');
    const restored = grid.querySelectorAll('.bf-cell--break');
    expect(restored).toHaveLength(1);
    expect(restored[0]).toHaveAttribute('data-cell', 'D2');
    // Restored without generating: only the prefetch reached the worker.
    expect(second.worker.requests).toHaveLength(1);
  });

  it('a pre-seeded dial wins over the rating recommendation on /play', async () => {
    const storage = memoryStorage();
    saveLocalState(storage, { ...defaultLocalState(), firstShiftDone: true });
    saveDial(storage, 'lookout');
    await renderPlay({ storage, path: '/play' });
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Lookout 5×5' })).toHaveAttribute(
        'aria-pressed',
        'true',
      );
    });
  });
});
