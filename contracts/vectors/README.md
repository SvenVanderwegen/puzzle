# contracts/vectors — cross-language behavioral truth

Produced ONLY by `python3 reference/firebreak.py --emit-vectors contracts/vectors`
(the reference implementation is the authority). Never hand-edit. CI regenerates and
diffs (`vectors-fresh` job); any mismatch is a hard block (playbook gate 4).

Consumers: `packages/engine` (all three files, WS-02) · `api/` PHP `BurnValidator`
(`burn.v1.jsonl` only, WS-07).

## Files

### burn.v1.jsonl — 509 cases: (board, shading) → times + verdict
```json
{"id":"burn-0001","rows":5,"cols":5,"breaks":4,"spark":[3,0],
 "clues":[{"r":1,"c":4,"m":8}, …],"shading":"0000000010…",
 "times":[3,4,5,…,-1,…],"valid":true,"reason":"ok"}
```
- `shading`/`solution`: row-major bit string, `1` = firebreak.
- `times`: row-major burn minutes over unshaded cells; `-1` = shaded or unreached.
  Always emitted, even for invalid shadings.
- `reason` (FROZEN check order — implementations must report the first failure in
  this order): `spark_shaded` → `clue_shaded` (row-major first) →
  `wrong_break_count` → `unreachable_cell` (row-major first) →
  `clue_time_mismatch` (row-major first) → `ok`.

### generate.v1.jsonl — 50 certified puzzle instances
Board + `solution` + `times` + `unique: true` + `deduction_steps` +
`nonunique_without_clue` ([r,c] of a clue whose removal provably yields ≥2
solutions, or null). The TS engine must confirm: `validate(solution)` ok,
`countSolutions(board) == 1`, `deduce(board)` reaches `solution`, and
`countSolutions(board minus that clue) ≥ 2`.
**PRNG parity with Python is NOT required** — `generate()` implementations must
satisfy the same certificates, not reproduce these exact boards from the seeds.

### deduction.v1.jsonl — certified step lists (Coach fuel)
One entry per generate-board: ordered `steps` of `{cell:[r,c], state:"open"|"break",
reason:{kind, clue:[r,c]|null, minute|null}}`. The TS `deduce()` must reproduce the
step sequence exactly.

## Frozen orderings (required for step-level parity)

- Cell scan: **row-major** (`(0,0),(0,1),…`). Assignments happen mid-scan (a pass
  continues after assigning).
- Per cell: assume **OPEN first, then SHADED**; if exactly one assumption is
  infeasible, assign the other and record the violated assumption's reason.
- Count-fills (all breaks placed → rest open; remaining cells == missing breaks →
  rest shaded) are checked at the top of each pass and emit one step per cell,
  row-major.
- Feasibility check order (first violation wins): `too_many_breaks` →
  `not_enough_breaks_left` → `clue_unreachable_in_time` (clues row-major) →
  `open_cell_unreachable` (cells row-major) → `clue_reached_too_fast` (clues
  row-major). (`all_breaks_placed`/`rest_must_be_breaks` appear only as count-fill
  step reasons. Kinds `not_enough_breaks_left`, `open_cell_unreachable` and
  `rest_must_be_breaks` happen not to occur in the current emitted set — they are
  still part of the frozen enum.)
- BFS: FIFO from spark, neighbor order up/down/left/right — order does not affect
  distances, listed only for completeness.
- Positions serialize as `[r,c]` arrays; clue *definitions* as `{r,c,m}` objects.

## Conventions shared with content (burnfront.puzzle/1)

`solution_sha256` = SHA-256 hex over the ASCII row-major bit string (the `solution`
field verbatim).

## Coverage notes

Verdict distribution: ok 54 · clue_time_mismatch 261 · wrong_break_count 87 ·
unreachable_cell 49 · spark_shaded 29 · clue_shaded 29. Sizes 3×3…7×7; 43/50
generate-boards have a spark-adjacent break. Adversarial mutations cover: solution
swaps, spark/clue shading, off-by-one clue minutes (±1), over/under counts, sealed
corner pockets, random same-count shadings. Larger boards (6×6/7×7) carry
solution + off-by-one cases only; validator logic is size-independent.
