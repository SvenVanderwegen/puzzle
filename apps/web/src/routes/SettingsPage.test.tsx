/**
 * /settings feature tests (WS-14): local preference toggles persist and
 * apply live (high contrast on the chrome), account rows PATCH /me, the
 * export request lands in the check-your-email state, delete is
 * type-to-confirm with dialog focus management and PRESERVES local guest
 * state, and sign-out drops only the marker.
 */
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import type { components } from '@burnfront/api-client';
import { defaultLocalState, loadLocalState, type LocalState } from '../state/localState';
import { t } from '../strings';
import { mockApi, type Responder } from '../testing/mockApi';
import { renderAppAt } from '../testing/renderApp';

type Me = components['schemas']['Me'];

function meFixture(overrides: Partial<Me> = {}): Me {
  return {
    id: 'u1',
    email: 'crew@example.com',
    timezone: 'Europe/Brussels',
    plan: 'free',
    streak_alert_opt_in: false,
    streak: { current: 3, best: 5, last_daily_date: '2026-07-03' },
    rating: { rating: 1487, rd: 120, volatility: 0.06, games: 12, calibrating: false },
    ...overrides,
  };
}

function signedInState(): LocalState {
  return {
    ...defaultLocalState(),
    firstShiftDone: true,
    streak: { current: 3, best: 5, lastDailyDate: '2026-07-02' },
    record: { rating: 1310, lastDelta: 9, games: 14, cleanContains: 6 },
    account: { email: 'crew@example.com' },
  };
}

async function renderSignedIn(extraRoutes: Readonly<Record<string, Responder>> = {}) {
  const mocked = mockApi({ 'GET /me': { status: 200, data: meFixture() }, ...extraRoutes });
  const rendered = await renderAppAt('/settings', { api: mocked.api, state: signedInState() });
  await waitFor(() => {
    expect(screen.getByLabelText(t('settings.streakAlert'))).toBeInTheDocument();
  });
  return { ...mocked, ...rendered };
}

describe('/settings — device preferences (local, guest-first)', () => {
  it('renders the four toggles and persists a change', async () => {
    const { storage } = await renderAppAt('/settings');
    const user = userEvent.setup();

    for (const key of [
      'settings.sound',
      'settings.reducedMotion',
      'settings.hideTimer',
      'settings.highContrast',
    ] as const) {
      expect(screen.getByLabelText(t(key))).not.toBeChecked();
    }

    await user.click(screen.getByLabelText(t('settings.sound')));
    expect(loadLocalState(storage).prefs.sound).toBe(true);
    expect(loadLocalState(storage).prefs.reducedMotion).toBe(false);
  });

  it('applies high contrast and reduced motion to the chrome immediately', async () => {
    await renderAppAt('/settings');
    const user = userEvent.setup();
    const app = document.querySelector('.bf-app');

    expect(app).toHaveAttribute('data-contrast', 'normal');
    await user.click(screen.getByLabelText(t('settings.highContrast')));
    expect(app).toHaveAttribute('data-contrast', 'high');

    expect(app).toHaveAttribute('data-motion', 'full');
    await user.click(screen.getByLabelText(t('settings.reducedMotion')));
    expect(app).toHaveAttribute('data-motion', 'reduced');
  });

  it('guests see the sign-in pointer instead of account rows, with zero API calls', async () => {
    const { api, calls } = mockApi({});
    await renderAppAt('/settings', { api });
    expect(screen.getByRole('link', { name: t('streak.guestNote') })).toHaveAttribute(
      'href',
      '/login',
    );
    expect(screen.queryByText(t('settings.export'))).not.toBeInTheDocument();
    expect(screen.queryByText(t('settings.delete'))).not.toBeInTheDocument();
    expect(calls).toHaveLength(0);
  });
});

describe('/settings — account preferences (PATCH /me)', () => {
  it('toggles streak protection alerts through the contract', async () => {
    const { callsTo } = await renderSignedIn({
      'PATCH /me': { status: 200, data: meFixture({ streak_alert_opt_in: true }) },
    });
    const user = userEvent.setup();

    await user.click(screen.getByLabelText(t('settings.streakAlert')));
    await waitFor(() => {
      expect(screen.getByLabelText(t('settings.streakAlert'))).toBeChecked();
    });
    expect(callsTo('PATCH /me')).toEqual([
      { method: 'PATCH', path: '/me', body: { streak_alert_opt_in: true } },
    ]);
  });

  it('shows the profile timezone and PATCHes a new choice', async () => {
    const { callsTo } = await renderSignedIn({
      'PATCH /me': { status: 200, data: meFixture({ timezone: 'UTC' }) },
    });
    const user = userEvent.setup();

    const select = screen.getByLabelText(t('settings.timezone'));
    expect(select).toHaveValue('Europe/Brussels');
    await user.selectOptions(select, 'UTC');
    await waitFor(() => {
      expect(select).toHaveValue('UTC');
    });
    expect(callsTo('PATCH /me')).toEqual([
      { method: 'PATCH', path: '/me', body: { timezone: 'UTC' } },
    ]);
  });
});

describe('/settings — export data (GDPR portability)', () => {
  it('202 lands in the check-your-email state', async () => {
    const { callsTo } = await renderSignedIn({ 'GET /me/export': { status: 202 } });
    const user = userEvent.setup();

    await user.click(screen.getByRole('button', { name: t('settings.export') }));
    expect(await screen.findByText(t('settings.export.sent'))).toBeInTheDocument();
    expect(callsTo('GET /me/export')).toHaveLength(1);
    expect(screen.queryByRole('button', { name: t('settings.export') })).not.toBeInTheDocument();
  });

  it('429 announces the throttle and keeps the button', async () => {
    await renderSignedIn({ 'GET /me/export': { status: 429 } });
    const user = userEvent.setup();

    await user.click(screen.getByRole('button', { name: t('settings.export') }));
    expect(await screen.findByRole('alert')).toHaveTextContent(t('error.rateLimited'));
    expect(screen.getByRole('button', { name: t('settings.export') })).toBeInTheDocument();
  });
});

describe('/settings — delete account (type-to-confirm)', () => {
  it('explains anonymization, requires the confirm word, deletes, and keeps guest state', async () => {
    const { callsTo, storage } = await renderSignedIn({ 'DELETE /me': { status: 202 } });
    const user = userEvent.setup();

    await user.click(screen.getByRole('button', { name: t('settings.delete') }));
    const dialog = await screen.findByRole('dialog');
    expect(dialog).toHaveTextContent(t('settings.delete.explain'));

    // Focus lands on the confirm field; the confirm button starts disabled.
    const input = screen.getByLabelText(
      t('settings.delete.typeToConfirm', { word: t('settings.delete.word') }),
    );
    expect(input).toHaveFocus();
    const confirm = screen.getAllByRole('button', { name: t('settings.delete') })[1];
    expect(confirm).toBeDisabled();

    await user.keyboard(t('settings.delete.word'));
    expect(confirm).toBeEnabled();
    if (confirm !== undefined) await user.click(confirm);

    expect(await screen.findByText(t('settings.delete.done'))).toBeInTheDocument();
    expect(callsTo('DELETE /me')).toHaveLength(1);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

    // Session over, marker gone — but the local guest record survives intact.
    const after = loadLocalState(storage);
    expect(after.account).toBeNull();
    expect(after.streak.current).toBe(3);
    expect(after.record.rating).toBe(1310);
    expect(after.firstShiftDone).toBe(true);
  });

  it('a wrong confirm word never enables the destructive button', async () => {
    await renderSignedIn({});
    const user = userEvent.setup();

    await user.click(screen.getByRole('button', { name: t('settings.delete') }));
    await screen.findByRole('dialog');
    await user.keyboard('delete');
    expect(screen.getAllByRole('button', { name: t('settings.delete') })[1]).toBeDisabled();
  });

  it('Escape closes the dialog and returns focus to the opener', async () => {
    await renderSignedIn({});
    const user = userEvent.setup();

    const opener = screen.getByRole('button', { name: t('settings.delete') });
    await user.click(opener);
    await screen.findByRole('dialog');
    await user.keyboard('{Escape}');

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(opener).toHaveFocus();
  });

  it('cancel closes without calling the API', async () => {
    const { calls } = await renderSignedIn({});
    const user = userEvent.setup();

    await user.click(screen.getByRole('button', { name: t('settings.delete') }));
    await screen.findByRole('dialog');
    await user.click(screen.getByRole('button', { name: t('common.cancel') }));

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(calls.filter((call) => call.method === 'DELETE')).toHaveLength(0);
  });
});

describe('/settings — sign out', () => {
  it('ends the session and keeps the local record', async () => {
    const { callsTo, storage } = await renderSignedIn({ 'POST /auth/logout': { status: 204 } });
    const user = userEvent.setup();

    await user.click(screen.getByRole('button', { name: t('auth.signOut') }));
    await waitFor(() => {
      expect(loadLocalState(storage).account).toBeNull();
    });
    expect(callsTo('POST /auth/logout')).toHaveLength(1);
    expect(loadLocalState(storage).streak.current).toBe(3);
    // Back to guest: the account rows are gone, the pointer is back.
    expect(screen.queryByLabelText(t('settings.streakAlert'))).not.toBeInTheDocument();
  });
});
