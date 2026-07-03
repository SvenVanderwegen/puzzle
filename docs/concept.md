# Burnfront — game concept

Working title of the puzzle prototyped in `reference/` (formerly called
"Firebreak" in that build). This document renames the project, gives it a
world to live in, and shows exactly where that world touches the mechanic
that already exists — nothing here changes `reference/firebreak.py` or
`reference/index.html`, which stay frozen as the ground truth other
implementations must match.

## Logline

A wildfire has already burned out. All you have is a scatter of timestamps —
the minute the fire reached a handful of points. Reconstruct exactly where
the containment lines were dug. There is one report that fits the evidence,
and only pure deduction is allowed to find it.

## Why "Burnfront," not "Firebreak"

The old name pointed at the *answer* — the shaded cells you're solving for.
"Burnfront" points at the *thing you're actually modeling*: the leading edge
of the fire, expanding one ring per minute from the spark. That's the object
the solver reasons about at every step — the wavefront the rules already
describe ("neighboring burnt cells differ by at most 1," "a cell burning at
minute t caught it from a neighbor that burned at t−1"). The firebreaks are
just the negative space that bent that front into the shape the report
recorded. Renaming it centers the fire, not the tool used against it — a
more active, more legible identity for the same puzzle.

It also reads as a proper noun for free: **Burnfront** is both the fire
phenomenon in-world and the name of the unit whose job is reconstructing it
(see below). Same word, two meanings, no extra branding to invent.

## Premise

Somewhere with real fire seasons — dry chaparral hills, wind-funneled
canyons, the kind of terrain that produces a new "complex" fire every
summer — every burn gets an official incident report once it's contained.
Full telemetry is expensive, so the report is never a map: it's a short list
of *sensor pings and structure logs*, each one a cell and the exact minute
the fire reached it. Everything else — where the crews actually cut their
lines — has to be reconstructed after the fact, because:

- **Liability and insurance** turn on whether the line was where the crew
  claimed, or whether it was moved, abandoned, or never dug at all.
- **Next season's planning** depends on knowing which lines actually held
  the fire back, and which just happened to sit in unburned ground.
- **Investigators can't take a crew's word for it.** A report is only
  accepted if the evidence *forces* a unique reconstruction — no version
  that also fits the timestamps gets waved through.

You are a **Burnfront analyst**, working the incident desk for the body that
signs off on these reconstructions — call it the **Line Verification
Unit**. Every case you close either confirms a crew's account or overturns
it.

## Rules of evidence = the puzzle's real invariants

This is the part worth calling out explicitly, because the game doesn't need
to invent new mechanics to sell the premise — the generator's existing
guarantees *are* the in-world standard of proof, unchanged:

| In-world rule | Mechanic already in `firebreak.py` |
|---|---|
| A reconstruction is only accepted if it's the *only* account consistent with the sensor log. | `count_solutions` — the generated puzzle has exactly one solution. |
| An analyst may never have to guess; every line must be provable step by step or the case gets kicked back. | `deduction_solve` — solvable by single-cell forced moves, no search. |
| A line only counts as "on the record" if the log itself proves it had to be there. | `breaks_witnessed` — every shaded cell, if opened, would contradict some clue. No break survives on the cell-count alone. |

That table is the pitch, essentially: the fiction of "defensible evidence"
and the math of "unique, deducible, witnessed" are the same requirement
stated twice. Nothing about the solver needs to change to support the lore;
the lore exists to explain *why* those particular guarantees matter.

## The difficulty ladder

The existing tiers already borrow real wildland-fire crew ranks — keep that
scheme and let it grow with board size instead of introducing new vocabulary
later:

| Tier | Grid | In-world read |
|---|---|---|
| Lookout | 5×5 | A single spotter's log — sparse, small incident. |
| Crew | 6×6 | A hand crew's sector report. |
| Hotshot | 7×7 | An elite crew's report on a fire that got away from the first line. |
| *(future)* Division Supervisor | 8×8+ | A multi-crew complex fire — several sectors' logs reconciled into one report. |
| *(future)* Cold Case | any size, fewer clues, no time limit | An old, disputed fire pulled back out of the archive for a re-review. |

## Naming individual fires

Every generated board is a distinct incident. Rather than "New fire," give
each seed a procedurally-picked designation in the style real fires get —
`<place-word> Fire` or `<place-word> Complex` for multi-day burns — plus a
one-line cause/conditions blurb pulled from a small template set ("Red flag
wind warning, ignition unconfirmed." / "Lightning strike, contained on day
3."). This is pure flavor text over the existing generator output — it
doesn't touch board generation, just labels the result. Good candidate for a
small static word list rather than anything procedural-narrative-heavy.

## Tone and visual language

The current UI copy ("Incident report · deduction puzzle", the burnt-orange
spark mark, the case-file framing of the banner) already fits this without
changes — it reads like a redacted field report, not a game screen. Lean
further into that register for any new copy: dispatch-log terseness,
minute-by-minute timestamps, no exclamation marks. Palette stays grounded —
char black, ember orange, ash gray — nothing fantastical; the fire is
physics, not a monster.

## Campaign hook (future, optional)

A light meta-layer once there's a career to progress through: analysts build
a case record across incidents, and a late-game "capstone" case reopens a
notorious old fire — one where the original crew's line was publicly
disputed — using everything the player has learned. This is a progression
skin on top of the same puzzle generator (bigger boards, sparser clues,
maybe a "Cold Case" mode above), not a new mechanic, and should stay opt-in
so the core solver experience is never gated behind it.

## What this doc does and doesn't do

- Renames the project's public identity to **Burnfront** and gives the
  rename a reason.
- Gives the existing mechanic (grid, spark, BFS burn times, shaded
  firebreaks, unique/deducible/witnessed generation) a world that explains
  *why* those specific guarantees exist, without altering any of them.
- Does not modify `reference/firebreak.py` or `reference/index.html` — both
  stay frozen. Any copy changes implied above (title, tier names, fire
  designations, banner text) are proposals for the next non-frozen build,
  not edits to the reference.
