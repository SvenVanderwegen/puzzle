import * as Haptics from 'expo-haptics';

import { playContained, playSound } from '@/components/sounds';
import type { MoveOutcome } from '@/state/session';
import { settingsStore } from '@/state/settings';

const SOUND: Record<Exclude<MoveOutcome, 'none' | 'solved'>, 'place' | 'dot' | 'clear' | 'wrong'> = {
  placed: 'place',
  dotted: 'dot',
  cleared: 'clear',
  full: 'wrong',
};

/**
 * What a move feels and sounds like: one light tick and a soft tap per move,
 * a warning and a low "hmm" when the supply is used up, a cheer and the fire
 * running through the board when it is contained (burnMs: how long that
 * replay takes). A hint that places a firebreak chimes instead of tapping.
 */
export function feel(outcome: MoveOutcome, { burnMs = 900, hint = false }: { burnMs?: number; hint?: boolean } = {}) {
  if (outcome === 'none') return;
  if (outcome === 'solved') playContained(burnMs);
  else playSound(hint && outcome !== 'full' ? 'hint' : SOUND[outcome]);
  if (!settingsStore.get().haptics) return;
  if (outcome === 'solved') Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
  else if (outcome === 'full') Haptics.notificationAsync(Haptics.NotificationFeedbackType.Warning);
  else if (outcome === 'placed') Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Medium);
  else Haptics.selectionAsync();
}

export function tick() {
  if (settingsStore.get().haptics) Haptics.selectionAsync();
}
