/**
 * Audio interface STUB (brief non-goal: no sound assets in WS-04). One
 * sample per verb per design-tokens.json `sound.verbs`; off by default
 * until first solve, then a one-time opt-in toast (token $comment). A real
 * player and its wiring land with app chrome (WS-09+).
 */
import { designTokens } from './tokens';

/** Mirrors design-tokens.json sound.verbs (parity-tested in tokens.test.ts). */
export type SoundVerb = 'shade' | 'dot' | 'unmark' | 'replayTick' | 'contained';

export interface SoundPlayer {
  play(verb: SoundVerb): void;
}

/** The default player until sound ships: does nothing. */
export const silentPlayer: SoundPlayer = {
  play: () => undefined,
};

/** The verb list as data (for the parity test and future preloading). */
export const soundVerbs: readonly string[] = designTokens.sound.verbs;
