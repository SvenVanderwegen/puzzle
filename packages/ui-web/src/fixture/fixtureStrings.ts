/**
 * Fixture-page strings, copied VERBATIM from contracts/COPY.md and passed
 * through the components' typed `strings` props. The WS-09 keyed-strings
 * module replaces this file's role in apps/web; the labels object covers
 * control copy that has no COPY key yet (proposed keys in strings.ts).
 */
import type {
  BoardStringKey,
  HudStringKey,
  ReplayLabels,
  ReplayStringKey,
  StringsFor,
} from '../strings';

export const boardStrings: StringsFor<BoardStringKey> = {
  'a11y.cell.empty': '{cell}, empty',
  'a11y.cell.break': '{cell}, firebreak',
  'a11y.cell.dot': '{cell}, marked clear',
  'a11y.cell.clue': '{cell}, clue: burns at minute {m}',
  'a11y.cell.spark': '{cell}, the spark',
};

export const replayStrings: StringsFor<ReplayStringKey> = {
  'a11y.replay.minute': 'Minute {t}: {count} cells burning.',
  'a11y.contained': 'Contained. {time}.',
  'play.contained': 'CONTAINED',
};

export const hudStrings: StringsFor<HudStringKey> = {
  'play.breaks': 'Breaks {placed}/{n}',
};

/** No COPY.md keys yet — dispatcher voice, flagged in STATUS.md. */
export const replayLabels: ReplayLabels = {
  watchAgain: 'Watch the burn again',
  nextMinute: 'Next minute',
  previousMinute: 'Previous minute',
};

/** Proposed `a11y.board` — accessible name for the play grid. */
export const boardLabel = 'Terrain';
