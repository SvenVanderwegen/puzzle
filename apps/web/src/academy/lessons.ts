/**
 * The seven-lesson course (product §5), 1:1 with the deduction toolkit and the
 * WS-05 academy pack. Each lesson is an animated demo (demos.ts) plus two
 * practice boards drawn from the pack (boards.ts), unrated. The `technique`
 * field is the pack tag the practice boards are filtered to require
 * (pipeline/GRADING.md §5); L1 and L7 carry no tag by construction (rules
 * walkthrough / board-shape capstone), asserted in practiceTags.test.ts.
 *
 * Lesson order is the toolkit order: rules → too-fast → too-slow → chains →
 * pockets → counting → the capstone that needs all of them.
 */
import type { DeductionKind } from '@burnfront/engine';
import type { StringKey } from '../strings';
import type { DemoScript } from './beats';
import type { PracticePuzzleId } from './boards';
import { DEMO_SCRIPTS } from './demos';

export type LessonSlug =
  | 'first-shift'
  | 'too-fast-means-walls'
  | 'too-slow-means-roads'
  | 'chains-to-the-spark'
  | 'nothing-is-spared'
  | 'counting-the-endgame'
  | 'the-long-way-around';

export interface Lesson {
  readonly slug: LessonSlug;
  /** 1-based position in the course. */
  readonly order: number;
  readonly titleKey: StringKey;
  readonly blurbKey: StringKey;
  /** The pack technique tag the practice boards require, or null (L1/L7). */
  readonly technique: DeductionKind | null;
  /** The two pack puzzle ids that back this lesson's practice. */
  readonly practice: readonly [PracticePuzzleId, PracticePuzzleId];
  readonly demo: DemoScript;
  /** The final board-shape capstone (product §5). */
  readonly capstone: boolean;
}

/** The First Shift lesson is also the Play-button first-visit funnel entry. */
export const FIRST_SHIFT_SLUG: LessonSlug = 'first-shift';

export const LESSONS: readonly Lesson[] = [
  {
    slug: 'first-shift',
    order: 1,
    titleKey: 'academy.l1.title',
    blurbKey: 'academy.l1.blurb',
    technique: null,
    practice: ['bf1-5x5-000003', 'bf1-5x5-000004'],
    demo: DEMO_SCRIPTS['first-shift'],
    capstone: false,
  },
  {
    slug: 'too-fast-means-walls',
    order: 2,
    titleKey: 'academy.l2.title',
    blurbKey: 'academy.l2.blurb',
    technique: 'clue_reached_too_fast',
    practice: ['bf1-5x5-000005', 'bf1-5x5-000006'],
    demo: DEMO_SCRIPTS['too-fast-means-walls'],
    capstone: false,
  },
  {
    slug: 'too-slow-means-roads',
    order: 3,
    titleKey: 'academy.l3.title',
    blurbKey: 'academy.l3.blurb',
    technique: 'clue_unreachable_in_time',
    practice: ['bf1-5x5-000007', 'bf1-5x5-000008'],
    demo: DEMO_SCRIPTS['too-slow-means-roads'],
    capstone: false,
  },
  {
    slug: 'chains-to-the-spark',
    order: 4,
    titleKey: 'academy.l4.title',
    blurbKey: 'academy.l4.blurb',
    technique: 'clue_unreachable_in_time',
    practice: ['bf1-5x5-000009', 'bf1-5x5-000010'],
    demo: DEMO_SCRIPTS['chains-to-the-spark'],
    capstone: false,
  },
  {
    slug: 'nothing-is-spared',
    order: 5,
    titleKey: 'academy.l5.title',
    blurbKey: 'academy.l5.blurb',
    technique: 'clue_unreachable_in_time',
    practice: ['bf1-6x6-000004', 'bf1-6x6-000005'],
    demo: DEMO_SCRIPTS['nothing-is-spared'],
    capstone: false,
  },
  {
    slug: 'counting-the-endgame',
    order: 6,
    titleKey: 'academy.l6.title',
    blurbKey: 'academy.l6.blurb',
    technique: 'all_breaks_placed',
    practice: ['bf1-5x5-000011', 'bf1-5x5-000012'],
    demo: DEMO_SCRIPTS['counting-the-endgame'],
    capstone: false,
  },
  {
    slug: 'the-long-way-around',
    order: 7,
    titleKey: 'academy.l7.title',
    blurbKey: 'academy.l7.blurb',
    technique: null,
    practice: ['bf1-7x7-000002', 'bf1-7x7-000003'],
    demo: DEMO_SCRIPTS['the-long-way-around'],
    capstone: true,
  },
];

export const LESSON_COUNT = LESSONS.length;

const BY_SLUG: Record<LessonSlug, Lesson> = Object.fromEntries(
  LESSONS.map((lesson) => [lesson.slug, lesson]),
) as Record<LessonSlug, Lesson>;

export function lessonBySlug(slug: string): Lesson | undefined {
  return Object.prototype.hasOwnProperty.call(BY_SLUG, slug)
    ? BY_SLUG[slug as LessonSlug]
    : undefined;
}

/** The lesson after `slug` in course order, or null at the end. */
export function nextLesson(slug: LessonSlug): Lesson | null {
  const lesson = BY_SLUG[slug];
  return LESSONS.find((candidate) => candidate.order === lesson.order + 1) ?? null;
}
