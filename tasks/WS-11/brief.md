# WS-11: Endless mode

Lane: A · Deps: WS-09 · Sessions: 1

## Scope
`/play`: in-browser generation in a Web Worker (engine), tier dials
(Lookout/Crew/Hotshot), rotating fairness loading copy, pre-generate next board during play
so "next" is instant, cancel/regenerate, local history + per-tier counts, rated submission
for signed-in users (payload includes `endless_spec`; reduced weight per ADR-0006; board
rating shown "This terrain: 1310"), recommended tier from rating.

## Inputs
engine, game-core, WS-09 shell, `contracts/RATING.md`.

## Outputs
Endless feature + worker module + tests.

## Acceptance
- [ ] Main thread never blocked > 50ms during generation (perf test)
- [ ] Worker cancel is race-free (rapid regenerate test)
- [ ] Dials + history persist across reload; anonymous play fully functional
- [ ] Submission validates against OpenAPI schema; unsigned users skip submission cleanly

## Non-goals
No Rush/Duel, no seeds-sharing UI (puzzle codes are phase 2 surface).
