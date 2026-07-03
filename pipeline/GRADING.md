# GRADING.md — grading v2 thresholds and measured distributions (WS-05)

Grade = **(rule tier, chain length)**. RATING.md §2 consumes
`grade_score` = chain length unchanged (`board_rating = base(tier) + 4 x
grade_score`); the player-facing tier enum (lookout/crew/hotshot) is frozen
in `contracts/schemas/puzzle.v1.json` and derives from board size (§4).

All numbers below are measured over **200 generated boards per profile**
(seeds 0..199), reproducible with:

```sh
.venv/bin/python -m burnfront_pipeline.cli measure --profile <name> \
    --count 200 --jobs 4 --out <name>.jsonl
```

## 1. Rule tiers

A single-cell deduction is available at a tier only when the violated
assumption's certified reason kind is in the tier's rule set (kinds and the
frozen check order: `contracts/vectors/README.md`).

| Tier | Rules | Reading |
|---|---|---|
| A | count-fills only (`all_breaks_placed`, `rest_must_be_breaks`) | pure counting |
| B | A + `clue_reached_too_fast` singletons | "too fast means walls" |
| C | full singleton feasibility = the reference solver | adds "too slow means roads" corridor logic |

The rule tier of a board is the lowest tier whose solver finishes it.
Notes pinned by construction (and asserted in tests):

- Chain length is **tier-invariant**: every solving tier assigns each
  initially-unknown cell exactly once, so `score = rows*cols - 1 -
  n_clues`. The score keys on clue sparsity; the tier keys on the kind of
  reasoning required.
- The mid-scan counting singletons (`too_many_breaks`,
  `not_enough_breaks_left`) belong to tier C by the definition above, but
  they never *gate* solvability: whenever one would fire, the equivalent
  count-fill fires at the top of the next pass. Tier A/B solvability is
  therefore unaffected by their absence from the A/B rule sets.
- Tier A only solves degenerate over-clued boards (every non-clue cell a
  break); no generated board in any 200-board run graded A.

## 2. Measured distributions (200 boards per profile)

score = chain length; detour = max over final clues of
(minute − Manhattan distance from spark).

| Profile | rule tier | score min/p10/p25/med/p75/p90/max | detour ≥8 / ≥12 |
|---|---|---|---|
| 5x5-minimal (N=4) | B 1.5% · C 98.5% | 15/18/19/20/21/21/22 | 34% / 0.5% |
| 5x5-floor10 (N=4, redundant) | B 14% · C 86% | score fixed at 14 | 35.5% / 0.5% |
| 6x6-minimal (N=8) | C 100% | 20/25/26/27/28/29/31 | 76.5% / 38% |
| 7x7-minimal (N=12) | «7X7_TIERS» | «7X7_SCORES» | «7X7_DETOUR» |
| 8x8-floor28 (N=16, redundant) | «8X8_TIERS» | score fixed at 35 (floor always reached: «8X8_FLOOR») | «8X8_DETOUR» |

Reason-kind presence per board (percent of boards whose certified steps
contain the kind at least once):

| Kind | 5x5-min | 5x5-fl10 | 6x6-min | 7x7-min | 8x8-fl28 |
|---|---|---|---|---|---|
| clue_reached_too_fast | 100 | 100 | 100 | «K7A» | «K8A» |
| clue_unreachable_in_time | 98.5 | 91 | 100 | «K7B» | «K8B» |
| too_many_breaks | 96 | 90 | 94 | «K7C» | «K8C» |
| all_breaks_placed | 97 | 95 | 94 | «K7D» | «K8D» |
| open_cell_unreachable | 0 | 0 | 0 | «K7E» | «K8E» |
| not_enough_breaks_left / rest_must_be_breaks | 0 | 0 | 0 | «K7F» | «K8F» |

`open_cell_unreachable` never occurs in generated content (same finding as
the frozen vector set): the feasibility check tests
`clue_unreachable_in_time` first, so a sealed region almost always contains
or starves a clue before the pure-pocket check is reached. Consequences for
the academy in §5.

## 3. Redundant-clue floors

The generator's greedy clue removal can stop at a floor (`min_clues`);
every intermediate clue set already satisfies unique + deducible +
witnessed, so stopping early is always safe (asserted in tests).

- **Mon** `min_clues=10` on 5x5: score fixed at 14 (floor reached in
  200/200 runs), tier B share rises 1.5% -> 14% — the redundancy is what
  makes gentle Mondays *findable*.
- **Sun** `min_clues=28` on 8x8: keeps the exact-count uniqueness oracle in
  cheap territory (near-minimal 8x8 boards can hit the 60k node budget for
  minutes per removal trial; floor-28 generation median is seconds).

## 4. Weekly bands (product §5 table -> acceptance predicates)

Player tier from size: 5x5 lookout · 6x6 crew · 7x7 hotshot · 8x8 crew
(Sunday Burn is "big and satisfying, not the hardest").

| Day | Board | Generation | Acceptance predicate | Measured acceptance |
|---|---|---|---|---|
| Mon | Lookout 5x5, redundant | N=4, floor 10 | tier in {A,B} and score <= 14 | 28/200 (14%) |
| Tue | Lookout 5x5, minimal | N=4 | score >= 19 | 169/200 (84.5%) |
| Wed | Crew 6x6 | N=8 | 25 <= score <= 28 | 146/200 (73%) |
| Thu | Crew 6x6, deeper detours | N=8 | detour >= 12 | 76/200 (38%) |
| Fri | Crew 6x6, minimal | N=8 | tier C and score >= 29 | 40/200 (20%) |
| Sat | Hotshot 7x7, minimal | N=12 | tier C and detour >= 8 | «SAT_ACC» |
| Sun | Sunday Burn 8x8, redundant | N=16, floor 28 | score <= 35 (structural: floor implies it) | «SUN_ACC» |

Curation draws candidates from the per-day seed stream until the predicate
accepts (`curator.py`; caps at 200 candidates, then hard failure — never a
silent band violation). Expected candidates/day = 1/acceptance; the worst
band (Mon, 14%) costs ~7 candidates of the cheapest profile.

The completion-rate targets these bands aim at (Mon >= 75% ... Sat ~45%)
live in `calibration/README.md`; retuning happens by editing the constants
in `grader.py` + this file together, and ships as a new content_version
affecting future dates only.

## 5. Academy lessons — operationalization (product §5, 7 lessons x 2 boards)

"Requires kind K" below means: deduction-solvable by the reference solver,
but **stuck when singletons justified by K are removed from the rule set**
(`curator._requires_kind`) — the strict sense of "filtered to require
exactly that lesson's argument". Acceptance rates measured over the first
80 5x5-minimal seeds.

| Lesson | Board | Predicate | technique tag | Measured |
|---|---|---|---|---|
| 1 First Shift | 5x5 floor10 | tier in {A,B} (rules walkthrough practice; no kind) | — (untagged) | 14% |
| 2 Too Fast Means Walls | 5x5 minimal | has + requires `clue_reached_too_fast` | clue_reached_too_fast | 80/80 |
| 3 Too Slow Means Roads | 5x5 minimal | open-step justified by `clue_unreachable_in_time` + requires it | clue_unreachable_in_time | 79/80 |
| 4 Chains to the Spark | 5x5 minimal | >= 2 open-steps citing the *same clue* via `clue_unreachable_in_time` with minute > 1, + requires it | clue_unreachable_in_time | 69/80 |
| 5 Nothing Is Spared | 6x6 minimal | `open_cell_unreachable` **if exercised**; fallback below | clue_unreachable_in_time | primary 0/80 -> fallback |
| 6 Counting the Endgame | 5x5 minimal | count-fill cascade >= 6 consecutive steps | all_breaks_placed | 42/80 |
| 7 The Long Way Around | 7x7 minimal | some clue with minute >= Manhattan(spark) + 8 ("max-time >= L1+8") | — (untagged) | «L7_ACC» |

Documented gaps and choices (not faked):

- **Lesson 4 ("every t needs a t−1 neighbor"):** the solver expresses chain
  arguments as repeated `clue_unreachable_in_time` steps walking a forced
  corridor toward the spark. Operationalized as >= 2 open-steps citing the
  same clue with reason minute > 1. 86% of 5x5-minimal boards qualify —
  the *requires* clause plus the same-clue chain is what distinguishes the
  practice boards from lesson 3's single-step boards.
- **Lesson 5:** `open_cell_unreachable` is **not producible by generation
  filtering** (0/600+ boards across all profiles, 0 in the frozen vectors).
  Reason: the frozen check order tests clue starvation first, so sealed
  pockets surface as `clue_unreachable_in_time`. Per the brief this is
  documented, not faked: the practice boards use the nearest exercised
  argument — an open-step where shading would starve a region of its only
  route (`clue_unreachable_in_time`), tagged accordingly. The lesson's
  animated demo (WS-12) can still *show* a pure pocket on a fixed board.
- **Lessons 1 and 7** have no reason-kind requirement (rules walkthrough /
  board-shape capstone), so their pack entries carry **no technique tag**
  (`technique` is optional in pack.v1). Lesson 7's criterion is the
  showpiece property itself: a clue readable only the long way around
  (detour >= 8 on a 7x7).
- Every lesson-2..6 practice board's tag is backed by `_requires_kind` or
  the cascade/chain predicate on the certified steps — the same steps the
  Coach replays, so lesson filtering and hints can never disagree.

## 6. Refusal invariants

Emit re-verifies per board: exact solution burn (`burn_verdict == ok`),
uniqueness (`count_solutions(limit=2) == 1`, unbudgeted), deducibility
(`deduction_solve` reproduces the solution), witness
(`breaks_witnessed`). Any failure raises RefusalError -> nonzero exit,
nothing ships (tests cover a hand-edited clue, a deleted clue, and CLI exit
codes).
