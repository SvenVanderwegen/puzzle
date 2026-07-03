/**
 * Injected clock (CLAUDE.md rule 8 discipline, applied to the shell too):
 * UTC day math and the hub countdown never read Date.now() directly, so
 * tests pin time and the same instant always renders the same hub.
 */
export interface Clock {
  /** Epoch milliseconds. */
  now(): number;
}

export const systemClock: Clock = {
  now: () => Date.now(),
};

const DAY_MS = 86_400_000;

/** UTC calendar date (YYYY-MM-DD) for an instant — ADR-0002 day boundaries. */
export function utcDateOf(nowMs: number): string {
  return new Date(nowMs).toISOString().slice(0, 10);
}

/** The UTC date one day before the given YYYY-MM-DD. */
export function utcDayBefore(date: string): string {
  const ms = Date.parse(`${date}T00:00:00Z`);
  return utcDateOf(ms - DAY_MS);
}

/** Milliseconds until the next UTC midnight (never 0; a full day at midnight). */
export function msUntilUtcMidnight(nowMs: number): number {
  const sinceMidnight = nowMs % DAY_MS;
  return sinceMidnight === 0 ? DAY_MS : DAY_MS - sinceMidnight;
}

export interface CountdownParts {
  readonly hh: string;
  readonly mm: string;
  readonly ss: string;
}

const pad = (n: number): string => String(n).padStart(2, '0');

/** hh:mm:ss remaining to the next incident (UTC midnight), zero-padded. */
export function countdownParts(nowMs: number): CountdownParts {
  const remaining = Math.floor(msUntilUtcMidnight(nowMs) / 1000);
  return {
    hh: pad(Math.floor(remaining / 3600)),
    mm: pad(Math.floor((remaining % 3600) / 60)),
    ss: pad(remaining % 60),
  };
}

/** Elapsed solve time as m:ss (h:mm:ss past an hour) — "Resume — 2:41 elapsed". */
export function formatElapsed(ms: number): string {
  const totalSeconds = Math.floor(Math.max(0, ms) / 1000);
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;
  if (hours > 0) return `${String(hours)}:${pad(minutes)}:${pad(seconds)}`;
  return `${String(minutes)}:${pad(seconds)}`;
}
