/**
 * /academy and /academy/$slug stubs — heading, local progress line, and the
 * lesson area WS-12 replaces (7 lessons per product §5).
 */
import { useParams } from '@tanstack/react-router';
import type { ReactElement } from 'react';
import { PageHeading } from '../chrome/PageHeading';
import { useLocalState } from '../state/runtime';
import { t } from '../strings';

export function AcademyPage(): ReactElement {
  const state = useLocalState();
  return (
    <>
      <PageHeading>{t('hub.lane.academy')}</PageHeading>
      <p className="bf-lane__meta">
        {t('hub.academy.progress', { done: state.academy.done, total: state.academy.total })}
      </p>
      <section data-ws="WS-12" aria-labelledby="bf-academy-area">
        <p className="bf-lane__meta" id="bf-academy-area">
          {t('play.loading')}
        </p>
      </section>
    </>
  );
}

export function AcademyLessonPage(): ReactElement {
  const params = useParams({ strict: false });
  return (
    <>
      <PageHeading>{t('hub.lane.academy')}</PageHeading>
      <p>
        <span className="bf-chip">
          <code>{params.slug ?? ''}</code>
        </span>
      </p>
      <section data-ws="WS-12" aria-labelledby="bf-lesson-area">
        <p className="bf-lane__meta" id="bf-lesson-area">
          {t('play.loading')}
        </p>
      </section>
    </>
  );
}
