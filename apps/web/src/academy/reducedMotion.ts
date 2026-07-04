/**
 * Reduced-motion resolution for the Academy: the OS prefers-reduced-motion
 * media query OR the WS-14 device preference (settings.reducedMotion) — either
 * one swaps every animated beat for its stepper variant. The app pref is read
 * live from the runtime store so a settings toggle takes effect immediately.
 */
import { useLocalState } from '../state/runtime';

export function prefersReducedMotion(): boolean {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false;
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

export function useReducedMotion(): boolean {
  const prefs = useLocalState().prefs;
  return prefs.reducedMotion || prefersReducedMotion();
}
