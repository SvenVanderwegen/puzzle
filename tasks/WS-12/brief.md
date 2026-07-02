# WS-12: Academy (7 lessons)

Lane: A · Deps: WS-09, WS-05 academy pack · Sessions: 2

## Scope
Port the prototype's animated walkthrough into the lesson player and build the 7-lesson
course (product §5, 1:1 with the deduction toolkit): First Shift · Too Fast Means Walls ·
Too Slow Means Roads · Chains to the Spark · Nothing Is Spared · Counting the Endgame ·
The Long Way Around (capstone: the 18-two-cells-from-★ board). Each lesson: animated demo
on a fixed board (beat-scripted like the prototype) + 2 practice boards from the academy
pack (unrated). Completion tracked locally + synced for accounts; "Certified" badge state
on the hub lane. Reduced-motion stepper variants. First Shift is also the funnel entry from
the Play button's first-visit state — it must flow directly into today's daily at the end.

## Inputs
WS-09, WS-05 `packs/academy-*.json` fixtures, `contracts/COPY.md` lesson scripts,
`reference/index.html` walkthrough (behavioral reference).

## Outputs
Academy feature, lesson beat scripts, tests.

## Acceptance
- [ ] Each lesson completable via e2e script; completion events recorded
- [ ] Practice boards verified to require the lesson's argument (pipeline tag asserted)
- [ ] Reduced-motion variant for every animated beat
- [ ] First Shift → daily handoff e2e (fresh user path)

## Non-goals
No coach integration (WS-13 consumes lesson concepts, not vice versa), no NL copy.
