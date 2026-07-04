/**
 * tutorial_step analytics — documented no-op seam (brief §6).
 *
 * WS-19's first-party beacon client was deferred, so there is no events
 * transport in apps/web yet (only the generated api-client type for
 * POST /events + the AnalyticsEvent `tutorial_step` kind exist). The BeatPlayer
 * calls `onStep` as each walkthrough beat advances; the default sink is a
 * no-op. When WS-19 lands its beacon, wire it HERE — through the generated
 * api-client's recordEvents operation, never a hand-written fetch (CLAUDE.md
 * rule 2). Tests inject a spy sink to assert the step sequence fires.
 */
export type TutorialStepSink = (step: number) => void;

/** The default: analytics are dropped until WS-19's beacon exists. */
export const noopTutorialStep: TutorialStepSink = () => {
  /* no transport yet — see module docstring */
};
