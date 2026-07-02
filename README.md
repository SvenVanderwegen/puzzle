# Firebreak

A new pen-and-paper deduction puzzle. You are shown where a fire *started* and
when it reached a few cells — you must reconstruct the firebreaks that shaped
its spread.

The core mechanic is, to the best of my knowledge, new to paper puzzles: the
clues are **arrival times of a single spreading process**, and the solver
reasons backwards from the time field to the terrain that produced it
(an "inverse BFS" / inverse shortest-path problem). Everything in this
document — uniqueness, solvability without guessing, the worked example — is
verified by the reference implementation in [`firebreak.py`](firebreak.py).

---

## Rules (player-facing)

> **Firebreak.** Shade exactly **N** cells as firebreaks; the ★ and the
> numbered cells are never shaded. Fire starts on the ★ at minute 0, and each
> minute it spreads from every burning cell to every orthogonally adjacent
> unshaded cell. Every unshaded cell must eventually burn, and every numbered
> cell must catch fire at exactly the minute shown.

That's the whole game (N is printed next to each puzzle). In practice you
solve with two kinds of pencil marks: shading for firebreaks, and small
numbers for burn minutes as you pin them down.

Two immediate consequences worth internalizing (they follow from the rules,
players discover them in the first minute):

* **Speed limit.** Two unshaded neighbors always burn within 1 minute of each
  other, and every cell burning at minute *t* ≥ 1 has a neighbor that burned
  at *t* − 1. The burn minutes form a wavefront.
* **Distance.** A cell's burn minute is exactly the length of the shortest
  unshaded path from the ★. It can never be *less* than its straight-line
  (taxicab) distance from the ★ — a bigger number means a forced detour.

---

## What makes it structurally new

Comparison against the established puzzle families, by what the clue
*means* and what the solver *constructs*:

| Family | Examples | Clue semantics | Why Firebreak is different |
|---|---|---|---|
| Number placement | Sudoku, Kakuro, KenKen, Futoshiki | Symbols constrained by row/column/region combinatorics | Firebreak places no symbols; numbers are given, never written into a constraint system |
| Shading by counts | Nonogram, Tapa, Hitori, Nurikabe, LITS | Clue counts cells in a line, block, or region — a *local/censused* quantity | Firebreak's clue is the value of a **global metric field** (geodesic distance from the ★ through the solver's own terrain) — no clue counts anything |
| Visibility | Kuromasu/Range, Cave/Corral, Akari, Skyscrapers | Clue measures what is seen along **straight rays** | Arrival time follows **shortest paths that bend around obstacles**; the whole point is detours, which rays cannot express |
| Loop / path drawing | Slitherlink, Masyu, Numberlink, Yajilin | Solver draws a 1-D loop/path satisfying local clues | Firebreak's object is a set of blocked cells; the "path structure" is an emergent wavefront, not a drawn line |
| Object placement | Minesweeper, Tents, Battleships, Star Battle | Clue counts hidden objects in a **fixed neighborhood** | Firebreak also locates hidden cells, but a clue reaches **arbitrarily far**: an 11 three cells from the ★ speaks about the whole board's topology, not a neighborhood census |
| Sequence along a path | Hidato, Numbrix, snake puzzles | Numbers are a **bijection** along one self-avoiding path the solver draws | Firebreak's minutes form a BFS field with many ties (a front, not a path), and the unknown is the *terrain*, not the route |
| Region division | Fillomino, Shikaku, Galaxies | Partition the grid into shaped/size-counted regions | No partition exists here at all |
| Logic grids | Einstein/Zebra puzzles | Cross-referencing categorical facts | Nothing categorical; the deductions are geometric |

The structural novelty in one sentence: **the constraint is dynamic** — clues
describe *when* something happened under a simple simultaneous process, and
every deduction is an argument about the lengths of open routes ("if this
cell were open, the fire would have arrived too early"). No existing family
constrains geodesic distance through solver-chosen obstacles.

---

## How it solves: the deduction toolkit

All Firebreak deductions reduce to five reusable arguments, which is what
makes it learnable yet deep:

1. **Too fast → wall.** A clue larger than the length of some fully-open
   route is a contradiction, so *some* cell on every shorter route must be a
   firebreak. When only one candidate cell can do the blocking, it is forced.
   (First aha: "the 5 is only 3 steps away — these two cells *must* be
   breaks.")
2. **Too slow → channel.** A clue must also be reachable *no later* than its
   number. When exactly one route of the right length survives, every cell
   on it is forced open — and the wavefront minutes 1, 2, 3, … get written
   along it for free. (Second aha: one clue suddenly fixes a whole corridor.)
3. **Predecessor chains.** A cell burning at *t* needs a neighbor burning at
   *t* − 1; dead ends propagate backwards toward the ★.
4. **Nothing is spared.** Every unshaded cell must burn, so a shading that
   would seal off a pocket is illegal.
5. **The count.** Exactly N breaks exist. Once all are located, everything
   else is open and the remaining minutes cascade in seconds — a satisfying
   endgame. (Final aha: verifying that the "impossibly late" clue is
   satisfied because the fire went the long way around.)

**Difficulty scaling.**

* *Size and break density.* Bigger grids with ~20–25 % of cells shaded give
  corridor-rich terrain and long deduction chains; sparse breaks on small
  grids give gentle starters.
* *Clue redundancy.* The generator can stop deleting clues early (easy) or
  minimize to an irredundant set where every clue is load-bearing (hard).
* *Detour depth.* Terrain accepted only if some cell burns much later than
  its taxicab distance forces deep "the fire went around" reasoning; the gap
  between burn time and distance is a direct difficulty dial.
* *Expert variants* (same engine): hide N; multiple ★s (fronts merge —
  clues mean "whichever fire arrives first"); "✕ = this cell never burns"
  clues on shaded cells.

---

## Generation algorithm (uniqueness by construction)

Full solution first, then clue deletion guarded by **two oracles**: an exact
solution counter (uniqueness is *proven* at every step, never hoped for) and
a no-search deduction solver (so the published puzzle is guaranteed solvable
by pure reasoning — no guessing required).

```text
GENERATE(R, C, N):
  repeat:
    # 1. Build a full solution (the terrain).
    spark   ← random cell
    breaks  ← N random cells ≠ spark, such that the unshaded cells
              form one connected region containing the spark   # all cells burn
    times   ← BFS distances from spark over unshaded cells

    # 1b. Repair until every break is WITNESSED — opening it (the others
    #     staying) would change some cell's burn time. A silent break
    #     could only ever be found by exhausting the count, which feels
    #     arbitrary to a solver; relocate silent breaks (connectivity
    #     preserved, times recomputed) until none remain.
    while some break s is silent:
      move s to a random legal cell;  recompute times
    if no cell has times[cell] > taxicab(spark, cell): retry   # demand ≥1 detour

    # 2. Start from the full clue set — trivially unique:
    #    all clue cells are forced open, and the break count forces
    #    every remaining cell to be shaded.
    clues ← { (cell, times[cell]) : cell unshaded, cell ≠ spark }
    assert COUNT_SOLUTIONS(clues, limit=2) = 1

    # 3. Greedy clue deletion to a fixpoint, guarded by all three oracles.
    repeat until no clue was removed this pass:
      for each clue c in random order:
        if COUNT_SOLUTIONS(clues \ {c}, limit=2) = 1        # still unique
           and DEDUCTION_SOLVE(clues \ {c}) succeeds        # still guess-free
           and WITNESSED(breaks, clues \ {c}):              # every break still
          clues ← clues \ {c}                               #   justified by a clue

    return (spark, clues, N)          # solution = breaks, kept for the answer key


COUNT_SOLUTIONS(clues, limit):        # exact solver = uniqueness oracle
  state[cell] ∈ {UNKNOWN, OPEN, SHADED};  spark and clue cells start OPEN
  DFS over UNKNOWN cells (branch SHADED/OPEN), pruning with sound tests only:
    • count bounds:  #SHADED ≤ N  and  #SHADED + #UNKNOWN ≥ N
    • optimistic BFS (UNKNOWN treated as open):  real times can only be
      larger, so require  d_opt(clue) ≤ clue value  for every clue,
      and every OPEN cell must be optimistically reachable
    • pessimistic BFS (through OPEN cells only):  fire provably travels at
      least this fast, so require  d_pes(clue) ≥ clue value
  when count constraints force the rest, fill and check exactly:
    BFS over OPEN cells; all OPEN reachable; every clue's distance equals
    its value; #SHADED = N
  count full assignments that pass, stop at `limit`


DEDUCTION_SOLVE(clues):               # no-guessing oracle, zero backtracking
  repeat until stuck or solved:
    if #SHADED = N: all UNKNOWN → OPEN;  if #SHADED + #UNKNOWN = N: → SHADED
    for each UNKNOWN cell x:
      if state with x=OPEN   fails the feasibility tests: x ← SHADED
      if state with x=SHADED fails the feasibility tests: x ← OPEN
  succeed iff every cell got assigned (then it must equal the solution)
```

**Why uniqueness is guaranteed.** The initial clue set admits exactly one
solution (step 2), and a clue is only ever deleted after the exact counter —
an exhaustive search whose pruning rules never discard a completable state —
re-proves that exactly one solution remains. Uniqueness is an invariant of
the loop, not a post-hoc hope. **Why no guessing is needed.** Each deletion
must also preserve success of `DEDUCTION_SOLVE`, which uses only single-cell
refutations — precisely the "if this were open, the 5 would burn too soon"
reasoning a human does. Every shipped puzzle therefore carries a
machine-checked certificate of a guess-free solving path.
**Why no break feels arbitrary.** Steps 1b and 3 maintain a fairness
invariant: for every firebreak, opening it (with the others in place) would
let the fire reach at least one *printed clue* earlier than its number. A
solver can always point at a concrete clue that a given break protects —
no break sits on an innocuous-looking square justified only by "the count
says the leftovers must be shaded." Runtime stays practical at
pen-and-paper scales: the JavaScript engine in [`index.html`](index.html)
generates 5×5 in milliseconds and 7×7 with 12 breaks in a few seconds; this
plain-Python reference, tuned for readability over speed, takes up to a
minute or two on 7×7.

---

## Worked example (5×5, N = 4)

Shown by `python3 firebreak.py --demo` (a fixed instance; the program
verifies the solution count is exactly 1 and prints the deduction trace).
Columns A–E, rows 1–5:

```text
     A  B  C  D  E
  1  .  .  .  .  .
  2  .  .  .  .  8
  3  .  .  5  .  .          Shade exactly 4 firebreaks.
  4  ★  1  .  .  .
  5  .  2  .  8  .
```

**Step 1 — the 5 is too close (too fast → wall).** C3 says minute 5, but it
is only 3 steps from the ★. B4 is a numbered cell, so it is open and burns at
minute 1. If B3 were open, the route ★→B4→B3→C3 would burn C3 at minute 3 —
too early. So **B3 is a firebreak**. The same argument along ★→B4→C4→C3
forces **C4** — and with those two shaded, every 3-step route to C3 is dead
(the third one, ★→A3→B3→C3, also used B3).

**Step 2 — the 8 at D5 is also too close.** ★→B4→B5→C5→D5 runs through two
numbered (hence open) cells; if C5 were open, D5 would burn by minute 4, not
8. So **C5 is a firebreak**. Three of four breaks placed.

**Step 3 — the 5 still needs a road (too slow → channel).** With B3 and C4
shaded, the only surviving route to C3 of length 5 is
★→A3→A2→B2→C2→C3. Shading any cell of it (we have exactly one break left)
would leave every remaining route longer than 5 — check A3, A2, B2, C2: each
one, if shaded, cuts C3 off past minute 5. So the whole corridor is open, it
is now the *shortest* open route, and the wavefront is pinned along it:
A3 = 1, A2 = 2, B2 = 3, C2 = 4, C3 = 5. Exactly on time.

**Step 4 — the 8 at D5 needs its road too.** C5 is shaded, so fire enters D5
from D4 or E5. If D3 took the final break, the entire south-east pocket could
only be reached the long way around the top, arriving at D5 at minute 10 —
too late. So **D3 is open**, and by the same argument **D4 is open**, giving
C3(5)→D3(6)→D4(7)→D5(8). Exactly on time.

**Step 5 — the late 8 places the last break.** E2 says 8, but through the
corridor from Step 3 the fire would arrive B2(3)→C2(4)→D2(5)→E2(6) if D2 were
open. Too early — so **D2 is the fourth firebreak**.

**Step 6 — the count finishes it.** All 4 breaks are placed, so every
remaining cell is open; just let the fire spread: A5 = 1, and across the top
A1 = 3, B1 = 4, C1 = 5, D1 = 6, E1 = 7 — and there is the payoff: E2 burns at
minute **8**, on time, because the fire went over the top. D5 = 8 and E5 = 9
close out the grid. Every clue checks; no step guessed.

**Solution** (`#` = firebreak, numbers = burn minutes):

```text
     A  B  C  D  E
  1  3  4  5  6  7
  2  2  3  4  #  8
  3  1  #  5  6  7
  4  ★  1  #  7  8
  5  1  2  #  8  9
```

---

## Bonus puzzle (7×7, N = 12)

Reproduce with `python3 firebreak.py --generate 7 7 12 --seed 1` (takes
about a minute; verified unique, guess-free, and every break witnessed;
10 clues). The showpiece: the cell diagonally adjacent to the ★ — two steps
away — burns at minute **18**. The fire has to lap the entire board to
reach it.

```text
  .  .  .  .  .  . 10
  .  .  3  .  7  .  .
  .  .  .  .  8  9  .
  .  .  .  ★  .  . 17
  .  7  .  . 18  .  .
  .  8  . 12  .  .  .
  .  .  .  .  .  .  .
```

<details><summary>Solution</summary>

```text
  6  5  4  5  6  # 10
  5  4  3  #  7  8  9
  4  3  2  #  8  9  #
  5  #  1  ★  #  # 17
  6  7  #  # 18 17 16
  7  8  # 12  #  # 15
  8  9 10 11 12 13 14
```

</details>

---

## Honest assessment: nearest relatives

The closest existing puzzles, in order:

1. **Kuromasu / Range** — the surface is the same (a grid with numbers in
   cells; shade some cells; numbers constrain the shading), so at a glance
   Firebreak looks like a Kuromasu cousin. But Kuromasu's clue counts cells
   visible along straight rays, a static census that stops at the first
   black cell in four directions. Firebreak's clue is a shortest-path length
   through whatever maze the solver builds — clues act at unbounded range,
   around corners, and interact with each other through shared routes. None
   of Kuromasu's deductions transfer; none of Firebreak's (too-fast walls,
   forced corridors, wavefront pinning) exist there.
2. **Minesweeper-style puzzles** — shared spirit of "locate hidden cells
   from numbers," but a Minesweeper clue is a radius-1 count; removing a
   distant flag never changes it. In Firebreak a single break can change
   clue values across the whole board, which is exactly what the detour
   deductions exploit.
3. **Hidato / Numbrix** — the filled solution grid superficially resembles
   their consecutive numbering, but there the numbers are a bijection along
   one hand-drawn path; here they are a many-tied BFS field and the unknown
   is the terrain. The resemblance is cosmetic (both write increasing
   numbers) rather than structural.

What is genuinely shared with the broader canon: shading as the mark type,
a connectivity side-rule (as in Nurikabe), and a global count of shaded
cells (as in Battleships fleets). Those are standard *ingredients*. The
*mechanic* — deducing obstacles from the arrival times of a simulated
front, i.e. solving an inverse shortest-path problem by hand — does not
appear in any established genre I know of (checked against the Nikoli,
GMPuzzles, puzz.link, and Simon Tatham catalogs). The closest *name*,
puzz.link's "Firewalk", is an unrelated loop-routing genre.

---

## Reference implementation

```bash
python3 firebreak.py --demo               # worked example + deduction trace
python3 firebreak.py --generate 7 7 12    # fresh 7×7, 12 breaks (--seed n to fix)
python3 firebreak.py --selftest           # uniqueness, deducibility, clue
                                          # irredundancy regression checks
```

No dependencies beyond the Python 3 standard library.

## Playable web version

[`index.html`](index.html) is a self-contained interactive version (no build,
no dependencies — open it in any browser, or serve it via GitHub Pages). It
ports the generator and all three oracles to JavaScript, so every "New
fire" is generated on the spot with the same guarantees: a verified unique
solution, reachable by deduction alone, with every firebreak witnessed by
a printed clue. Marks cycle firebreak → clear-ground
dot → empty; when the last break is placed the board checks itself, and on a
correct solution the fire replays across the map, minute by minute. Three
difficulty tiers: 5×5 with 4 breaks, 6×6 with 8, and 7×7 with 12.
