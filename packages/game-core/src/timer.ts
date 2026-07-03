/**
 * Session timer. The clock is injected (epoch ms) — game-core never reads
 * Date.now. Supports manual pause/resume plus an auto-pause hook the UI calls
 * on document visibility changes (no DOM access here): auto-pause yields to a
 * manual pause and never resumes over one.
 */
import type { Clock } from './types';

export type TimerState = 'idle' | 'running' | 'paused';

export class SessionTimer {
  private readonly clock: Clock;
  private accumulatedMs: number;
  private runningSince: number | null = null;
  private started = false;
  private manuallyPaused = false;
  private autoPaused = false;

  constructor(clock: Clock, initialElapsedMs = 0) {
    this.clock = clock;
    this.accumulatedMs = Math.max(0, initialElapsedMs);
    this.started = initialElapsedMs > 0;
  }

  get state(): TimerState {
    if (this.runningSince !== null) return 'running';
    return this.started ? 'paused' : 'idle';
  }

  elapsedMs(): number {
    if (this.runningSince === null) return this.accumulatedMs;
    // Clamp: a skewed injected clock must never run the timer backwards.
    return this.accumulatedMs + Math.max(0, this.clock.now() - this.runningSince);
  }

  /** Begin (or resume) timing. Idempotent while running. */
  start(): void {
    if (this.runningSince !== null) return;
    this.started = true;
    this.manuallyPaused = false;
    this.autoPaused = false;
    this.runningSince = this.clock.now();
  }

  /** Manual pause. No-op unless running. */
  pause(): void {
    if (this.runningSince === null) return;
    this.settle();
    this.manuallyPaused = true;
    this.autoPaused = false;
  }

  /** Manual resume. No-op unless paused. */
  resume(): void {
    if (this.state !== 'paused') return;
    this.start();
  }

  /**
   * Auto-pause hook — the UI calls setHidden(true/false) from its
   * document-visibility listener. Hiding pauses a running timer; unhiding
   * resumes only when the pause was ours (never over a manual pause).
   */
  setHidden(hidden: boolean): void {
    if (hidden) {
      if (this.runningSince === null) return;
      this.settle();
      this.autoPaused = true;
      return;
    }
    if (this.autoPaused && !this.manuallyPaused) this.start();
  }

  private settle(): void {
    if (this.runningSince === null) return;
    this.accumulatedMs += Math.max(0, this.clock.now() - this.runningSince);
    this.runningSince = null;
  }
}
