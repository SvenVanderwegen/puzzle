/**
 * The big Play button decision table (product §3) — all five states:
 *
 * | State                                   | Label                          | Target   |
 * |-----------------------------------------|--------------------------------|----------|
 * | 1. first visit (no First Shift flag)    | hub.play.first                 | /academy |
 * | 2. daily unstarted                      | hub.play.daily (streak-holders |          |
 * |                                         | hub.play.daily.streak, Day n)  | /daily   |
 * | 3. daily in progress                    | hub.play.resume + elapsed      | /daily   |
 * | 4. daily contained                      | hub.play.endless + rec. tier   | /play    |
 * | 5. daily contained + endless mid-solve  | hub.play.resumeEndless         | /play    |
 *
 * State 1 is the funnel entry (WS-12): first-visit Play routes into the First
 * Shift lesson, which flows directly into today's daily on completion. The slug
 * is the literal First Shift route (academy/lessons FIRST_SHIFT_SLUG) — kept
 * inline so the eager hub never imports the lazy academy chunk.
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
  readonly to: '/daily' | '/play' | '/academy';
  /** Present when the target is endless at a recommended tier. */
  readonly tier?: Tier;
  /** The lesson slug when the target is the academy (first-shift funnel). */
  readonly slug?: string;
}

/** True when yesterday's daily was contained, so today extends the run. */
function streakAlive(state: LocalState, todayUtc: string): boolean {
  return state.streak.current > 0 && state.streak.lastDailyDate === utcDayBefore(todayUtc);
}

export function decidePlayButton(state: LocalState, todayUtc: string): PlayDecision {
  if (!state.firstShiftDone) {
    return {
      kind: 'first-shift',
      labelKey: 'hub.play.first',
      labelParams: {},
      to: '/academy',
      slug: 'first-shift',
    };
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
