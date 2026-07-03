/**
 * /login feature tests (WS-14, ADR-0003): request → constant sent state,
 * throttling, and the consumed-link landing — session established, local
 * signed-in marker stamped, timezone seeded from the browser, hub redirect
 * with the auth.consumed toast; 410 falls back to a working retry form.
 */
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { defaultLocalState, loadLocalState, type SolveLogEntry } from '../state/localState';
import { t } from '../strings';
import { mockApi } from '../testing/mockApi';
import { renderAppAt } from '../testing/renderApp';

// Pin the browser-detected zone (CI runs under TZ=UTC; the bootstrap-PATCH
// branch needs a real zone).
vi.mock('../account/timezone', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../account/timezone')>();
  return { ...actual, detectedTimezone: () => 'Europe/Brussels' };
});

const TOKEN = 'a'.repeat(32);

function meFixture(timezone = 'Europe/Brussels') {
  return {
    id: 'u1',
    email: 'crew@example.com',
    timezone,
    plan: 'free',
    streak_alert_opt_in: false,
    streak: { current: 3, best: 5, last_daily_date: '2026-07-03' },
    rating: { rating: 1487, rd: 120, volatility: 0.06, games: 12, calibrating: false },
  };
}

describe('/login — magic-link request form', () => {
  it('requests a link and shows the constant sent state', async () => {
    const { api, callsTo } = mockApi({ 'POST /auth/magic-link': { status: 202 } });
    await renderAppAt('/login', { api });
    const user = userEvent.setup();

    await user.type(screen.getByLabelText(t('auth.email')), 'crew@example.com');
    await user.click(screen.getByRole('button', { name: t('auth.request') }));

    expect(await screen.findByText(t('auth.sent'))).toBeInTheDocument();
    expect(callsTo('POST /auth/magic-link')).toEqual([
      { method: 'POST', path: '/auth/magic-link', body: { email: 'crew@example.com' } },
    ]);
    // The form is gone — nothing invites a second, enumerable attempt.
    expect(screen.queryByRole('button', { name: t('auth.request') })).not.toBeInTheDocument();
  });

  it('announces throttling and keeps the form usable', async () => {
    const { api } = mockApi({ 'POST /auth/magic-link': { status: 429 } });
    await renderAppAt('/login', { api });
    const user = userEvent.setup();

    await user.type(screen.getByLabelText(t('auth.email')), 'crew@example.com');
    await user.click(screen.getByRole('button', { name: t('auth.request') }));

    expect(await screen.findByRole('alert')).toHaveTextContent(t('error.rateLimited'));
    expect(screen.getByRole('button', { name: t('auth.request') })).toBeInTheDocument();
  });

  it('announces a generic failure when the API is outside the contract', async () => {
    const { api } = mockApi({
      'POST /auth/magic-link': () => {
        throw new Error('HTTP 500');
      },
    });
    await renderAppAt('/login', { api });
    const user = userEvent.setup();

    await user.type(screen.getByLabelText(t('auth.email')), 'crew@example.com');
    await user.click(screen.getByRole('button', { name: t('auth.request') }));

    expect(await screen.findByRole('alert')).toHaveTextContent(t('error.generic'));
  });
});

describe('/login?token=… — consumed-link landing', () => {
  it('consumes the token, stamps the signed-in marker and redirects with the toast', async () => {
    const { api, callsTo } = mockApi({
      'POST /auth/magic-link/consume': { status: 204 },
      'GET /me': { status: 200, data: meFixture() },
    });
    const { router, storage } = await renderAppAt(`/login?token=${TOKEN}`, { api });

    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/');
    });
    expect(callsTo('POST /auth/magic-link/consume')).toEqual([
      { method: 'POST', path: '/auth/magic-link/consume', body: { token: TOKEN } },
    ]);
    expect(loadLocalState(storage).account).toEqual({ email: 'crew@example.com' });
    expect(await screen.findByText(t('auth.consumed'))).toBeInTheDocument();
    // Signed in: the header shows the account, not the Guest chip.
    expect(screen.queryByText(t('hub.guest'))).not.toBeInTheDocument();
    // Profile already had a real zone — no timezone bootstrap PATCH.
    expect(callsTo('PATCH /me')).toEqual([]);
  });

  it('seeds the browser-detected timezone while the profile is at the server default', async () => {
    const { api, callsTo } = mockApi({
      'POST /auth/magic-link/consume': { status: 204 },
      'GET /me': { status: 200, data: meFixture('UTC') },
      'PATCH /me': { status: 200, data: meFixture() },
    });
    const { router } = await renderAppAt(`/login?token=${TOKEN}`, { api });

    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/');
    });
    expect(callsTo('PATCH /me')).toEqual([
      { method: 'PATCH', path: '/me', body: { timezone: 'Europe/Brussels' } },
    ]);
  });

  it('410 (expired or used) explains and falls back to a working request form', async () => {
    const { api } = mockApi({
      'POST /auth/magic-link/consume': { status: 410 },
      'POST /auth/magic-link': { status: 202 },
    });
    const { storage } = await renderAppAt(`/login?token=${TOKEN}`, { api });
    const user = userEvent.setup();

    expect(await screen.findByRole('alert')).toHaveTextContent(t('auth.expired'));
    expect(loadLocalState(storage).account).toBeNull();

    // The retry path: request a fresh link right there.
    await user.type(screen.getByLabelText(t('auth.email')), 'crew@example.com');
    await user.click(screen.getByRole('button', { name: t('auth.request') }));
    expect(await screen.findByText(t('auth.sent'))).toBeInTheDocument();
  });

  it('keeps guest state untouched when consumption fails', async () => {
    const seeded = {
      ...defaultLocalState(),
      firstShiftDone: true,
      streak: { current: 4, best: 4, lastDailyDate: '2026-07-03' },
    };
    const { api } = mockApi({ 'POST /auth/magic-link/consume': { status: 410 } });
    const { storage } = await renderAppAt(`/login?token=${TOKEN}`, { api, state: seeded });

    expect(await screen.findByRole('alert')).toHaveTextContent(t('auth.expired'));
    expect(loadLocalState(storage).streak.current).toBe(4);
  });
});

// WS-20: the anonymous→account merge attached to the consume landing.
describe('/login?token=… — local-record merge (WS-20)', () => {
  function guestLogEntry(i: number, date: string): SolveLogEntry {
    return {
      clientSolveId: `01980000-0000-7000-8000-${String(i).padStart(12, '0')}`,
      mode: 'daily',
      date,
      shaded: '000010010',
      clientMs: 61_000,
      hints: { s1: 0, s2: 0, s3: 0 },
      solvedAt: `${date}T20:00:00.000Z`,
    };
  }

  function guestWithLog() {
    return {
      ...defaultLocalState(),
      firstShiftDone: true,
      streak: { current: 3, best: 3, lastDailyDate: '2026-07-03' },
      solveLog: [
        guestLogEntry(1, '2026-07-01'),
        guestLogEntry(2, '2026-07-02'),
        guestLogEntry(3, '2026-07-03'),
      ],
    };
  }

  function importResult(days: number) {
    return {
      results: [
        { client_solve_id: '01980000-0000-7000-8000-000000000001', status: 'credited' },
        { client_solve_id: '01980000-0000-7000-8000-000000000002', status: 'credited' },
        { client_solve_id: '01980000-0000-7000-8000-000000000003', status: 'credited' },
      ],
      credited_days: days,
      streak: { current: days, best: days, last_daily_date: '2026-07-03' },
    };
  }

  it('uploads the guest log, clears it, and shows the merge summary (streak 3)', async () => {
    const { api, callsTo } = mockApi({
      'POST /auth/magic-link/consume': { status: 204 },
      'GET /me': { status: 200, data: meFixture() },
      'POST /me/import': { status: 200, data: importResult(3) },
    });
    const state = guestWithLog();
    const { router, storage } = await renderAppAt(`/login?token=${TOKEN}`, { api, state });

    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/');
    });

    // The full local log went up, in the ImportItem wire shape.
    const uploads = callsTo('POST /me/import');
    expect(uploads).toHaveLength(1);
    expect(uploads[0]?.body).toEqual({
      items: state.solveLog.map((entry) => ({
        client_solve_id: entry.clientSolveId,
        mode: entry.mode,
        date: entry.date,
        shaded: entry.shaded,
        client_ms: entry.clientMs,
        hints: entry.hints,
        solved_at: entry.solvedAt,
      })),
    });

    // The merge summary toast, interpolated: "3 solves merged. 3-day streak protected."
    expect(
      await screen.findByText(t('account.merge.summary', { solves: 3, days: 3 })),
    ).toBeInTheDocument();

    // The log cleared; the rest of the guest record survived byte-for-byte.
    const after = loadLocalState(storage);
    expect(after.solveLog).toEqual([]);
    expect(after.streak).toEqual(state.streak);
    expect(after.firstShiftDone).toBe(true);
    expect(after.account).toEqual({ email: 'crew@example.com' });
  });

  it('skips the upload entirely when the log is empty', async () => {
    const { api, callsTo } = mockApi({
      'POST /auth/magic-link/consume': { status: 204 },
      'GET /me': { status: 200, data: meFixture() },
    });
    const { router } = await renderAppAt(`/login?token=${TOKEN}`, { api });

    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/');
    });
    expect(callsTo('POST /me/import')).toEqual([]);
    expect(await screen.findByText(t('auth.consumed'))).toBeInTheDocument();
  });

  it('keeps the log for a later sign-in when the merge call fails', async () => {
    const { api } = mockApi({
      'POST /auth/magic-link/consume': { status: 204 },
      'GET /me': { status: 200, data: meFixture() },
      'POST /me/import': () => {
        throw new Error('HTTP 500');
      },
    });
    const state = guestWithLog();
    const { router, storage } = await renderAppAt(`/login?token=${TOKEN}`, { api, state });

    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/');
    });
    // Signed in fine, calm landing, nothing merged, nothing lost.
    expect(await screen.findByText(t('auth.consumed'))).toBeInTheDocument();
    const after = loadLocalState(storage);
    expect(after.account).toEqual({ email: 'crew@example.com' });
    expect(after.solveLog).toEqual(state.solveLog);
  });

  it('clears the log but lands calm when the server merged nothing', async () => {
    const { api } = mockApi({
      'POST /auth/magic-link/consume': { status: 204 },
      'GET /me': { status: 200, data: meFixture() },
      'POST /me/import': {
        status: 200,
        data: {
          results: state0Results(),
          credited_days: 0,
          streak: { current: 0, best: 0 },
        },
      },
    });
    const state = guestWithLog();
    const { router, storage } = await renderAppAt(`/login?token=${TOKEN}`, { api, state });

    await waitFor(() => {
      expect(router.state.location.pathname).toBe('/');
    });
    // The server ruled (all duplicates/dropped): the log clears, but a
    // "0 solves merged" toast would be noise — the plain consumed line shows.
    expect(await screen.findByText(t('auth.consumed'))).toBeInTheDocument();
    expect(loadLocalState(storage).solveLog).toEqual([]);
  });

  function state0Results() {
    return [
      { client_solve_id: '01980000-0000-7000-8000-000000000001', status: 'duplicate' },
      { client_solve_id: '01980000-0000-7000-8000-000000000002', status: 'invalid' },
      { client_solve_id: '01980000-0000-7000-8000-000000000003', status: 'board_unknown' },
    ];
  }
});
