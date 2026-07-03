/**
 * Route table (brief WS-09): / (hub) · /daily/{-$date} · /play ·
 * /academy · /academy/$slug · /me · /settings · /login. Code-based
 * TanStack Router tree; tests pass a memory history.
 */
import {
  createRootRoute,
  createRoute,
  createRouter,
  type RouterHistory,
  redirect,
} from '@tanstack/react-router';
import { AppChrome } from './chrome/AppChrome';
import { HubPage } from './hub/HubPage';
import type { Tier } from './state/localState';
import { AcademyLessonPage, AcademyPage } from './routes/AcademyPage';
import { DailyPage } from './routes/DailyPage';
import { LoginPage } from './routes/LoginPage';
import { MePage } from './routes/MePage';
import { NotFoundPage } from './routes/NotFoundPage';
import { PlayPage } from './routes/PlayPage';
import { SettingsPage } from './routes/SettingsPage';

const TIER_VALUES: readonly string[] = ['lookout', 'crew', 'hotshot'];

function isTier(value: unknown): value is Tier {
  return typeof value === 'string' && TIER_VALUES.includes(value);
}

const rootRoute = createRootRoute({
  component: AppChrome,
  notFoundComponent: NotFoundPage,
});

const hubRoute = createRoute({ getParentRoute: () => rootRoute, path: '/', component: HubPage });

const dailyRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/daily/{-$date}',
  component: DailyPage,
});

const playRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/play',
  component: PlayPage,
  validateSearch: (search: Record<string, unknown>): { tier?: Tier } =>
    isTier(search.tier) ? { tier: search.tier } : {},
});

const academyRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/academy',
  component: AcademyPage,
});

const academyLessonRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/academy/$slug',
  component: AcademyLessonPage,
});

const meRoute = createRoute({ getParentRoute: () => rootRoute, path: '/me', component: MePage });

const settingsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/settings',
  component: SettingsPage,
});

const hubAliasRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/hub',
  // Laravel's landing controller redirects authenticated users here (WS-15);
  // '/' is the canonical hub inside the SPA (integration fix, see
  // tasks/INTEGRATION-LOG.md 2026-07-03 WS-15 entry).
  beforeLoad: () => {
    // eslint-disable-next-line @typescript-eslint/only-throw-error -- TanStack's documented redirect idiom throws a Redirect object
    throw redirect({ to: '/' });
  },
});

const loginRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/login',
  component: LoginPage,
  // The consumed-link landing (ADR-0003): /login?token=… exchanges the
  // emailed single-use token for a session.
  validateSearch: (search: Record<string, unknown>): { token?: string } =>
    typeof search.token === 'string' && search.token !== '' ? { token: search.token } : {},
});

const routeTree = rootRoute.addChildren([
  hubRoute,
  dailyRoute,
  playRoute,
  academyRoute,
  academyLessonRoute,
  meRoute,
  settingsRoute,
  loginRoute,
  hubAliasRoute,
]);

export function createAppRouter(history?: RouterHistory) {
  return createRouter({
    routeTree,
    defaultPreload: false,
    ...(history === undefined ? {} : { history }),
  });
}

declare module '@tanstack/react-router' {
  interface Register {
    router: ReturnType<typeof createAppRouter>;
  }
}
