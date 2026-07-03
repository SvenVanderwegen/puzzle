/**
 * The big Play button decision table (product §3) — every row exercised
 * state-by-state against the local store (brief acceptance criterion).
 */
import { describe, expect, it } from 'vitest';
import { defaultLocalState, type LocalState } from '../state/localState';
import { decidePlayButton } from './playButton';
import { t } from '../strings';

const TODAY = '2026-07-03';

const base: LocalState = { ...defaultLocalState(), firstShiftDone: true };

describe('play button decision table', () => {
  it('state 1 — first visit ever: Play — First Shift', () => {
    const decision = decidePlayButton(defaultLocalState(), TODAY);
    expect(decision.kind).toBe('first-shift');
    expect(decision.to).toBe('/daily');
    expect(t(decision.labelKey, decision.labelParams)).toBe('Play — First Shift');
  });

  it("state 2 — daily unstarted: Play today's Burn Order", () => {
    const decision = decidePlayButton(base, TODAY);
    expect(decision.kind).toBe('daily-unstarted');
    expect(decision.to).toBe('/daily');
    expect(t(decision.labelKey, decision.labelParams)).toBe("Play today's Burn Order");
  });

  it('state 2 (streak-holder variant) — Day n label when yesterday was contained', () => {
    const state: LocalState = {
      ...base,
      streak: { current: 12, best: 12, lastDailyDate: '2026-07-02' },
    };
    const decision = decidePlayButton(state, TODAY);
    expect(decision.kind).toBe('daily-unstarted');
    expect(t(decision.labelKey, decision.labelParams)).toBe("Day 13 — today's Burn Order");
  });

  it('state 2 — a broken streak gets the plain label', () => {
    const state: LocalState = {
      ...base,
      streak: { current: 12, best: 12, lastDailyDate: '2026-06-30' },
    };
    expect(t(decidePlayButton(state, TODAY).labelKey, {})).toBe("Play today's Burn Order");
  });

  it('state 3 — daily in progress: Resume with elapsed time', () => {
    const state: LocalState = {
      ...base,
      daily: { date: TODAY, status: 'in_progress', elapsedMs: 161_000 },
    };
    const decision = decidePlayButton(state, TODAY);
    expect(decision.kind).toBe('daily-resume');
    expect(decision.to).toBe('/daily');
    expect(t(decision.labelKey, decision.labelParams)).toBe('Resume — 2:41 elapsed');
  });

  it('state 4 — daily contained: Keep burning at the rating-recommended tier', () => {
    const state: LocalState = {
      ...base,
      daily: { date: TODAY, status: 'contained', elapsedMs: 291_000, containedMs: 291_000 },
      record: { ...base.record, rating: 1200 },
    };
    const decision = decidePlayButton(state, TODAY);
    expect(decision.kind).toBe('endless-recommended');
    expect(decision.to).toBe('/play');
    expect(decision.tier).toBe('crew');
    expect(t(decision.labelKey, decision.labelParams)).toBe('Keep burning · Crew 6×6');
  });

  it('state 4 — the recommendation follows the rating bands', () => {
    const contained: LocalState = {
      ...base,
      daily: { date: TODAY, status: 'contained', elapsedMs: 291_000 },
    };
    const low = { ...contained, record: { ...contained.record, rating: 1000 } };
    const high = { ...contained, record: { ...contained.record, rating: 1500 } };
    expect(decidePlayButton(low, TODAY).tier).toBe('lookout');
    expect(decidePlayButton(high, TODAY).tier).toBe('hotshot');
  });

  it('state 5 — daily contained + endless mid-solve: Resume Endless', () => {
    const state: LocalState = {
      ...base,
      daily: { date: TODAY, status: 'contained', elapsedMs: 291_000 },
      endless: { ...base.endless, tier: 'hotshot', inProgress: true },
    };
    const decision = decidePlayButton(state, TODAY);
    expect(decision.kind).toBe('endless-resume');
    expect(decision.to).toBe('/play');
    expect(decision.tier).toBe('hotshot');
    expect(t(decision.labelKey, decision.labelParams)).toBe('Resume Endless');
  });

  it("yesterday's leftover progress does not shadow today's fresh daily", () => {
    const stale: LocalState = {
      ...base,
      daily: { date: '2026-07-02', status: 'in_progress', elapsedMs: 30_000 },
    };
    expect(decidePlayButton(stale, TODAY).kind).toBe('daily-unstarted');

    const containedYesterday: LocalState = {
      ...base,
      daily: { date: '2026-07-02', status: 'contained', elapsedMs: 30_000 },
    };
    expect(decidePlayButton(containedYesterday, TODAY).kind).toBe('daily-unstarted');
  });

  it('an endless board mid-solve without a contained daily still points at the daily', () => {
    const state: LocalState = {
      ...base,
      endless: { ...base.endless, inProgress: true },
    };
    expect(decidePlayButton(state, TODAY).kind).toBe('daily-unstarted');
  });
});
