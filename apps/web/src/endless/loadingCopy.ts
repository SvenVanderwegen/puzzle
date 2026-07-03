/**
 * Rotating fairness loading copy (product §3: "the fairness guarantee
 * doubles as the loading entertainment"). Key 1 shows immediately; the
 * rotation steps through 2..4 and loops while generation is pending.
 */
import { useEffect, useState } from 'react';
import type { StringKey } from '../strings';

export const LOADING_KEYS: readonly StringKey[] = [
  'play.loading.endless.1',
  'play.loading.endless.2',
  'play.loading.endless.3',
  'play.loading.endless.4',
];

export const LOADING_ROTATE_MS = 1200;

export function useLoadingCopy(active: boolean): StringKey {
  const [step, setStep] = useState(0);
  useEffect(() => {
    if (!active) {
      setStep(0);
      return;
    }
    const id = window.setInterval(() => {
      setStep((current) => current + 1);
    }, LOADING_ROTATE_MS);
    return () => {
      window.clearInterval(id);
    };
  }, [active]);
  return LOADING_KEYS[step % LOADING_KEYS.length] ?? 'play.loading.endless.1';
}
