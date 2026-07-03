/**
 * The landing replay strip driver (WS-15, product.md §2.2).
 *
 * The Blade page server-renders a solved 7×7 board in its fully-burnt final
 * state (per-cell burn minute in data-m, per-cell burnRamp color inlined by
 * the server from the committed strip fixture). This module animates it
 * minute-by-minute from that inline data — no video, no fetch:
 *
 * - Animated: loop from minute 0 to the end at the frozen motion-token
 *   pacing (320ms/minute, 180ms past minute 8), hold the finished burn for
 *   a beat, restart. Runs only while the strip is on screen.
 * - prefers-reduced-motion: no timer at all — the strip stays on the final
 *   state and a "Next minute" step button (server-rendered, hidden until
 *   hydration) steps through the minutes manually.
 */
import { motion } from '@burnfront/ui-web';

/** Delay before revealing minute `t` (t >= 1), per the frozen motion tokens. */
export function minuteDelayMs(minute: number): number {
  return minute > motion.replayAccelAfterMinute ? motion.replayMinuteFastMs : motion.replayMinuteMs;
}

/** How long the finished burn holds before the loop restarts. */
export const LOOP_HOLD_MS = 4 * motion.containedBeatMs;

interface StripCell {
  readonly element: HTMLElement;
  readonly minute: number;
}

export interface StripHandle {
  /** Reveal minutes 0..t (t clamped to [0, maxMinute]). */
  readonly setMinute: (minute: number) => void;
  readonly maxMinute: number;
}

function collectCells(root: HTMLElement): StripCell[] {
  const cells: StripCell[] = [];
  for (const element of root.querySelectorAll<HTMLElement>('[data-m]')) {
    const minute = Number(element.dataset.m);
    if (Number.isInteger(minute) && minute >= 0) cells.push({ element, minute });
  }
  return cells;
}

function prefersReducedMotion(): boolean {
  if (typeof window.matchMedia !== 'function') return false;
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Wires one strip root. Expects the WS-15 Blade markup contract:
 * `[data-m]` cells (pre-burnt), `[data-bf-strip-minute]` chip text and a
 * hidden `[data-bf-strip-step]` button. Returns null when the markup is
 * absent (the page then simply keeps its static final frame).
 */
export function initStrip(root: HTMLElement): StripHandle | null {
  const cells = collectCells(root);
  if (cells.length === 0) return null;
  const maxMinute = Math.max(...cells.map((cell) => cell.minute));
  const chip = root.querySelector<HTMLElement>('[data-bf-strip-minute]');
  const step = root.querySelector<HTMLElement>('[data-bf-strip-step]');

  const setMinute = (minute: number): void => {
    const t = Math.max(0, Math.min(maxMinute, minute));
    for (const cell of cells) {
      cell.element.classList.toggle('bf-cell--burn', cell.minute <= t);
    }
    if (chip !== null) chip.textContent = String(t);
  };

  if (prefersReducedMotion()) {
    // Paused: final state stays; the step button walks the minutes.
    let current = maxMinute;
    setMinute(current);
    if (step !== null) {
      step.hidden = false;
      step.addEventListener('click', () => {
        current = current >= maxMinute ? 0 : current + 1;
        setMinute(current);
      });
    }
    return { setMinute, maxMinute };
  }

  let current = 0;
  let timer: number | null = null;
  const tick = (): void => {
    setMinute(current);
    const next = current >= maxMinute ? 0 : current + 1;
    const delay = current >= maxMinute ? LOOP_HOLD_MS : minuteDelayMs(next);
    current = next;
    timer = window.setTimeout(tick, delay);
  };
  const start = (): void => {
    if (timer === null) tick();
  };
  const stop = (): void => {
    if (timer !== null) {
      window.clearTimeout(timer);
      timer = null;
    }
  };

  // Animate only while visible (battery + main-thread hygiene).
  if (typeof IntersectionObserver === 'function') {
    const observer = new IntersectionObserver((entries) => {
      for (const entry of entries) {
        if (entry.isIntersecting) start();
        else stop();
      }
    });
    observer.observe(root);
  } else {
    start();
  }

  return { setMinute, maxMinute };
}
