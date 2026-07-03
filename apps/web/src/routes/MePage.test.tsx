/**
 * /me feature tests (WS-14): guest local record (zero API calls), the
 * calibrating rating state (RATING.md §5), the rated chip + sparkline, the
 * controlled-burn (freeze) streak marker, cursor-paged solve history and
 * the expired-session fallback.
 */
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import type { components } from '@burnfront/api-client';
import { defaultLocalState, loadLocalState, type LocalState } from '../state/localState';
import { t } from '../strings';
import { mockApi } from '../testing/mockApi';
import { renderAppAt } from '../testing/renderApp';

type Me = components['schemas']['Me'];
type SolveSummary = components['schemas']['SolveSummary'];

function meFixture(overrides: Partial<Me> = {}): Me {
  return {
    id: 'u1',
    email: 'crew@example.com',
    timezone: 'Europe/Brussels',
    plan: 'free',
    streak_alert_opt_in: false,
    streak: { current: 5, best: 9, last_daily_date: '2026-07-03' },
    rating: {
      rating: 1487.4,
      rd: 120,
      volatility: 0.06,
      games: 12,
      calibrating: false,
      sparkline: [1450, 1462, 1478, 1487],
    },
    ...overrides,
  };
}

function solve(id: string, overrides: Partial<SolveSummary> = {}): SolveSummary {
  return {
    solve_id: id,
    mode: 'daily',
    incident_number: 142,
    valid: true,
    official_ms: 272_000,
    clean: true,
    received_at: '2026-07-03T08:12:00Z',
    ...overrides,
  };
}

function signedInState(): LocalState {
  return { ...defaultLocalState(), firstShiftDone: true, account: { email: 'crew@example.com' } };
}

describe('/me — guest', () => {
  it('shows the local provisional record with zero API calls', async () => {
    const { api, calls } = mockApi({});
    await renderAppAt('/me', { api });
    expect(screen.getByText(t('play.stats.calibrating', { n: 0 }))).toBeInTheDocument();
    expect(screen.getByText(t('streak.guestNote'))).toBeInTheDocument();
    expect(calls).toHaveLength(0);
  });
});

describe('/me — signed in', () => {
  it('renders the calibrating state instead of a number (RATING.md §5)', async () => {
    const { api } = mockApi({
      'GET /me': {
        status: 200,
        data: meFixture({
          rating: { rating: 1500, rd: 300, volatility: 0.06, games: 6, calibrating: true },
        }),
      },
      'GET /me/solves': { status: 200, data: { items: [], next_cursor: null } },
    });
    await renderAppAt('/me', { api, state: signedInState() });
    expect(await screen.findByText(t('play.stats.calibrating', { n: 6 }))).toBeInTheDocument();
    expect(screen.getByText(t('me.history.empty'))).toBeInTheDocument();
    expect(document.querySelector('.bf-sparkline')).toBeNull();
  });

  it('renders the rated chip with the sparkline delta, the streak and history rows', async () => {
    const { api } = mockApi({
      'GET /me': { status: 200, data: meFixture() },
      'GET /me/solves': {
        status: 200,
        data: {
          items: [
            solve('s1'),
            solve('s2', { mode: 'endless', incident_number: null, clean: false }),
          ],
          next_cursor: null,
        },
      },
    });
    await renderAppAt('/me', { api, state: signedInState() });

    // 1487 (+9): rounded rating, delta from the last two sparkline points.
    expect(
      await screen.findByText(t('play.stats.ratingDelta', { rating: 1487, delta: '+9' })),
    ).toBeInTheDocument();
    expect(screen.getByText(t('streak.days', { n: 5 }))).toBeInTheDocument();
    expect(document.querySelector('.bf-sparkline')).not.toBeNull();

    expect(screen.getByText(t('daily.title', { n: 142 }))).toBeInTheDocument();
    expect(screen.getByText(t('me.mode.endless'))).toBeInTheDocument();
    expect(screen.getByText(t('play.stats.clean'))).toBeInTheDocument();
    expect(screen.getByText(t('me.distributions.pending'))).toBeInTheDocument();
    // Solved today: no freeze marker.
    expect(screen.queryByText(t('streak.frozen'))).not.toBeInTheDocument();
  });

  it('marks a freeze-held streak with the controlled-burn line', async () => {
    const { api } = mockApi({
      'GET /me': {
        status: 200,
        data: meFixture({
          // Today is 2026-07-03 (pinned clock): a live streak whose last
          // solve was 2 days ago means a freeze covered yesterday.
          streak: { current: 5, best: 9, last_daily_date: '2026-07-01', freeze_available: false },
        }),
      },
      'GET /me/solves': { status: 200, data: { items: [], next_cursor: null } },
    });
    await renderAppAt('/me', { api, state: signedInState() });
    expect(await screen.findByText(t('streak.frozen'))).toBeInTheDocument();
  });

  it('pages the solve history with the cursor', async () => {
    const { api, callsTo } = mockApi({
      'GET /me': { status: 200, data: meFixture() },
      'GET /me/solves': [
        { status: 200, data: { items: [solve('s1'), solve('s2')], next_cursor: 'c1' } },
        { status: 200, data: { items: [solve('s3')], next_cursor: null } },
      ],
    });
    await renderAppAt('/me', { api, state: signedInState() });
    const user = userEvent.setup();

    await waitFor(() => {
      expect(document.querySelectorAll('.bf-history__row')).toHaveLength(2);
    });
    await user.click(screen.getByRole('button', { name: t('me.history.more') }));
    await waitFor(() => {
      expect(document.querySelectorAll('.bf-history__row')).toHaveLength(3);
    });
    expect(screen.queryByRole('button', { name: t('me.history.more') })).not.toBeInTheDocument();

    const pages = callsTo('GET /me/solves');
    expect(pages).toHaveLength(2);
    expect(pages[1]?.query).toEqual({ cursor: 'c1' });
  });

  it('falls back to guest when the session has expired (401)', async () => {
    const { api } = mockApi({
      'GET /me': {
        status: 401,
        data: { error: { code: 'unauthenticated', message: 'No session.' } },
      },
      'GET /me/solves': {
        status: 401,
        data: { error: { code: 'unauthenticated', message: 'No session.' } },
      },
    });
    const { storage } = await renderAppAt('/me', { api, state: signedInState() });

    expect(await screen.findByText(t('streak.guestNote'))).toBeInTheDocument();
    expect(loadLocalState(storage).account).toBeNull();
  });
});
