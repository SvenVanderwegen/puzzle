/**
 * The seven walkthrough demos — one fixed teaching board per lesson, each
 * scripted as discrete beats (beats.ts). These are hand-authored (small,
 * legible) rather than pack boards, so each demo isolates exactly one
 * argument, the way the prototype's 4×5 demo does. The wave minutes shown are
 * the ENGINE's own burn times over the solution (BeatPlayer computes them with
 * `burnTimes`), never hand-typed — so the animation can never disagree with
 * the rules. `demos.test.ts` asserts every board validates against its
 * solution and every highlighted/wave claim matches the engine.
 *
 * Cell indices are row-major (`r * cols + c`).
 */
import type { LessonSlug } from './lessons';
import type { DemoScript } from './beats';

export const DEMO_SCRIPTS: Readonly<Record<LessonSlug, DemoScript>> = {
  // L1 — rules. 4×5, spark A3, breaks force the classic detour the prototype
  // teaches: a clue three steps out that only burns at five.
  'first-shift': {
    board: {
      rows: 4,
      cols: 5,
      spark: { r: 2, c: 0 },
      breaks: 2,
      clues: [
        { r: 3, c: 2, m: 3 },
        { r: 2, c: 3, m: 5 },
      ],
    },
    solutionBreaks: [7, 12],
    beats: [
      { kind: 'wave', captionKey: 'academy.l1.beat.1', waveMinute: 0, breaksShown: 2, durMs: 2600 },
      { kind: 'wave', captionKey: 'academy.l1.beat.2', waveMinute: 2, breaksShown: 2, durMs: 1600 },
      {
        kind: 'wave',
        captionKey: 'academy.l1.beat.3',
        waveMinute: 3,
        breaksShown: 2,
        highlight: { cells: [17], style: 'focus' },
        durMs: 2400,
      },
      {
        kind: 'wave',
        captionKey: 'academy.l1.beat.4',
        waveMinute: 5,
        breaksShown: 2,
        highlight: { cells: [13], style: 'focus' },
        durMs: 3000,
      },
      { kind: 'wave', captionKey: 'academy.l1.beat.5', waveMinute: 7, breaksShown: 2, durMs: 2200 },
      {
        kind: 'caption',
        captionKey: 'academy.l1.beat.6',
        waveMinute: 7,
        breaksShown: 2,
        durMs: 3200,
      },
    ],
  },

  // L2 — clue_reached_too_fast. 5×5, spark A1. The clue at D1 (3 steps) reads 7,
  // so C1 must wall the fast route.
  'too-fast-means-walls': {
    board: {
      rows: 5,
      cols: 5,
      spark: { r: 0, c: 0 },
      breaks: 2,
      clues: [{ r: 0, c: 3, m: 7 }],
    },
    solutionBreaks: [2, 7],
    beats: [
      {
        kind: 'highlight',
        captionKey: 'academy.l2.beat.1',
        waveMinute: -1,
        breaksShown: 0,
        highlight: { cells: [3], style: 'focus' },
        durMs: 2600,
      },
      {
        kind: 'highlight',
        captionKey: 'academy.l2.beat.2',
        waveMinute: -1,
        breaksShown: 0,
        highlight: { cells: [1, 2, 3], style: 'route' },
        durMs: 3000,
      },
      {
        kind: 'prompt',
        captionKey: 'academy.l2.beat.3',
        waveMinute: -1,
        breaksShown: 0,
        highlight: { cells: [2], style: 'focus' },
        durMs: 3200,
      },
      {
        kind: 'reveal',
        captionKey: 'academy.l2.beat.4',
        waveMinute: -1,
        breaksShown: 1,
        highlight: { cells: [2], style: 'wall' },
        durMs: 2400,
      },
      { kind: 'wave', captionKey: 'academy.l2.beat.5', waveMinute: 8, breaksShown: 2, durMs: 3200 },
    ],
  },

  // L3 — clue_unreachable_in_time (open forced). 5×5, spark A5 (r4,c0). The A1
  // corner reads 4 = its distance; column 0 is the only road and must stay open.
  'too-slow-means-roads': {
    board: {
      rows: 5,
      cols: 5,
      spark: { r: 4, c: 0 },
      breaks: 2,
      clues: [{ r: 0, c: 0, m: 4 }],
    },
    solutionBreaks: [16, 21],
    beats: [
      {
        kind: 'highlight',
        captionKey: 'academy.l3.beat.1',
        waveMinute: -1,
        breaksShown: 2,
        highlight: { cells: [0], style: 'focus' },
        durMs: 2600,
      },
      {
        kind: 'highlight',
        captionKey: 'academy.l3.beat.2',
        waveMinute: -1,
        breaksShown: 2,
        highlight: { cells: [5, 10, 15], style: 'route' },
        durMs: 3000,
      },
      {
        kind: 'prompt',
        captionKey: 'academy.l3.beat.3',
        waveMinute: -1,
        breaksShown: 2,
        highlight: { cells: [5, 10, 15], style: 'route' },
        durMs: 3200,
      },
      {
        kind: 'wave',
        captionKey: 'academy.l3.beat.4',
        waveMinute: 4,
        breaksShown: 2,
        highlight: { cells: [5, 10, 15], style: 'route' },
        durMs: 3000,
      },
    ],
  },

  // L4 — chain of clue_unreachable_in_time steps. 5×5, spark E5. The A1 corner
  // reads 8; the top-then-right edge is the unbroken t, t−1, … chain to the spark.
  'chains-to-the-spark': {
    board: {
      rows: 5,
      cols: 5,
      spark: { r: 4, c: 4 },
      breaks: 3,
      clues: [{ r: 0, c: 0, m: 8 }],
    },
    solutionBreaks: [13, 17, 18],
    beats: [
      {
        kind: 'highlight',
        captionKey: 'academy.l4.beat.1',
        waveMinute: -1,
        breaksShown: 3,
        highlight: { cells: [0], style: 'focus' },
        durMs: 2600,
      },
      {
        kind: 'highlight',
        captionKey: 'academy.l4.beat.2',
        waveMinute: -1,
        breaksShown: 3,
        highlight: { cells: [0, 1], style: 'route' },
        durMs: 3000,
      },
      {
        kind: 'highlight',
        captionKey: 'academy.l4.beat.3',
        waveMinute: -1,
        breaksShown: 3,
        highlight: { cells: [0, 1, 2, 3, 4, 9, 14, 19], style: 'route' },
        durMs: 3400,
      },
      {
        kind: 'wave',
        captionKey: 'academy.l4.beat.4',
        waveMinute: 8,
        breaksShown: 3,
        highlight: { cells: [0, 1, 2, 3, 4, 9, 14, 19], style: 'route' },
        durMs: 3200,
      },
    ],
  },

  // L5 — no sealed pockets (open_cell_unreachable, shown on a fixed board per
  // GRADING.md §5). 4×4, spark D4. Walling B1+A2 would seal the A1 corner.
  'nothing-is-spared': {
    board: {
      rows: 4,
      cols: 4,
      spark: { r: 3, c: 3 },
      breaks: 2,
      clues: [{ r: 0, c: 0, m: 6 }],
    },
    solutionBreaks: [2, 8],
    beats: [
      { kind: 'wave', captionKey: 'academy.l5.beat.1', waveMinute: 6, breaksShown: 2, durMs: 2600 },
      {
        kind: 'highlight',
        captionKey: 'academy.l5.beat.2',
        waveMinute: 6,
        breaksShown: 2,
        highlight: { cells: [0, 1, 4], style: 'route' },
        durMs: 3000,
      },
      {
        kind: 'prompt',
        captionKey: 'academy.l5.beat.3',
        waveMinute: 6,
        breaksShown: 2,
        highlight: { cells: [1, 4], style: 'wall' },
        durMs: 3400,
      },
      {
        kind: 'highlight',
        captionKey: 'academy.l5.beat.4',
        waveMinute: 6,
        breaksShown: 2,
        highlight: { cells: [0, 1, 4], style: 'route' },
        durMs: 3000,
      },
    ],
  },

  // L6 — all_breaks_placed count-fill. 4×4, spark A1, all four breaks visible;
  // once the count is complete, every remaining cell must burn.
  'counting-the-endgame': {
    board: {
      rows: 4,
      cols: 4,
      spark: { r: 0, c: 0 },
      breaks: 4,
      clues: [{ r: 3, c: 3, m: 6 }],
    },
    solutionBreaks: [3, 5, 10, 12],
    beats: [
      {
        kind: 'reveal',
        captionKey: 'academy.l6.beat.1',
        waveMinute: -1,
        breaksShown: 4,
        durMs: 2400,
      },
      {
        kind: 'highlight',
        captionKey: 'academy.l6.beat.2',
        waveMinute: -1,
        breaksShown: 4,
        highlight: { cells: [3, 5, 10, 12], style: 'wall' },
        durMs: 3000,
      },
      {
        kind: 'highlight',
        captionKey: 'academy.l6.beat.3',
        waveMinute: -1,
        breaksShown: 4,
        highlight: { cells: [1, 2, 4, 6, 7, 8, 9, 11, 13, 14, 15], style: 'route' },
        durMs: 3400,
      },
      { kind: 'wave', captionKey: 'academy.l6.beat.4', waveMinute: 6, breaksShown: 4, durMs: 3000 },
    ],
  },

  // L7 — the capstone showpiece at demo scale. 5×5, spark A5. The clue at A3
  // sits two cells from the spark yet reads 8 — reachable only the long way.
  'the-long-way-around': {
    board: {
      rows: 5,
      cols: 5,
      spark: { r: 4, c: 0 },
      breaks: 3,
      clues: [{ r: 2, c: 0, m: 8 }],
    },
    solutionBreaks: [15, 16, 11],
    beats: [
      {
        kind: 'highlight',
        captionKey: 'academy.l7.beat.1',
        waveMinute: -1,
        breaksShown: 0,
        highlight: { cells: [10], style: 'focus' },
        durMs: 2600,
      },
      {
        kind: 'reveal',
        captionKey: 'academy.l7.beat.2',
        waveMinute: -1,
        breaksShown: 1,
        highlight: { cells: [15], style: 'wall' },
        durMs: 3000,
      },
      {
        kind: 'reveal',
        captionKey: 'academy.l7.beat.3',
        waveMinute: -1,
        breaksShown: 3,
        highlight: { cells: [15, 16, 11], style: 'wall' },
        durMs: 3000,
      },
      {
        kind: 'wave',
        captionKey: 'academy.l7.beat.4',
        waveMinute: 8,
        breaksShown: 3,
        highlight: { cells: [21, 22, 17, 12, 7, 6, 5, 10], style: 'route' },
        durMs: 3600,
      },
      {
        kind: 'caption',
        captionKey: 'academy.l7.beat.5',
        waveMinute: 8,
        breaksShown: 3,
        durMs: 3400,
      },
    ],
  },
};
