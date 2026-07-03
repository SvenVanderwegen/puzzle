/**
 * Root layout: night-incident-map chrome, route-change focus management and
 * aria-live route announcements, plus the offline notice (error.offline).
 * Every visible string comes from the keyed catalog (CLAUDE.md rule 7).
 */
import { Link, Outlet, useRouterState } from '@tanstack/react-router';
import { useEffect, useRef, useState, type ReactElement } from 'react';
import { t } from '../strings';
import { useLocalState } from '../state/runtime';
import { appCssText } from './appCss';
import { useOnline } from './useOnline';

/**
 * On navigation (not initial load): move focus to the new page's heading and
 * announce it politely. Headings are rendered with tabIndex={-1} via
 * PageHeading, so focus lands without adding them to the tab order.
 */
function useRouteFocus(): string {
  // resolvedLocation: the navigation has committed and the new page is in the
  // DOM (location alone flips while the old page still shows).
  const pathname = useRouterState({
    select: (state) => state.resolvedLocation?.pathname ?? state.location.pathname,
  });
  const [announcement, setAnnouncement] = useState('');
  const isFirst = useRef(true);
  useEffect(() => {
    if (isFirst.current) {
      isFirst.current = false;
      return;
    }
    const heading = document.querySelector<HTMLElement>('main h1');
    if (heading !== null) {
      heading.focus();
      setAnnouncement(heading.textContent ?? '');
    }
  }, [pathname]);
  return announcement;
}

export function AppChrome(): ReactElement {
  const announcement = useRouteFocus();
  const online = useOnline();
  const state = useLocalState();

  return (
    <div className="bf-app">
      <style>{appCssText()}</style>
      <header className="bf-header">
        <div>
          <span className="bf-header__eyebrow">{t('app.eyebrow')}</span>
          <Link className="bf-header__title" to="/">
            {t('app.title')}
          </Link>
        </div>
        <div className="bf-header__spacer" />
        {state.account === null ? (
          <Link className="bf-chip" to="/login" data-ws="WS-14">
            {t('hub.guest')}
          </Link>
        ) : (
          <Link className="bf-chip" to="/me">
            {state.account.email}
          </Link>
        )}
      </header>
      {online ? null : (
        <p className="bf-offline" role="status">
          {t('error.offline')}
        </p>
      )}
      <main className="bf-main">
        <Outlet />
      </main>
      <div aria-live="polite" role="status" className="bf-vh">
        {announcement}
      </div>
    </div>
  );
}
