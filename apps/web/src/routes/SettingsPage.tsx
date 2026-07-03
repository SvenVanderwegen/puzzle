/**
 * /settings (WS-14).
 *
 * - Device preferences (local-only, product §1): sound, reduced motion,
 *   hide-timer, high-contrast theme — persisted in the anonymous-first
 *   store; the chrome applies them as data attributes.
 * - Account preferences (PATCH /me): streak-protection alerts (double
 *   opt-in starts server-side on true, WS-21) and the IANA timezone.
 * - GDPR self-service: export (GET /me/export → 202 → emailed signed link)
 *   and delete (type-to-confirm dialog, DELETE /me → 202 → session over,
 *   local guest state kept — product §1: accounts protect, never gate).
 * - Sign out (POST /auth/logout).
 * Guests see the device preferences and the sign-in pointer only.
 */
import { Link } from '@tanstack/react-router';
import {
  useEffect,
  useId,
  useRef,
  useState,
  type KeyboardEvent as ReactKeyboardEvent,
  type ReactElement,
} from 'react';
import type { components } from '@burnfront/api-client';
import { PageHeading } from '../chrome/PageHeading';
import { timezoneOptions } from '../account/timezone';
import { withoutAccount, type PrefsState } from '../state/localState';
import { useApi, useLocalState, useLocalStateUpdate } from '../state/runtime';
import { t, type StringKey } from '../strings';

type Me = components['schemas']['Me'];

const PREF_ROWS = [
  ['sound', 'settings.sound'],
  ['reducedMotion', 'settings.reducedMotion'],
  ['hideTimer', 'settings.hideTimer'],
  ['highContrast', 'settings.highContrast'],
] as const satisfies ReadonlyArray<readonly [keyof PrefsState, StringKey]>;

function PrefsSection(): ReactElement {
  const state = useLocalState();
  const update = useLocalStateUpdate();
  return (
    <ul className="bf-settings" data-settings="prefs">
      {PREF_ROWS.map(([pref, labelKey]) => (
        <li key={pref}>
          <label className="bf-settings__row">
            <input
              type="checkbox"
              checked={state.prefs[pref]}
              onChange={() => {
                update((current) => ({
                  ...current,
                  prefs: { ...current.prefs, [pref]: !current.prefs[pref] },
                }));
              }}
            />
            {t(labelKey)}
          </label>
        </li>
      ))}
    </ul>
  );
}

function DeleteDialog(props: {
  readonly onClose: () => void;
  readonly onDeleted: () => void;
}): ReactElement {
  const api = useApi();
  const titleId = useId();
  const inputId = useId();
  const dialogRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const [confirmText, setConfirmText] = useState('');
  const [phase, setPhase] = useState<'idle' | 'deleting' | 'failed'>('idle');
  const word = t('settings.delete.word');

  // Focus management on open: the confirm field takes focus; the opener
  // restores it on close (see AccountSection).
  useEffect(() => {
    inputRef.current?.focus();
  }, []);

  async function confirm(): Promise<void> {
    setPhase('deleting');
    try {
      // Both documented statuses end in guest: 202 = anonymization queued +
      // session ended; 401 = the session was already gone.
      await api.delete('/me');
      props.onDeleted();
    } catch {
      setPhase('failed');
    }
  }

  /**
   * Focus trap (aria-modal alone does not stop Tab): wrap Tab/Shift+Tab at
   * the dialog edges so the background chrome is unreachable while open.
   * Disabled controls (the confirm button before the word matches) are not
   * tab stops, so the trailing edge moves with the confirm state.
   */
  function trapTab(event: ReactKeyboardEvent<HTMLDivElement>): void {
    const dialog = dialogRef.current;
    if (dialog === null) return;
    const focusables = [
      ...dialog.querySelectorAll<HTMLElement>('a[href], button, input, select, textarea'),
    ].filter((element) => !element.hasAttribute('disabled'));
    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    if (first === undefined || last === undefined) return;
    const active = document.activeElement;
    if (active === null || !dialog.contains(active)) {
      event.preventDefault();
      first.focus();
    } else if (event.shiftKey && active === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    }
  }

  return (
    <div className="bf-dialog__backdrop">
      <div
        className="bf-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        ref={dialogRef}
        onKeyDown={(event) => {
          if (event.key === 'Escape') props.onClose();
          else if (event.key === 'Tab') trapTab(event);
        }}
      >
        <h2 className="bf-dialog__title" id={titleId}>
          {t('settings.delete')}
        </h2>
        <p className="bf-lane__meta">{t('settings.delete.explain')}</p>
        <label className="bf-field__label" htmlFor={inputId}>
          {t('settings.delete.typeToConfirm', { word })}
        </label>
        <input
          className="bf-input"
          id={inputId}
          ref={inputRef}
          autoComplete="off"
          value={confirmText}
          onChange={(event) => {
            setConfirmText(event.target.value);
          }}
        />
        <div className="bf-dialog__actions">
          <button className="bf-button" type="button" onClick={props.onClose}>
            {t('common.cancel')}
          </button>
          <button
            className="bf-button bf-button--danger"
            type="button"
            disabled={confirmText !== word || phase === 'deleting'}
            onClick={() => {
              void confirm();
            }}
          >
            {t('settings.delete')}
          </button>
        </div>
        {phase === 'failed' ? (
          <p className="bf-error" role="alert">
            {t('error.generic')}
          </p>
        ) : null}
      </div>
    </div>
  );
}

function AccountSection(props: { readonly onDeleted: () => void }): ReactElement | null {
  const api = useApi();
  const update = useLocalStateUpdate();
  const timezoneId = useId();
  const [me, setMe] = useState<Me | null>(null);
  const [exportPhase, setExportPhase] = useState<
    'idle' | 'requesting' | 'sent' | 'throttled' | 'failed'
  >('idle');
  const [signOutFailed, setSignOutFailed] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const deleteButtonRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    // Post-unmount setMe is a React no-op; no cancellation needed.
    void (async () => {
      try {
        const result = await api.get('/me');
        if (result.status === 200) setMe(result.data);
        else update(withoutAccount); // 401 — the session is gone.
      } catch {
        /* offline etc. — the local prefs above still work */
      }
    })();
  }, [api, update]);

  async function patchMe(body: {
    timezone?: string;
    streak_alert_opt_in?: boolean;
  }): Promise<void> {
    try {
      const result = await api.patch('/me', { body });
      if (result.status === 200) setMe(result.data);
      else update(withoutAccount);
    } catch {
      /* leave the last known profile */
    }
  }

  async function requestExport(): Promise<void> {
    setExportPhase('requesting');
    try {
      const result = await api.get('/me/export');
      if (result.status === 202) setExportPhase('sent');
      else if (result.status === 429) setExportPhase('throttled');
      else {
        update(withoutAccount);
        setExportPhase('idle');
      }
    } catch {
      setExportPhase('failed');
    }
  }

  async function signOut(): Promise<void> {
    try {
      await api.post('/auth/logout'); // 204 or 401 — both end the session.
      update(withoutAccount);
    } catch {
      setSignOutFailed(true);
    }
  }

  if (me === null) return null;

  return (
    <ul className="bf-settings" data-settings="account">
      <li>
        <label className="bf-settings__row">
          <input
            type="checkbox"
            checked={me.streak_alert_opt_in}
            onChange={() => {
              void patchMe({ streak_alert_opt_in: !me.streak_alert_opt_in });
            }}
          />
          {t('settings.streakAlert')}
        </label>
      </li>
      <li>
        <label className="bf-field__label" htmlFor={timezoneId}>
          {t('settings.timezone')}
        </label>
        <select
          className="bf-input"
          id={timezoneId}
          value={me.timezone}
          onChange={(event) => {
            void patchMe({ timezone: event.target.value });
          }}
        >
          {timezoneOptions(me.timezone).map((zone) => (
            <option key={zone} value={zone}>
              {zone}
            </option>
          ))}
        </select>
        <p className="bf-hint">{t('settings.timezone.hint')}</p>
      </li>
      <li>
        {exportPhase === 'sent' ? (
          <p className="bf-lane__meta" role="status" data-settings="export-sent">
            {t('settings.export.sent')}
          </p>
        ) : (
          <button
            className="bf-button"
            type="button"
            disabled={exportPhase === 'requesting'}
            onClick={() => {
              void requestExport();
            }}
          >
            {t('settings.export')}
          </button>
        )}
        {exportPhase === 'throttled' ? (
          <p className="bf-error" role="alert">
            {t('error.rateLimited')}
          </p>
        ) : null}
        {exportPhase === 'failed' ? (
          <p className="bf-error" role="alert">
            {t('error.generic')}
          </p>
        ) : null}
      </li>
      <li>
        <button
          className="bf-button bf-button--danger"
          type="button"
          ref={deleteButtonRef}
          onClick={() => {
            setDeleteOpen(true);
          }}
        >
          {t('settings.delete')}
        </button>
        <p className="bf-hint">{t('settings.delete.explain')}</p>
        {deleteOpen ? (
          <DeleteDialog
            onClose={() => {
              setDeleteOpen(false);
              deleteButtonRef.current?.focus();
            }}
            onDeleted={() => {
              setDeleteOpen(false);
              update(withoutAccount);
              props.onDeleted();
            }}
          />
        ) : null}
      </li>
      <li>
        <button
          className="bf-button"
          type="button"
          onClick={() => {
            void signOut();
          }}
        >
          {t('auth.signOut')}
        </button>
        {signOutFailed ? (
          <p className="bf-error" role="alert">
            {t('error.generic')}
          </p>
        ) : null}
      </li>
    </ul>
  );
}

export function SettingsPage(): ReactElement {
  const state = useLocalState();
  const [deleted, setDeleted] = useState(false);
  const deletedRef = useRef<HTMLParagraphElement>(null);

  useEffect(() => {
    // The dialog and account section unmount together on deletion; move
    // focus to the confirmation so it is not dropped on <body>.
    if (deleted) deletedRef.current?.focus();
  }, [deleted]);

  return (
    <>
      <PageHeading>{t('settings.title')}</PageHeading>
      <PrefsSection />
      {deleted ? (
        <p className="bf-lane" role="status" tabIndex={-1} ref={deletedRef} data-settings="deleted">
          {t('settings.delete.done')}
        </p>
      ) : null}
      {state.account === null ? (
        deleted ? null : (
          <p className="bf-lane__meta" data-settings="guest">
            <Link to="/login">{t('streak.guestNote')}</Link>
          </p>
        )
      ) : (
        <AccountSection
          onDeleted={() => {
            setDeleted(true);
          }}
        />
      )}
    </>
  );
}
