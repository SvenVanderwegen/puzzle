# WS-12 — Academy (7 lessons) · STATUS

The builder session was killed by the shared session limit + a container restart
mid-flight. The lead recovered the branch to a compiling, lint/format-clean
state during the subagent blackout, but did NOT finish it — the test suite is
the builder's remaining work (design context needed). NOT merged.

## Done (builder, then lead recovery)
- Full academy feature scaffolding committed: `apps/web/src/academy/**` —
  LessonPlayer, BeatPlayer (beat engine), DemoGrid, PracticeBoard, beats,
  boards, demos, lessons (7), packSync (mode=pack sync seam), progress,
  reducedMotion, routes, events, deps; hub wiring (HubPage, playButton for the
  First Shift funnel); AcademyPage; ~136 lines of proposed lesson copy in
  strings/proposed.ts (StringKey widened).
- **Lead recovery (this session):**
  - Stripped a stray `</content>` write-artifact trailer from 17 source files
    plus this STATUS file (the "every file errors at its final line" symptom
    the builder flagged).
  - Fixed 3 `noUncheckedIndexedAccess` type errors in LessonPlayer
    (`lesson.practice[practiceIndex]` guards).
  - Prettier-formatted the academy dir.
  - Result: `typecheck` OK, `lint` OK, `format:check` OK.

## Remaining (builder, on resume)
1. **Reconcile 3 pre-existing tests** that now fail because the stubs became
   real (mechanical, but needs the intended behavior):
   - `src/hub/playButton.test.ts` "state 1 — first visit ever" — the First
     Shift funnel changed first-visit routing.
   - `src/app.test.tsx` "state 1 — first visit renders First Shift".
   - `src/app.test.tsx` "/academy and /academy/$slug render the academy stubs"
     — the stubs are gone; assert the real academy instead.
2. **Write the academy test suite (brief acceptance — currently ZERO tests):**
   - **Practice-board tag assertion** (brief acceptance): load the fixture pack
     (pipeline/tests/fixtures/content-sample/v20260706-1/packs/academy-1.json),
     assert each lesson's practice boards carry the lesson's technique tag
     (L2 crtf, L3/4/5 cuit, L6 abp; L1/L7 untagged — assert as shipped).
   - Beat engine + reduced-motion stepper (every animated beat has a
     no-autoplay stepper variant).
   - Progress: completion survives reload; 7/7 -> Certified badge; First Shift
     -> firstShiftDone mirrored to LocalState.
   - packSync: signed-in pack-solve submission at the api-client seam (mock
     transport, WS-11 pattern); guest = no network.
   - Verify budget:check stays <=200KB with the lazy academy chunk.
3. Full gates, then STATUS ## Done with SHAs.

## Blockers
- None (was blocked on the session-limit reset for subagent resume — 15:40 UTC).

## Decisions made (builder — lead to audit at integration)
- ~68 proposed lesson-copy keys (the project's largest copy batch) in
  proposed.ts — lead moves to COPY.md by ADR (ADR-0017/0023/0026/0027 pattern)
  and audits the dispatcher voice.
- First Shift completion routes to `/daily/{-$date}` (WS-10 daily, now merged —
  the handoff target is live).

## Files touched
- apps/web/src/academy/** (new), apps/web/src/hub/{HubPage,playButton}.tsx,
  apps/web/src/routes/AcademyPage.tsx, apps/web/src/strings/{proposed,index}.ts,
  tasks/WS-12/STATUS.md.

## Resume instructions
Branch compiles clean (typecheck/lint/format OK). Do Remaining #1 then #2 then
#3. The daily handoff target (/daily) is now merged, so the First Shift funnel
e2e seam is real. Commit often. Do NOT touch api/resources/landing/hero.js
(budget:landing is lead-owned and expected to fail on the copy-catalog change).
