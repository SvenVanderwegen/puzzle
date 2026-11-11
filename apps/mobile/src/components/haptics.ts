import * as Haptics from 'expo-haptics';

import type { MoveOutcome } from '@/state/session';
import { settingsStore } from '@/state/settings';

/** One light tick per move, a warning when the supply is used up, a cheer when contained. */
export function feel(outcome: MoveOutcome) {
  if (!settingsStore.get().haptics || outcome === 'none') return;
  if (outcome === 'solved') Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
  else if (outcome === 'full') Haptics.notificationAsync(Haptics.NotificationFeedbackType.Warning);
  else if (outcome === 'placed') Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Medium);
  else Haptics.selectionAsync();
}

export function tick() {
  if (settingsStore.get().haptics) Haptics.selectionAsync();
}
