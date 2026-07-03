/**
 * @burnfront/ui-web — React components binding @burnfront/game-core (WS-04).
 * Colors/motion/type only via contracts/design-tokens.json (tokens.ts);
 * user-facing strings only via typed `strings` props (COPY.md keys) until
 * the WS-09 keyed-strings module lands.
 */
export { silentPlayer, soundVerbs } from './audio';
export type { SoundPlayer, SoundVerb } from './audio';
export { Board, LONG_PRESS_MS } from './Board';
export type { BoardProps } from './Board';
export { BurnReplay } from './BurnReplay';
export type { BurnReplayProps } from './BurnReplay';
export { BreaksCounter, CluePill, MinuteCounter } from './hud';
export type { BreaksCounterProps, CluePillProps, MinuteCounterProps } from './hud';
export { cellName, formatString } from './strings';
export type {
  BoardStringKey,
  HudStringKey,
  ReplayLabels,
  ReplayStringKey,
  StringsFor,
} from './strings';
export { BurnfrontStyles, uiWebCss } from './styles';
export { burnColor, cssVariables, designTokens, motion, tokensCssText } from './tokens';
