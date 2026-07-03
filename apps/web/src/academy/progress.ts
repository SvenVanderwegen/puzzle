/**
 * Academy progress — per-lesson completion, source of truth for the hub badge.
 *
 * Same two-store shape as Endless (endless/prefs.ts): the per-lesson detail
 * lives in its own feature store (`burnfront.academy.v1`), while the hub-facing
 * summary (`academy.done` / `firstShiftDone`) is mirrored into the shared
 * anonymous-first LocalState through the WS-14 runtime store so every consumer
 * re-renders live (see recordLessonCompletion in LessonPlayer). Local
 * completion is authoritative regardless of any account sync (brief §4).
 *
 * Storage is injected; malformed/absent payloads fall back to empty.
 */
import type { StorageLike } from '../state/localState';
import { LESSONS, type LessonSlug } from './lessons';

export const ACADEMY_PROGRESS_KEY = 'burnfront.academy.v1';

export interface AcademyProgress {
  readonly v: 1;
  /** Completed lesson slugs, order-insensitive; deduped on write. */
  readonly completed: readonly LessonSlug[];
}

const VALID_SLUGS = new Set<string>(LESSONS.map((lesson) => lesson.slug));

function isLessonSlug(value: unknown): value is LessonSlug {
  return typeof value === 'string' && VALID_SLUGS.has(value);
}

export function emptyProgress(): AcademyProgress {
  return { v: 1, completed: [] };
}

/** Tolerant load: unknown slugs are dropped, malformed payloads reset. */
export function loadAcademyProgress(storage: StorageLike): AcademyProgress {
  const raw = storage.getItem(ACADEMY_PROGRESS_KEY);
  if (raw === null || raw === '') return emptyProgress();
  try {
    const parsed: unknown = JSON.parse(raw);
    if (typeof parsed !== 'object' || parsed === null) return emptyProgress();
    const candidate = parsed as { v?: unknown; completed?: unknown };
    if (candidate.v !== 1 || !Array.isArray(candidate.completed)) return emptyProgress();
    const completed = [...new Set((candidate.completed as unknown[]).filter(isLessonSlug))];
    return { v: 1, completed };
  } catch {
    return emptyProgress();
  }
}

export function saveAcademyProgress(storage: StorageLike, progress: AcademyProgress): void {
  storage.setItem(ACADEMY_PROGRESS_KEY, JSON.stringify(progress));
}

export function isLessonComplete(storage: StorageLike, slug: LessonSlug): boolean {
  return loadAcademyProgress(storage).completed.includes(slug);
}

export function completedCount(storage: StorageLike): number {
  return loadAcademyProgress(storage).completed.length;
}

export function completedSet(storage: StorageLike): ReadonlySet<LessonSlug> {
  return new Set(loadAcademyProgress(storage).completed);
}

/**
 * Marks a lesson complete in the academy feature store; idempotent. Returns the
 * new completed count so the caller can mirror `academy.done` into LocalState.
 */
export function markLessonComplete(storage: StorageLike, slug: LessonSlug): number {
  const progress = loadAcademyProgress(storage);
  if (progress.completed.includes(slug)) return progress.completed.length;
  const completed = [...progress.completed, slug];
  saveAcademyProgress(storage, { v: 1, completed });
  return completed.length;
}
</content>
