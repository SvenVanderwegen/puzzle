/**
 * Fire names, from the same place words as app/Support/Burnfront/IncidentNamer.php.
 * Place names are proper nouns and stay as they are in every language; the
 * word "Fire" around them is translated (see the `fire.name` string).
 */
import type { Random } from './random.ts';

export const PLACE_WORDS = [
  'Coldwater', 'Deadhorse', 'Widow Creek', 'Six Mile', 'Chalk Bluff',
  'Blackrock', 'Dry Wash', 'Tinder Ridge', 'Salt Fork', 'Hollow Point',
  'Cinder Pass', 'Ashfall', 'Rimrock', 'Split Timber', 'Quail Canyon',
  'Bitter Springs', 'Redshale', 'Windrow', 'Stonebreak', 'Ember Flat',
];

export function pickPlace(rnd: Random): string {
  return PLACE_WORDS[Math.floor(rnd() * PLACE_WORDS.length)];
}
