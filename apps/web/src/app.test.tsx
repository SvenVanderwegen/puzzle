/**
 * Shell integration: route smoke for all eight routes (chrome + heading +
 * catalog strings + data-ws feature markers), route-change focus management
 * and aria-live announcements, the hub decision table rendered end-to-end
 * against seeded local state, the countdown with an injected clock, and the
 * offline notices.
 */
import { createMemoryHistory, RouterProvider } from '@tanstack/react-router';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { createAppRouter } from './router';
import type { Clock } from './state/clock';
import {
  defaultLocalState,
  memoryStorage,
  saveLocalState,
  type LocalState,
  type StorageLike,
} from './state/localState';
import { RuntimeProvider } from './state/runtime';
import { t } from './strings';

const NOW = Date.parse('2026-07-03T21:18:51Z'); // 02:41:09 to midnight UTC
const TODAY = '2026-07-03';

function fixedClock(ms: number): Clock {
  return { now: () => ms };
}

interface RenderedApp {
  router: ReturnType<typeof createAppRouter>;
  storage: StorageLike;
}

async function renderApp(
  path: string,
  options: { state?: LocalState; nowMs?: number } = {},
): Promise<RenderedApp> {
  const storage = memoryStorage();
  saveLocalState(storage, options.state ?? { ...defaultLocalState(), firstShiftDone: true });
  const router = createAppRouter(createMemoryHistory({ initialEntries: [path] }));
  render(
    <RuntimeProvider runtime={{ clock: fixedClock(options.nowMs ?? NOW), storage }}>
      <RouterProvider router={router} />
    </RuntimeProvider>,
  );
  await waitFor(() => {
    expect(document.querySelector('main h1')).not.toBeNull();
  });
  return { router, storage };
}

describe('route smoke — every route renders chrome, heading and strings', () => {
  it('/ renders the hub', async () => {
    await renderApp('/');
    expect(screen.getByRole('heading', { level: 1, name: t('app.title') })).toBeInTheDocument();
    expect(screen.getByText(t('app.eyebrow'))).toBeInTheDocument();
  });

  it('/daily renders the daily stub with its WS-10 marker', async () => {
    await renderApp('/daily');
    expect(
      screen.getByRole('heading', { level: 1, name: t('hub.lane.daily') }),
    ).toBeInTheDocument();
    expect(document.querySelector('main [data-ws="WS-10"]')).not.toBeNull();
    expect(screen.getByText(t('daily.loading'))).toBeInTheDocument();
  });

  it('/daily/$date flags a past incident with the live banner', async () => {
    await renderApp('/daily/2026-07-01');
    // 2026-07-01 was a Wednesday (UTC).
    expect(screen.getByText(t('daily.pastBanner', { weekday: 'Wednesday' }))).toBeInTheDocument();
  });

  it('/daily/$date for today shows no past banner', async () => {
    await renderApp(`/daily/${TODAY}`);
    expect(screen.queryByText(/incident — today's is live/)).not.toBeInTheDocument();
  });

  it('/play renders the endless stub, tier from the search param', async () => {
    await renderApp('/play?tier=hotshot');
    expect(
      screen.getByRole('heading', { level: 1, name: t('hub.lane.endless') }),
    ).toBeInTheDocument();
    expect(document.querySelector('main [data-ws="WS-11"]')).not.toBeNull();
    expect(screen.getByText('Hotshot 7×7')).toBeInTheDocument();
  });

  it('/play without a tier falls back to the rating recommendation', async () => {
    await renderApp('/play');
    expect(screen.getByText('Crew 6×6')).toBeInTheDocument();
  });

  it('/academy and /academy/$slug render the academy stubs', async () => {
    await renderApp('/academy');
    expect(
      screen.getByRole('heading', { level: 1, name: t('hub.lane.academy') }),
    ).toBeInTheDocument();
    expect(document.querySelector('main [data-ws="WS-12"]')).not.toBeNull();

    await renderApp('/academy/reading-the-numbers');
    expect(screen.getByText('reading-the-numbers')).toBeInTheDocument();
  });

  it('/me renders the guest record with the guest note', async () => {
    await renderApp('/me');
    expect(
      screen.getByRole('heading', { level: 1, name: t('hub.lane.record') }),
    ).toBeInTheDocument();
    expect(screen.getByText(t('streak.guestNote'))).toBeInTheDocument();
  });

  it('/settings renders the device preferences and the guest pointer (WS-14)', async () => {
    await renderApp('/settings');
    expect(
      screen.getByRole('heading', { level: 1, name: t('settings.title') }),
    ).toBeInTheDocument();
    expect(screen.getByLabelText(t('settings.sound'))).toBeInTheDocument();
    expect(screen.getByLabelText(t('settings.highContrast'))).toBeInTheDocument();
    // Account rows (export/delete) require a session — SettingsPage.test.tsx.
    expect(screen.getByRole('link', { name: t('streak.guestNote') })).toBeInTheDocument();
  });

  it('/login renders the magic-link request form', async () => {
    await renderApp('/login');
    expect(screen.getByRole('heading', { level: 1, name: t('auth.request') })).toBeInTheDocument();
    expect(screen.getByLabelText(t('auth.email'))).toBeInTheDocument();
  });

  it('unknown routes render the not-found surface inside the chrome', async () => {
    await renderApp('/no-such-route');
    expect(screen.getByRole('heading', { level: 1, name: t('error.generic') })).toBeInTheDocument();
    expect(screen.getByText(t('app.eyebrow'))).toBeInTheDocument();
  });
});

describe('route-change focus management + announcements', () => {
  it('moves focus to the new page heading and announces it politely', async () => {
    const { router } = await renderApp('/');
    expect(document.activeElement).not.toBe(document.querySelector('main h1'));

    await act(async () => {
      await router.navigate({ to: '/settings' });
    });
    await waitFor(() => {
      const heading = document.querySelector('main h1');
      expect(heading).toHaveTextContent(t('settings.title'));
      expect(document.activeElement).toBe(heading);
    });
    const liveRegion = document.querySelector('[aria-live="polite"]');
    expect(liveRegion).toHaveTextContent(t('settings.title'));
  });

  it('does not steal focus on initial load', async () => {
    await renderApp('/me');
    expect(document.activeElement).not.toBe(document.querySelector('main h1'));
  });
});

describe('the hub — play button decision table, rendered', () => {
  const base: LocalState = { ...defaultLocalState(), firstShiftDone: true };

  async function playButton(state: LocalState): Promise<HTMLElement> {
    await renderApp('/', { state });
    const button = document.querySelector<HTMLElement>('main a.bf-play');
    expect(button).not.toBeNull();
    return button as HTMLElement;
  }

  it('state 1 — first visit renders First Shift', async () => {
    const button = await playButton(defaultLocalState());
    expect(button).toHaveTextContent(t('hub.play.first'));
    expect(button.dataset['decision']).toBe('first-shift');
    expect(button.getAttribute('href')).toBe('/daily');
  });

  it('state 2 — unstarted daily', async () => {
    const button = await playButton(base);
    expect(button).toHaveTextContent("Play today's Burn Order");
    expect(button.getAttribute('href')).toBe('/daily');
  });

  it('state 3 — daily in progress resumes with elapsed', async () => {
    const button = await playButton({
      ...base,
      daily: { date: TODAY, status: 'in_progress', elapsedMs: 161_000 },
    });
    expect(button).toHaveTextContent('Resume — 2:41 elapsed');
    expect(button.dataset['decision']).toBe('daily-resume');
  });

  it('state 4 — contained daily recommends endless at tier', async () => {
    const button = await playButton({
      ...base,
      daily: { date: TODAY, status: 'contained', elapsedMs: 291_000, containedMs: 291_000 },
    });
    expect(button).toHaveTextContent('Keep burning · Crew 6×6');
    expect(button.getAttribute('href')).toBe('/play?tier=crew');
  });

  it('state 5 — endless mid-solve resumes endless', async () => {
    const button = await playButton({
      ...base,
      daily: { date: TODAY, status: 'contained', elapsedMs: 291_000 },
      endless: { ...base.endless, tier: 'hotshot', inProgress: true },
    });
    expect(button).toHaveTextContent(t('hub.play.resumeEndless'));
    expect(button.getAttribute('href')).toBe('/play?tier=hotshot');
  });
});

describe('the hub — lanes and countdown', () => {
  it('renders all five lanes in order with their feature markers', async () => {
    await renderApp('/');
    const lanes = [...document.querySelectorAll('main section.bf-lane')];
    expect(lanes.map((lane) => lane.getAttribute('data-ws'))).toEqual([
      'WS-10',
      'WS-11',
      'WS-12',
      'WS-14',
    ]);
    expect(screen.getByText(t('hub.lane.daily'))).toBeInTheDocument();
    expect(screen.getByText(t('hub.lane.endless'))).toBeInTheDocument();
    expect(screen.getByText(t('hub.lane.academy'))).toBeInTheDocument();
    expect(screen.getByText(t('hub.lane.record'))).toBeInTheDocument();
    expect(screen.getByText(t('hub.lane.rush'))).toBeInTheDocument();
  });

  it('renders the UTC countdown from the injected clock', async () => {
    await renderApp('/');
    expect(
      screen.getByText(t('hub.countdown', { hh: '02', mm: '41', ss: '09' })),
    ).toBeInTheDocument();
  });

  it('shows the endless tier chips with the recommendation highlighted', async () => {
    await renderApp('/');
    const chips = [...document.querySelectorAll('main .bf-tier-chip')];
    expect(chips.map((chip) => chip.textContent)).toEqual([
      'Lookout 5×5',
      'Crew 6×6',
      'Hotshot 7×7',
    ]);
    expect(chips.map((chip) => chip.getAttribute('data-recommended'))).toEqual([
      'false',
      'true',
      'false',
    ]);
  });

  it('shows daily state, streak flame and guest chips', async () => {
    await renderApp('/', {
      state: {
        ...defaultLocalState(),
        firstShiftDone: true,
        daily: { date: TODAY, status: 'in_progress', elapsedMs: 161_000 },
        streak: { current: 3, best: 3, lastDailyDate: '2026-07-02' },
      },
    });
    expect(screen.getByText(t('streak.days', { n: 3 }))).toBeInTheDocument();
    // Guest chip appears in the header and in the record lane.
    expect(screen.getAllByText(t('hub.guest')).length).toBeGreaterThanOrEqual(2);
    expect(screen.getByText(t('streak.guestNote'))).toBeInTheDocument();
  });
});

describe('offline (PWA shell behavior)', () => {
  it('shows the offline notice in the chrome and the daily offline line', async () => {
    await renderApp('/daily');
    expect(screen.queryByText(t('error.offline'))).not.toBeInTheDocument();

    fireEvent(window, new Event('offline'));
    expect(await screen.findByText(t('error.offline'))).toBeInTheDocument();
    expect(screen.getByText(t('daily.offline'))).toBeInTheDocument();
    expect(screen.queryByText(t('daily.loading'))).not.toBeInTheDocument();

    fireEvent(window, new Event('online'));
    expect(await screen.findByText(t('daily.loading'))).toBeInTheDocument();
  });
});
