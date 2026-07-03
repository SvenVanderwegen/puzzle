/**
 * /login stub — heading + guest context; the magic-link form (auth.request →
 * auth.sent flow, ADR-0003) is WS-14's data-ws area.
 */
import type { ReactElement } from 'react';
import { PageHeading } from '../chrome/PageHeading';
import { t } from '../strings';

export function LoginPage(): ReactElement {
  return (
    <>
      <PageHeading>{t('auth.request')}</PageHeading>
      <p className="bf-lane__meta">{t('streak.guestNote')}</p>
      <section data-ws="WS-14" aria-labelledby="bf-login-area">
        <p className="bf-hint" id="bf-login-area">
          {t('auth.sent')}
        </p>
      </section>
    </>
  );
}
