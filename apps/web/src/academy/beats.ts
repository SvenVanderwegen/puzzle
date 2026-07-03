/**
 * The beat model — the data behind the animated walkthrough, ported from the
 * prototype's scripted demo (reference/index.html, the second `<script>`).
 *
 * Like the prototype, every beat describes the WHOLE demo state, so play,
 * pause, step-forward and step-back are all trivially consistent: rendering
 * beat k is a pure function of the board, its solution and BEATS[k]. The
 * reduced-motion variant just advances the same beats by hand instead of on a
 * timer (BeatPlayer). No beat ever mutates anything.
 *
 * `kind` is informational: it tags each beat for the tutorial_step analytics
 * seam and drives the interactive-prompt pause. It never changes the render.
 */
import type { BoardSpec } from '@burnfront/engine';
import type { IcuParams, StringKey } from '../strings';

export type BeatKind =
  /** Caption only — a rule or an observation, no wave change. */
  | 'caption'
  /** The burn wave advances to `waveMinute`. */
  | 'wave'
  /** A firebreak is revealed (breaksShown steps up). */
  | 'reveal'
  /** Cells are called out (highlight set) without a wave change. */
  | 'highlight'
  /** A question the learner sits with before advancing (autoplay pauses). */
  | 'prompt';

/** How a highlighted set reads: an open road, a wall, or a focused clue. */
export type HighlightStyle = 'route' | 'wall' | 'focus';

export interface BeatHighlight {
  /** Row-major cell indices to call out. */
  readonly cells: readonly number[];
  readonly style: HighlightStyle;
}

export interface Beat {
  readonly kind: BeatKind;
  readonly captionKey: StringKey;
  readonly captionParams?: IcuParams;
  /**
   * Burn minute to show: cells whose burn time is ≤ this render as burnt.
   * `-1` = before ignition (nothing burnt yet). Firebreaks never burn.
   */
  readonly waveMinute: number;
  /** How many of `DemoScript.solutionBreaks` render as walls at this beat. */
  readonly breaksShown: number;
  readonly highlight?: BeatHighlight;
  /** Autoplay dwell for this beat, in ms (ignored under reduced motion). */
  readonly durMs: number;
}

export interface DemoScript {
  /** The fixed teaching board (hand-authored; validated in demos.test.ts). */
  readonly board: BoardSpec;
  /**
   * The solution's firebreaks as row-major indices, in reveal order. Length is
   * `board.breaks`; `validate(board, shadingFrom(solutionBreaks))` is valid.
   */
  readonly solutionBreaks: readonly number[];
  readonly beats: readonly Beat[];
}

/** The full-solution shading (row-major booleans) for a demo script. */
export function demoShading(script: DemoScript): boolean[] {
  const shading = new Array<boolean>(script.board.rows * script.board.cols).fill(false);
  for (const index of script.solutionBreaks) shading[index] = true;
  return shading;
}
</content>
