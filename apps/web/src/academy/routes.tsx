/**
 * Academy route bodies — the lazy chunk. `routes/AcademyPage.tsx` React.lazy's
 * both exports from THIS module, so the whole feature (lesson player, beat
 * engine, board data, demos, ui-web board) lands in one on-demand chunk the
 * hub never pays for (budget:check keeps the initial bundle ≤ 200 KB).
 *
 * AcademyIndex is the lesson list with per-lesson completion and the Certified
 * badge at 7/7; LessonRoute resolves the slug to a LessonPlayer.
 */
import { Link, useParams } from '@tanstack/react-router';
import { useMemo, type ReactElement } from 'react';
import { PageHeading } from '../chrome/PageHeading';
import { useLocalState, useRuntime } from '../state/runtime';
import { t } from '../strings';
import { academyCss } from './academyCss';
import { LESSON_COUNT, LESSONS, lessonBySlug } from './lessons';
import { LessonPlayer } from './LessonPlayer';
import { completedSet } from './progress';

export function AcademyIndex(): ReactElement {
  const { storage } = useRuntime();
  const state = useLocalState();
  // state.academy.done changes on completion → recompute the per-lesson set.
  const done = useMemo(() => completedSet(storage), [storage, state.academy.done]);
  const certified = state.academy.done >= state.academy.total;

  return (
    <>
      <style>{academyCss}</style>
      <PageHeading>{t('hub.lane.academy')}</PageHeading>
      <p className="bf-academy__intro">{t('academy.intro')}</p>
      <div className="bf-lane__row">
        <span className="bf-badge" data-testid="academy-progress">
          {t('hub.academy.progress', { done: state.academy.done, total: state.academy.total })}
        </span>
        {certified ? (
          <span className="bf-badge bf-badge--certified" data-testid="certified-badge">
            {t('academy.certified')}
          </span>
        ) : null}
      </div>
      {certified ? <p className="bf-lane__meta">{t('academy.certified.note')}</p> : null}
      <ul className="bf-lessons">
        {LESSONS.map((lesson) => (
          <li className="bf-lessons__item" key={lesson.slug}>
            <Link
              className="bf-lessons__link"
              to="/academy/$slug"
              params={{ slug: lesson.slug }}
              data-slug={lesson.slug}
              data-done={done.has(lesson.slug)}
            >
              <span className="bf-lessons__ord" aria-hidden="true">
                {lesson.order}
              </span>
              <span className="bf-lessons__title">{t(lesson.titleKey)}</span>
              {lesson.capstone ? (
                <span className="bf-badge">{t('academy.capstoneBadge')}</span>
              ) : null}
              {done.has(lesson.slug) ? (
                <span className="bf-badge bf-badge--done">{t('academy.lesson.done')}</span>
              ) : null}
            </Link>
            <p className="bf-lessons__blurb">{t(lesson.blurbKey)}</p>
          </li>
        ))}
      </ul>
    </>
  );
}

export function LessonRoute(): ReactElement {
  const params = useParams({ strict: false });
  const lesson = lessonBySlug(params.slug ?? '');

  if (lesson === undefined) {
    return (
      <>
        <PageHeading>{t('hub.lane.academy')}</PageHeading>
        <p className="bf-hint">{t('error.generic')}</p>
        <Link className="bf-chip" to="/academy">
          {t('academy.lesson.back')}
        </Link>
      </>
    );
  }

  return (
    <>
      <PageHeading>{t(lesson.titleKey)}</PageHeading>
      {lesson.capstone ? (
        <p>
          <span className="bf-badge">{t('academy.capstoneBadge')}</span>
        </p>
      ) : null}
      <LessonPlayer lesson={lesson} />
    </>
  );
}

// The number of lessons, re-exported so the shell can assert the course size
// without importing the whole feature eagerly.
export { LESSON_COUNT };
