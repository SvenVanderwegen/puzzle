/**
 * SPA not-found surface. COPY.md has no dedicated 404 key; error.generic is
 * the closest calm-dispatcher line (public 404s are WS-15's Blade pages).
 */
import { Link } from '@tanstack/react-router';
import type { ReactElement } from 'react';
import { PageHeading } from '../chrome/PageHeading';
import { t } from '../strings';

export function NotFoundPage(): ReactElement {
  return (
    <>
      <PageHeading>{t('error.generic')}</PageHeading>
      <p>
        <Link to="/">{t('app.title')}</Link>
      </p>
    </>
  );
}
