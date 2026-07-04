/**
 * /academy and /academy/$slug — thin route shells that lazily load the Academy
 * feature (WS-12). The heavy graph (lesson player, beat engine, board data,
 * ui-web board) lives in one on-demand chunk (src/academy/routes.tsx), so the
 * hub and every other route stay off the academy's weight and the initial
 * bundle stays under budget (playbook §5 gate 7). Both exports lazy-import the
 * SAME module, so they resolve to a single shared chunk.
 */
import { lazy, Suspense, type ReactElement } from 'react';
import { t } from '../strings';

const AcademyIndex = lazy(() =>
  import('../academy/routes').then((module) => ({ default: module.AcademyIndex })),
);
const LessonRoute = lazy(() =>
  import('../academy/routes').then((module) => ({ default: module.LessonRoute })),
);

function Loading(): ReactElement {
  return (
    <p className="bf-lane__meta" role="status">
      {t('play.loading')}
    </p>
  );
}

export function AcademyPage(): ReactElement {
  return (
    <div data-ws="WS-12">
      <Suspense fallback={<Loading />}>
        <AcademyIndex />
      </Suspense>
    </div>
  );
}

export function AcademyLessonPage(): ReactElement {
  return (
    <div data-ws="WS-12">
      <Suspense fallback={<Loading />}>
        <LessonRoute />
      </Suspense>
    </div>
  );
}
