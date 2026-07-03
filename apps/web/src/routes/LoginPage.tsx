/**
 * /login (WS-14, ADR-0003 magic-link-only auth).
 *
 * Two surfaces on one route:
 * - Request form: POST /auth/magic-link, then the constant response
 *   (auth.sent) whether or not the address exists — no account enumeration.
 * - Consumed-link landing: /login?token=… POSTs /auth/magic-link/consume;
 *   204 stamps the local signed-in marker (email from GET /me), seeds the
 *   browser-detected timezone while the profile is at the server default,
 *   and returns to the hub with the auth.consumed toast. 410 (expired or
 *   already used) explains and falls back to the form — the retry path is
 *   requesting a fresh link.
 */
import { useNavigate, useSearch } from '@tanstack/react-router';
import { useEffect, useId, useRef, useState, type ReactElement } from 'react';
import { detectedTimezone } from '../account/timezone';
import { flashState } from '../chrome/flash';
import { PageHeading } from '../chrome/PageHeading';
import { withAccount } from '../state/localState';
import { useApi, useLocalState, useLocalStateUpdate } from '../state/runtime';
import { t } from '../strings';

type RequestPhase = 'idle' | 'sending' | 'sent' | 'throttled' | 'failed';
type ConsumePhase = 'consuming' | 'expired' | 'throttled' | 'failed';

function RequestForm(): ReactElement {
  const api = useApi();
  const emailId = useId();
  const [email, setEmail] = useState('');
  const [phase, setPhase] = useState<RequestPhase>('idle');

  async function submit(): Promise<void> {
    setPhase('sending');
    try {
      const result = await api.post('/auth/magic-link', { body: { email } });
      // 202 is the constant response (account or not) — always "sent".
      setPhase(result.status === 202 ? 'sent' : 'throttled');
    } catch {
      setPhase('failed');
    }
  }

  if (phase === 'sent') {
    return (
      <p className="bf-lane" role="status" data-auth="sent">
        {t('auth.sent')}
      </p>
    );
  }

  return (
    <form
      className="bf-form"
      data-auth="request"
      onSubmit={(event) => {
        event.preventDefault();
        void submit();
      }}
    >
      <label className="bf-field__label" htmlFor={emailId}>
        {t('auth.email')}
      </label>
      <input
        className="bf-input"
        id={emailId}
        type="email"
        name="email"
        autoComplete="email"
        required
        value={email}
        onChange={(event) => {
          setEmail(event.target.value);
        }}
      />
      <button className="bf-button" type="submit" disabled={phase === 'sending'}>
        {t('auth.request')}
      </button>
      {phase === 'throttled' ? (
        <p className="bf-error" role="alert">
          {t('error.rateLimited')}
        </p>
      ) : null}
      {phase === 'failed' ? (
        <p className="bf-error" role="alert">
          {t('error.generic')}
        </p>
      ) : null}
    </form>
  );
}

function ConsumeLanding(props: { readonly token: string }): ReactElement {
  const api = useApi();
  const update = useLocalStateUpdate();
  const navigate = useNavigate();
  const [phase, setPhase] = useState<ConsumePhase>('consuming');
  const started = useRef(false);

  useEffect(() => {
    // Single-use token: guard against double effects (StrictMode) — a second
    // POST would 410 a link that just succeeded.
    if (started.current) return;
    started.current = true;
    void (async () => {
      try {
        const consumed = await api.post('/auth/magic-link/consume', {
          body: { token: props.token },
        });
        if (consumed.status !== 204) {
          setPhase(consumed.status === 410 ? 'expired' : 'throttled');
          return;
        }
        const me = await api.get('/me');
        if (me.status !== 200) {
          setPhase('failed');
          return;
        }
        // Browser-detected timezone becomes the profile default (streak
        // alert send time) — only while the profile sits at the server
        // default, so an explicit /settings choice is never overridden.
        const zone = detectedTimezone();
        if (me.data.timezone === 'UTC' && zone !== 'UTC') {
          await api.patch('/me', { body: { timezone: zone } });
        }
        const email = me.data.email;
        update((state) => withAccount(state, email));
        // WS-20 seam: the anonymous→account merge (POST /me/import of the
        // local record + account.merge.summary toast) attaches here, before
        // the hub redirect. See tasks/WS-14/STATUS.md.
        await navigate({ to: '/', state: flashState('auth.consumed') });
      } catch {
        setPhase('failed');
      }
    })();
  }, [api, navigate, props.token, update]);

  if (phase === 'consuming') {
    return (
      <p className="bf-lane__meta" role="status" data-ws="WS-20" data-auth="consuming">
        {t('auth.consuming')}
      </p>
    );
  }
  return (
    <>
      <p className="bf-error" role="alert" data-auth={phase}>
        {phase === 'expired'
          ? t('auth.expired')
          : phase === 'throttled'
            ? t('error.rateLimited')
            : t('error.generic')}
      </p>
      <RequestForm />
    </>
  );
}

export function LoginPage(): ReactElement {
  const search = useSearch({ strict: false });
  const state = useLocalState();
  const token = search.token;

  return (
    <>
      <PageHeading>{t('auth.request')}</PageHeading>
      {state.account === null ? <p className="bf-lane__meta">{t('streak.guestNote')}</p> : null}
      {token === undefined ? <RequestForm /> : <ConsumeLanding token={token} />}
    </>
  );
}
