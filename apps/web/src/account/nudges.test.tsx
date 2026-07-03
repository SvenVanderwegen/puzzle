/**
 * Guest→account nudge placement (product §1 — exactly three, never
 * modal-blocking): the decision's three states, the stats-card component's
 * rendering for each, and the persistent Guest chip's /login wiring.
 */
import {
  createMemoryHistory,
  createRootRoute,
  createRouter,
  RouterProvider,
} from '@tanstack/react-router';
import { render, screen, waitFor } from '@testing-library/react';
import type { ReactElement } from 'react';
import { describe, expect, it } from 'vitest';
import type { createAppRouter } from '../router';
import {
  defaultLocalState,
  memoryStorage,
  saveLocalState,
  type LocalState,
} from '../state/localState';
import { RuntimeProvider } from '../state/runtime';
import { t } from '../strings';
import { renderAppAt } from '../testing/renderApp';
import { decidePostSolveNudge } from './nudges';
import { PostSolveNudge } from './PostSolveNudge';

const base: LocalState = { ...defaultLocalState(), firstShiftDone: true };

function withStreak(current: number): LocalState {
  return { ...base, streak: { current, best: current, lastDailyDate: '2026-07-03' } };
}

describe('decidePostSolveNudge — the three states', () => {
  it('signed-in users are never nudged', () => {
    expect(decidePostSolveNudge({ ...withStreak(5), account: { email: 'a@b.c' } })).toBeNull();
  });

  it('guests below streak day 3 get the one-line footer note', () => {
    expect(decidePostSolveNudge(withStreak(0))).toBe('guest-note');
    expect(decidePostSolveNudge(withStreak(2))).toBe('guest-note');
  });

  it('guests at streak day 3+ get the primary protect nudge', () => {
    expect(decidePostSolveNudge(withStreak(3))).toBe('streak-protect');
    expect(decidePostSolveNudge(withStreak(7))).toBe('streak-protect');
  });

  it('guests past 7 days get the capped variant — the merge carries 7, the nudge must not promise more', () => {
    expect(decidePostSolveNudge(withStreak(8))).toBe('streak-protect-capped');
    expect(decidePostSolveNudge(withStreak(13))).toBe('streak-protect-capped');
  });
});

/**
 * Mounts the component the way WS-10/11 will: inside a routed stats card
 * (it uses <Link>, so a router context is required). The harness wrapper
 * proves mount completion even when the nudge renders nothing.
 */
async function renderNudge(state: LocalState): Promise<void> {
  const storage = memoryStorage();
  saveLocalState(storage, state);
  const StatsCardStandIn = (): ReactElement => (
    <div data-harness="stats-card">
      <PostSolveNudge />
    </div>
  );
  const router = createRouter({
    routeTree: createRootRoute({ component: StatsCardStandIn }),
    history: createMemoryHistory({ initialEntries: ['/'] }),
  });
  render(
    <RuntimeProvider runtime={{ clock: { now: () => 0 }, storage }}>
      <RouterProvider router={router as unknown as ReturnType<typeof createAppRouter>} />
    </RuntimeProvider>,
  );
  await waitFor(() => {
    expect(document.querySelector('[data-harness="stats-card"]')).not.toBeNull();
  });
}

describe('PostSolveNudge — stats-card footer component', () => {
  it('renders the guest note as a plain, non-blocking line', async () => {
    await renderNudge(withStreak(1));
    await waitFor(() => {
      expect(screen.getByText(t('streak.guestNote'))).toBeInTheDocument();
    });
    expect(document.querySelector('[data-nudge="guest-note"]')).not.toBeNull();
    expect(document.querySelector('[role="dialog"]')).toBeNull();
  });

  it('renders the protect nudge linking to /login at streak day 3', async () => {
    await renderNudge(withStreak(3));
    await waitFor(() => {
      expect(screen.getByRole('link', { name: t('streak.protect', { n: 3 }) })).toHaveAttribute(
        'href',
        '/login',
      );
    });
    expect(document.querySelector('[data-nudge="streak-protect"]')).not.toBeNull();
  });

  it('renders the honest capped line past 7 days — real {n}, real merge behavior', async () => {
    await renderNudge(withStreak(12));
    await waitFor(() => {
      expect(
        screen.getByRole('link', { name: t('streak.protect.capped', { n: 12 }) }),
      ).toHaveAttribute('href', '/login');
    });
    expect(document.querySelector('[data-nudge="streak-protect-capped"]')).not.toBeNull();
    // The uncapped promise is not shown alongside.
    expect(document.querySelector('[data-nudge="streak-protect"]')).toBeNull();
  });

  it('renders nothing for signed-in users', async () => {
    await renderNudge({ ...withStreak(4), account: { email: 'a@b.c' } });
    expect(document.querySelector('[data-nudge]')).toBeNull();
  });
});

describe('the persistent Guest chip (nudge 3)', () => {
  it('links to /login from the chrome header while guest', async () => {
    await renderAppAt('/', { state: withStreak(1) });
    const chip = document.querySelector('[data-nudge="guest-chip"]');
    expect(chip).not.toBeNull();
    expect(chip).toHaveAttribute('href', '/login');
    expect(chip).toHaveTextContent(t('hub.guest'));
  });

  it('is replaced by the account chip when signed in', async () => {
    await renderAppAt('/', { state: { ...withStreak(1), account: { email: 'crew@example.com' } } });
    expect(document.querySelector('[data-nudge="guest-chip"]')).toBeNull();
    expect(screen.getByText('crew@example.com')).toBeInTheDocument();
  });
});
