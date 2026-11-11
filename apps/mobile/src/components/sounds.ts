/**
 * Sound effects. The same short, soft sounds as the website, made by
 * scripts/make-sounds.mjs in the repository root (our own, no licence
 * needed). Change a sound there, never by hand.
 *
 * Sounds follow the iPhone's silent switch and never stop the player's own
 * music or podcast: they mix in quietly. The "Sounds" switch on the You tab
 * turns them off altogether.
 */
import { createAudioPlayer, setAudioModeAsync, type AudioPlayer } from 'expo-audio';

import { settingsStore } from '@/state/settings';

const SOURCES = {
  place: require('../../assets/sounds/place.wav'),
  dot: require('../../assets/sounds/dot.wav'),
  clear: require('../../assets/sounds/clear.wav'),
  hint: require('../../assets/sounds/hint.wav'),
  wrong: require('../../assets/sounds/wrong.wav'),
  burn: require('../../assets/sounds/burn.wav'),
  solved: require('../../assets/sounds/solved.wav'),
} as const;

export type Sound = keyof typeof SOURCES;

const VOLUME = 0.7;
let players: Partial<Record<Sound, AudioPlayer>> | null = null;

function ready() {
  if (players) return players;
  players = {};
  setAudioModeAsync({ playsInSilentMode: false, interruptionMode: 'mixWithOthers' }).catch(() => {});
  for (const name of Object.keys(SOURCES) as Sound[]) {
    try {
      const player = createAudioPlayer(SOURCES[name]);
      player.volume = VOLUME;
      players[name] = player;
    } catch {
      // This one sound stays quiet.
    }
  }
  return players;
}

/** Load the sounds ahead of the first move, so that move already has its sound. */
export function preloadSounds() {
  if (settingsStore.get().sounds) ready();
}

export function playSound(name: Sound) {
  if (!settingsStore.get().sounds) return;
  const player = ready()[name];
  if (!player) return;
  try {
    player.seekTo(0).catch(() => {});
    player.play();
  } catch {
    // Quiet on failure; the game goes on.
  }
}

let later: ReturnType<typeof setTimeout> | null = null;

/** The fire runs through the board, then a warm chord once it has burnt out. */
export function playContained(burnMs: number) {
  playSound('burn');
  if (later) clearTimeout(later);
  later = setTimeout(() => playSound('solved'), Math.max(400, burnMs));
}
