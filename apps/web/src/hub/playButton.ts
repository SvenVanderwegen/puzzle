/**
 * The big Play button decision table (product §3) — all five states:
 *
 * | State                                   | Label                          | Target |
 * |-----------------------------------------|--------------------------------|--------|
 * | 1. first visit (no First Shift flag)    | hub.play.first                 | /daily |
 * | 2. daily unstarted                      | hub.play.daily (streak-holders |        |
 * |                                         | hub.play.daily.streak, Day n)  | /daily |
 * | 3. daily in progress                    | hub.play.resume + elapsed      | /daily |
 * | 4. daily contained                      | hub.play.endless + rec. tier   | /play  |
 * | 5. daily contained + endless mid-solve  | hub.play.resumeEndless         | /play  |
 */
import { formatElapsed, utcDayBefore } from '../state/clock';
import type { LocalState, Tier } from '../state/localState';
import type { IcuParams, StringKey } from '../strings';
import { recommendedTier, tierLabel } from './tiers';

export type PlayDecisionKind =
  'first-shift' | 'daily-unstarted' | 'daily-resume' | 'endless-recommended' | 'endless-resume';

export interface PlayDecision {
  readonly kind: PlayDecisionKind;
  readonly labelKey: StringKey;
  readonly labelParams: IcuParams;
  readonly to: '/daily' | '/play';
  /** Present when the target is endless at a recommended tier. */
  readonly tier?: Tier;
}

/** True when yesterday's daily was contained, so today extends the run. */
function streakAlive(state: LocalState, todayUtc: string): boolean {
  return state.streak.current > 0 && state.streak.lastDailyDate === utcDayBefore(todayUtc);
}

export function decidePlayButton(state: LocalState, todayUtc: string): PlayDecision {
  if (!state.firstShiftDone) {
    return { kind: 'first-shift', labelKey: 'hub.play.first', labelParams: {}, to: '/daily' };
  }

  const daily = state.daily?.date === todayUtc ? state.daily : null;

  if (daily?.status === 'in_progress') {
    return {
      kind: 'daily-resume',
      labelKey: 'hub.play.resume',
      labelParams: { elapsed: formatElapsed(daily.elapsedMs) },
      to: '/daily',
    };
  }

  if (daily?.status === 'contained') {
    if (state.endless.inProgress) {
      return {
        kind: 'endless-resume',
        labelKey: 'hub.play.resumeEndless',
        labelParams: {},
        to: '/play',
        tier: state.endless.tier,
      };
    }
    const tier = recommendedTier(state.record.rating);
    return {
      kind: 'endless-recommended',
      labelKey: 'hub.play.endless',
      labelParams: { tier: tierLabel(tier) },
      to: '/play',
      tier,
    };
  }

  if (streakAlive(state, todayUtc)) {
    return {
      kind: 'daily-unstarted',
      labelKey: 'hub.play.daily.streak',
      labelParams: { n: state.streak.current + 1 },
      to: '/daily',
    };
  }
  return { kind: 'daily-unstarted', labelKey: 'hub.play.daily', labelParams: {}, to: '/daily' };
}
