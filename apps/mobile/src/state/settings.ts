import { Appearance } from 'react-native';

import { createStore, useStore } from './store';

export type AppearanceChoice = 'auto' | 'light' | 'dark';

export type Settings = {
  /** Off by default: no hurry. The time is still shown on the result. */
  showClock: boolean;
  haptics: boolean;
  /** Soft sound effects; they follow the silent switch. */
  sounds: boolean;
  /** Ring a number as soon as it can no longer burn on time. */
  flagLate: boolean;
  appearance: AppearanceChoice;
  reminder: boolean;
  /** Minutes after midnight. */
  reminderAt: number;
  seenWelcome: boolean;
};

export const settingsStore = createStore<Settings>('settings', {
  showClock: false,
  haptics: true,
  sounds: true,
  flagLate: true,
  appearance: 'auto',
  reminder: false,
  reminderAt: 8 * 60,
  seenWelcome: false,
});

export const useSettings = () => useStore(settingsStore);

export function updateSettings(patch: Partial<Settings>) {
  settingsStore.set((s) => ({ ...s, ...patch }));
  if (patch.appearance) applyAppearance(patch.appearance);
}

export function applyAppearance(choice: AppearanceChoice = settingsStore.get().appearance) {
  // Not every platform can override the scheme (web cannot); the device setting is used there.
  if (typeof Appearance.setColorScheme === 'function') {
    Appearance.setColorScheme(choice === 'auto' ? 'unspecified' : choice);
  }
}
