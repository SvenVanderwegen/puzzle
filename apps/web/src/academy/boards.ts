/**
 * The 14 Academy practice boards (WS-05 pack `academy-1`), bundled as
 * BoardSpecs so the lesson player runs offline with zero content fetches.
 *
 * These are the exact `board` objects of the signed pack's puzzle files
 * (pipeline/tests/fixtures/content-sample/v20260706-1/puzzles/*.json). The
 * `boards.fidelity.test.ts` reads those fixtures and deep-compares, so a
 * transcription drift fails CI — the fixture is the source of truth, this is a
 * build-time transcription of it. Academy boards are ALWAYS unrated
 * (RATING.md §3); nothing here feeds the Fire Rating.
 *
 * Spark is `{ r, c }` (engine BoardSpec), not the fixture's `[r, c]` tuple.
 */
import type { BoardSpec } from '@burnfront/engine';

export type PracticePuzzleId =
  | 'bf1-5x5-000003'
  | 'bf1-5x5-000004'
  | 'bf1-5x5-000005'
  | 'bf1-5x5-000006'
  | 'bf1-5x5-000007'
  | 'bf1-5x5-000008'
  | 'bf1-5x5-000009'
  | 'bf1-5x5-000010'
  | 'bf1-6x6-000004'
  | 'bf1-6x6-000005'
  | 'bf1-5x5-000011'
  | 'bf1-5x5-000012'
  | 'bf1-7x7-000002'
  | 'bf1-7x7-000003';

export const PRACTICE_BOARDS: Readonly<Record<PracticePuzzleId, BoardSpec>> = {
  'bf1-5x5-000003': {
    rows: 5,
    cols: 5,
    spark: { r: 0, c: 4 },
    breaks: 4,
    clues: [
      { r: 0, c: 1, m: 7 },
      { r: 0, c: 3, m: 1 },
      { r: 1, c: 0, m: 7 },
      { r: 1, c: 1, m: 6 },
      { r: 1, c: 3, m: 2 },
      { r: 2, c: 2, m: 4 },
      { r: 2, c: 3, m: 3 },
      { r: 3, c: 2, m: 5 },
      { r: 3, c: 4, m: 9 },
      { r: 4, c: 1, m: 7 },
    ],
  },
  'bf1-5x5-000004': {
    rows: 5,
    cols: 5,
    spark: { r: 3, c: 1 },
    breaks: 4,
    clues: [
      { r: 0, c: 0, m: 4 },
      { r: 0, c: 1, m: 3 },
      { r: 0, c: 4, m: 6 },
      { r: 1, c: 3, m: 6 },
      { r: 2, c: 0, m: 2 },
      { r: 2, c: 2, m: 2 },
      { r: 3, c: 0, m: 1 },
      { r: 3, c: 2, m: 1 },
      { r: 4, c: 1, m: 1 },
      { r: 4, c: 3, m: 11 },
    ],
  },
  'bf1-5x5-000005': {
    rows: 5,
    cols: 5,
    spark: { r: 0, c: 1 },
    breaks: 4,
    clues: [
      { r: 0, c: 0, m: 1 },
      { r: 1, c: 3, m: 3 },
      { r: 2, c: 0, m: 11 },
      { r: 2, c: 1, m: 10 },
      { r: 2, c: 2, m: 9 },
    ],
  },
  'bf1-5x5-000006': {
    rows: 5,
    cols: 5,
    spark: { r: 1, c: 1 },
    breaks: 4,
    clues: [
      { r: 1, c: 3, m: 2 },
      { r: 2, c: 4, m: 8 },
      { r: 3, c: 1, m: 4 },
      { r: 4, c: 2, m: 6 },
    ],
  },
  'bf1-5x5-000007': {
    rows: 5,
    cols: 5,
    spark: { r: 3, c: 4 },
    breaks: 4,
    clues: [
      { r: 1, c: 4, m: 6 },
      { r: 4, c: 0, m: 7 },
      { r: 4, c: 2, m: 3 },
    ],
  },
  'bf1-5x5-000008': {
    rows: 5,
    cols: 5,
    spark: { r: 3, c: 0 },
    breaks: 4,
    clues: [
      { r: 0, c: 0, m: 7 },
      { r: 2, c: 0, m: 1 },
      { r: 3, c: 1, m: 1 },
      { r: 4, c: 2, m: 7 },
    ],
  },
  'bf1-5x5-000009': {
    rows: 5,
    cols: 5,
    spark: { r: 4, c: 3 },
    breaks: 4,
    clues: [
      { r: 1, c: 3, m: 9 },
      { r: 2, c: 2, m: 5 },
      { r: 2, c: 4, m: 11 },
      { r: 3, c: 3, m: 1 },
    ],
  },
  'bf1-5x5-000010': {
    rows: 5,
    cols: 5,
    spark: { r: 0, c: 0 },
    breaks: 4,
    clues: [
      { r: 0, c: 2, m: 12 },
      { r: 1, c: 2, m: 11 },
      { r: 2, c: 1, m: 3 },
      { r: 3, c: 2, m: 7 },
    ],
  },
  'bf1-6x6-000004': {
    rows: 6,
    cols: 6,
    spark: { r: 5, c: 1 },
    breaks: 8,
    clues: [
      { r: 0, c: 0, m: 12 },
      { r: 0, c: 1, m: 11 },
      { r: 0, c: 2, m: 10 },
      { r: 0, c: 3, m: 9 },
      { r: 1, c: 0, m: 13 },
      { r: 1, c: 3, m: 8 },
      { r: 1, c: 4, m: 7 },
      { r: 2, c: 0, m: 14 },
      { r: 2, c: 2, m: 18 },
      { r: 3, c: 1, m: 16 },
      { r: 3, c: 2, m: 17 },
      { r: 5, c: 0, m: 1 },
      { r: 5, c: 5, m: 6 },
    ],
  },
  'bf1-6x6-000005': {
    rows: 6,
    cols: 6,
    spark: { r: 2, c: 5 },
    breaks: 8,
    clues: [
      { r: 0, c: 0, m: 11 },
      { r: 0, c: 2, m: 5 },
      { r: 0, c: 5, m: 4 },
      { r: 1, c: 2, m: 4 },
      { r: 2, c: 2, m: 3 },
      { r: 3, c: 2, m: 4 },
      { r: 3, c: 5, m: 1 },
      { r: 4, c: 4, m: 9 },
    ],
  },
  'bf1-5x5-000011': {
    rows: 5,
    cols: 5,
    spark: { r: 4, c: 2 },
    breaks: 4,
    clues: [
      { r: 1, c: 2, m: 5 },
      { r: 1, c: 4, m: 9 },
      { r: 2, c: 3, m: 3 },
      { r: 4, c: 0, m: 4 },
    ],
  },
  'bf1-5x5-000012': {
    rows: 5,
    cols: 5,
    spark: { r: 1, c: 1 },
    breaks: 4,
    clues: [
      { r: 1, c: 4, m: 3 },
      { r: 2, c: 1, m: 1 },
      { r: 2, c: 4, m: 4 },
      { r: 3, c: 0, m: 11 },
      { r: 3, c: 2, m: 9 },
      { r: 3, c: 4, m: 5 },
    ],
  },
  'bf1-7x7-000002': {
    rows: 7,
    cols: 7,
    spark: { r: 4, c: 4 },
    breaks: 12,
    clues: [
      { r: 0, c: 0, m: 14 },
      { r: 0, c: 1, m: 13 },
      { r: 1, c: 2, m: 11 },
      { r: 1, c: 3, m: 10 },
      { r: 1, c: 5, m: 6 },
      { r: 2, c: 1, m: 13 },
      { r: 2, c: 4, m: 2 },
      { r: 3, c: 3, m: 2 },
      { r: 4, c: 2, m: 18 },
      { r: 5, c: 3, m: 8 },
      { r: 6, c: 0, m: 10 },
    ],
  },
  'bf1-7x7-000003': {
    rows: 7,
    cols: 7,
    spark: { r: 4, c: 2 },
    breaks: 12,
    clues: [
      { r: 1, c: 2, m: 9 },
      { r: 1, c: 3, m: 10 },
      { r: 1, c: 4, m: 11 },
      { r: 2, c: 1, m: 5 },
      { r: 2, c: 5, m: 15 },
      { r: 3, c: 3, m: 2 },
      { r: 3, c: 5, m: 16 },
      { r: 4, c: 6, m: 16 },
      { r: 5, c: 3, m: 22 },
      { r: 6, c: 1, m: 3 },
      { r: 6, c: 3, m: 21 },
      { r: 6, c: 6, m: 20 },
    ],
  },
};
</content>
