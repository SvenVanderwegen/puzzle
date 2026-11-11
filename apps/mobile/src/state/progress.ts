/**
 * What the player has done, kept on the phone: daily results, campaign
 * results and a log of every contained fire (for the streak, the weekly
 * goal and the level).
 */
import { FIRES_PER_LEVEL, WEEKLY_GOAL } from '@/config';
import { CAMPAIGN_SIZE, daysBetween, localDate, type Fire } from '@/engine/puzzles';

import { createStore, useStore } from './store';

export type Result = { ms: number; hints: number };

export type Progress = {
  daily: Record<string, Result>;
  campaign: Record<string, Result>;
  /** Local date of every contained fire, any mode. */
  log: string[];
  bestMs: number | null;
};

export const progressStore = createStore<Progress>('progress', {
  daily: {},
  campaign: {},
  log: [],
  bestMs: null,
});

export const useProgress = () => useStore(progressStore);

export function recordWin(fire: Fire, result: Result) {
  const today = localDate();
  progressStore.set((p) => {
    // Playing today's fire again is fine, but it only counts once.
    if (fire.kind === 'daily' && p.daily[fire.key]) return p;
    const next: Progress = { ...p, log: [...p.log, today] };
    if (fire.kind === 'daily' && !p.daily[fire.key]) next.daily = { ...p.daily, [fire.key]: result };
    if (fire.kind === 'campaign') {
      const prev = p.campaign[fire.key];
      if (!prev || result.ms < prev.ms) next.campaign = { ...p.campaign, [fire.key]: result };
    }
    if (result.hints === 0 && (p.bestMs == null || result.ms < p.bestMs)) next.bestMs = result.ms;
    return next;
  });
}

/** Days in a row with the daily fire contained, ending today (or yesterday). */
export function streak(p: Progress, today = localDate()): number {
  const days = new Set(Object.keys(p.daily));
  let day = today;
  if (!days.has(day)) day = shift(day, -1);
  let n = 0;
  while (days.has(day)) {
    n++;
    day = shift(day, -1);
  }
  return n;
}

function shift(date: string, days: number): string {
  const d = new Date(`${date}T12:00:00`);
  d.setDate(d.getDate() + days);
  return localDate(d);
}

/** Fires contained this week (Monday to Sunday). */
export function thisWeek(p: Progress, today = localDate()): { done: number; goal: number } {
  const d = new Date(`${today}T12:00:00`);
  const monday = shift(today, -((d.getDay() + 6) % 7));
  const done = p.log.filter((day) => daysBetween(monday, day) >= 0 && daysBetween(day, today) >= 0).length;
  return { done: Math.min(done, WEEKLY_GOAL), goal: WEEKLY_GOAL };
}

export function level(p: Progress): { level: number; toGo: number; fraction: number } {
  const total = p.log.length;
  const into = total % FIRES_PER_LEVEL;
  return { level: Math.floor(total / FIRES_PER_LEVEL) + 1, toGo: FIRES_PER_LEVEL - into, fraction: into / FIRES_PER_LEVEL };
}

export function campaignDone(p: Progress): number {
  return Object.keys(p.campaign).length;
}

/** The first campaign fire not contained yet, or null when all are. */
export function nextCampaign(p: Progress): number | null {
  for (let n = 1; n <= CAMPAIGN_SIZE; n++) if (!p.campaign[String(n)]) return n;
  return null;
}

export function isUnlocked(p: Progress, n: number): boolean {
  return n === 1 || !!p.campaign[String(n - 1)] || !!p.campaign[String(n)];
}
