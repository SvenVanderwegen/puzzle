"""Firebreak engine — pipeline copy of the frozen reference implementation.

Copied from reference/firebreak.py (WS-05). reference/ stays read-only; this
copy is kept honest by tests/test_engine_vectors.py, which replays
contracts/vectors/ (burn verdicts byte-for-byte, generate/deduction parity on
the reference seed plan). If this module ever disagrees with a vector, this
module is wrong.

Two additions over the reference (both opt-in, both default-off so the
default code path reproduces the reference RNG stream exactly):

* ``generate(min_clues=...)`` stops the greedy clue removal once the clue
  count reaches a floor, yielding redundant-clue boards (Mon/Sun bands).
* ``generate(terrain_predicate=...)`` rejects a witnessed terrain before the
  expensive clue-removal phase (cheap curation pre-filter).

Determinism: no wall clock, no global RNG — every random draw comes from a
``random.Random(seed)`` owned by the caller. Same seed, same board, forever.
"""

from collections import deque
import random

UNKNOWN, OPEN, SHADED = 0, 1, 2
INF = float("inf")


class Puzzle:
    def __init__(self, R, C, spark, clues, n_breaks):
        self.R = R
        self.C = C
        self.spark = spark            # (r, c)
        self.clues = dict(clues)      # {(r, c): minute}
        self.n_breaks = n_breaks      # exact number of shaded cells

    def cells(self):
        return [(r, c) for r in range(self.R) for c in range(self.C)]

    def neighbors(self, cell):
        r, c = cell
        for dr, dc in ((-1, 0), (1, 0), (0, -1), (0, 1)):
            nr, nc = r + dr, c + dc
            if 0 <= nr < self.R and 0 <= nc < self.C:
                yield (nr, nc)


def manhattan(a, b):
    return abs(a[0] - b[0]) + abs(a[1] - b[1])


# ---------------------------------------------------------------------------
# Core: BFS arrival times
# ---------------------------------------------------------------------------

def bfs_times(pz, passable):
    """BFS distances from the spark over cells for which passable(cell)."""
    if not passable(pz.spark):
        return {}
    dist = {pz.spark: 0}
    q = deque([pz.spark])
    while q:
        x = q.popleft()
        for y in pz.neighbors(x):
            if y not in dist and passable(y):
                dist[y] = dist[x] + 1
                q.append(y)
    return dist


# ---------------------------------------------------------------------------
# Feasibility check for a partial assignment (sound pruning: never rejects
# a state that can still be completed to a real solution).
# ---------------------------------------------------------------------------

def feasible(pz, state):
    """Return (ok, reason). state maps every cell to UNKNOWN/OPEN/SHADED."""
    n_shaded = sum(1 for v in state.values() if v == SHADED)
    n_unknown = sum(1 for v in state.values() if v == UNKNOWN)
    if n_shaded > pz.n_breaks:
        return False, "too many firebreaks"
    if n_shaded + n_unknown < pz.n_breaks:
        return False, "not enough cells left to shade"

    # Optimistic distances: treat UNKNOWN as open. Real distances can only
    # be larger, so a clue must satisfy d_opt(c) <= v, and every known-open
    # cell must be optimistically reachable at all.
    d_opt = bfs_times(pz, lambda x: state[x] != SHADED)
    for c, v in pz.clues.items():
        if d_opt.get(c, INF) > v:
            return False, f"clue {v} at {c} can no longer be reached by minute {v}"
    for x, s in state.items():
        if s == OPEN and x not in d_opt:
            return False, f"unshaded cell {x} can never burn"

    # Pessimistic distances: paths through known-open cells only. Fire
    # provably travels at least this fast, so a clue must satisfy
    # d_pes(c) >= v.
    d_pes = bfs_times(pz, lambda x: state[x] == OPEN)
    for c, v in pz.clues.items():
        if d_pes.get(c, INF) < v:
            return False, f"fire would reach clue {v} at {c} by minute {d_pes[c]}"

    return True, ""


def exact_check(pz, state):
    """Exact verification of a complete assignment."""
    if any(s == UNKNOWN for s in state.values()):
        return False
    if sum(1 for s in state.values() if s == SHADED) != pz.n_breaks:
        return False
    d = bfs_times(pz, lambda x: state[x] == OPEN)
    for x, s in state.items():
        if s == OPEN and x not in d:
            return False
    return all(d.get(c, INF) == v for c, v in pz.clues.items())


def initial_state(pz):
    state = {x: UNKNOWN for x in pz.cells()}
    state[pz.spark] = OPEN
    for c in pz.clues:
        state[c] = OPEN
    return state


# ---------------------------------------------------------------------------
# Exact solver: counts solutions up to `limit` by exhaustive search with
# sound pruning. Used as the uniqueness oracle during generation.
# ---------------------------------------------------------------------------

def count_solutions(pz, limit=2, collect=None, node_budget=None):
    """Exact solution count (up to `limit`). If `node_budget` is given and
    exhausted, returns None ("don't know") — callers treat that
    conservatively, so it never endangers the uniqueness invariant."""
    state = initial_state(pz)
    count = 0
    nodes = [node_budget if node_budget is not None else float("inf")]

    def pick_branch_cell():
        # Prefer an unknown cell sitting on a tight optimistic path to some
        # clue; these decisions cut the search fastest. Fall back to any
        # unknown adjacent to an open cell, then to any unknown.
        d_opt = bfs_times(pz, lambda x: state[x] != SHADED)
        best = None
        for c, v in pz.clues.items():
            if d_opt.get(c, INF) == v:
                # walk one shortest path back to the spark
                x = c
                while x != pz.spark:
                    for y in pz.neighbors(x):
                        if d_opt.get(y, INF) == d_opt[x] - 1:
                            if state[y] == UNKNOWN:
                                return y
                            x = y
                            break
        for x in pz.cells():
            if state[x] == UNKNOWN:
                if any(state[y] == OPEN for y in pz.neighbors(x)):
                    return x
                if best is None:
                    best = x
        return best

    def dfs():
        nonlocal count
        if count >= limit or nodes[0] < 0:
            return
        nodes[0] -= 1
        ok, _ = feasible(pz, state)
        if not ok:
            return
        n_shaded = sum(1 for v in state.values() if v == SHADED)
        n_unknown = sum(1 for v in state.values() if v == UNKNOWN)
        # Forced completions by the break count.
        if n_unknown and (n_shaded == pz.n_breaks
                          or n_shaded + n_unknown == pz.n_breaks):
            fill = OPEN if n_shaded == pz.n_breaks else SHADED
            filled = [x for x in pz.cells() if state[x] == UNKNOWN]
            for x in filled:
                state[x] = fill
            dfs()
            for x in filled:
                state[x] = UNKNOWN
            return
        if n_unknown == 0:
            if exact_check(pz, state):
                count += 1
                if collect is not None:
                    collect.append(dict(state))
            return
        x = pick_branch_cell()
        for val in (SHADED, OPEN):
            state[x] = val
            dfs()
            state[x] = UNKNOWN
            if count >= limit:
                return

    dfs()
    if nodes[0] < 0:
        return None          # budget exhausted: solution count unknown
    return count


# ---------------------------------------------------------------------------
# Deduction-only solver: repeatedly find a cell whose value is forced by a
# single-cell test ("if this cell were open/shaded, the puzzle would break").
# No backtracking — success certifies the puzzle needs no guessing.
# ---------------------------------------------------------------------------

def deduction_solve(pz, trace=None):
    state = initial_state(pz)

    def note(msg):
        if trace is not None:
            trace.append(msg)

    progress = True
    while progress:
        progress = False
        n_shaded = sum(1 for v in state.values() if v == SHADED)
        n_unknown = sum(1 for v in state.values() if v == UNKNOWN)
        if n_unknown == 0:
            break
        if n_shaded == pz.n_breaks:
            for x in pz.cells():
                if state[x] == UNKNOWN:
                    state[x] = OPEN
            note("all firebreaks are placed; every remaining cell is open")
            break
        if n_shaded + n_unknown == pz.n_breaks:
            for x in pz.cells():
                if state[x] == UNKNOWN:
                    state[x] = SHADED
            note("every remaining cell must be a firebreak to reach the count")
            break
        for x in pz.cells():
            if state[x] != UNKNOWN:
                continue
            state[x] = OPEN
            ok_open, why_open = feasible(pz, state)
            state[x] = SHADED
            ok_shaded, why_shaded = feasible(pz, state)
            state[x] = UNKNOWN
            if not ok_open and not ok_shaded:
                return None  # contradiction: puzzle unsolvable
            if not ok_open:
                state[x] = SHADED
                note(f"{x} must be a firebreak: if open, {why_open}")
                progress = True
            elif not ok_shaded:
                state[x] = OPEN
                note(f"{x} must be open: if shaded, {why_shaded}")
                progress = True

    if any(s == UNKNOWN for s in state.values()):
        return None
    return state if exact_check(pz, state) else None


# ---------------------------------------------------------------------------
# Generator
# ---------------------------------------------------------------------------

def random_terrain(R, C, N, rng):
    """Pick a spark and N shaded cells such that all unshaded cells burn."""
    cells = [(r, c) for r in range(R) for c in range(C)]
    while True:
        spark = rng.choice(cells)
        candidates = [x for x in cells if x != spark]
        shaded = set(rng.sample(candidates, N))
        pz = Puzzle(R, C, spark, {}, N)
        d = bfs_times(pz, lambda x: x not in shaded)
        if len(d) == R * C - N:          # every unshaded cell burns
            return spark, shaded, d


def breaks_witnessed(R, C, spark, shaded, clues):
    """True iff every firebreak, when opened (the others staying), lets the
    fire reach at least one clued cell earlier than its number. Such a break
    is *witnessed*: the clues themselves justify it, not just the count N."""
    helper = Puzzle(R, C, spark, {}, 0)
    for s in shaded:
        rest = shaded - {s}
        d = bfs_times(helper, lambda x: x not in rest)
        if all(d.get(c) == v for c, v in clues.items()):
            return False
    return True


def witnessed_terrain(R, C, N, rng, max_moves=400):
    """Random terrain repaired so that every break is witnessed by the full
    time map: silent breaks (whose opening changes no burn time) are moved
    to fresh connectivity-preserving positions until none remain."""
    cells = [(r, c) for r in range(R) for c in range(C)]
    while True:
        spark, shaded, times = random_terrain(R, C, N, rng)
        helper = Puzzle(R, C, spark, {}, 0)
        for _ in range(max_moves):
            silent = []
            for s in shaded:
                rest = shaded - {s}
                d = bfs_times(helper, lambda x: x not in rest)
                if all(d.get(x) == t for x, t in times.items()):
                    silent.append(s)
            if not silent:
                return spark, shaded, times
            s = rng.choice(silent)
            for _ in range(50):
                t = rng.choice(cells)
                if t == spark or t in shaded:
                    continue
                cand = (shaded - {s}) | {t}
                d = bfs_times(helper, lambda x: x not in cand)
                if len(d) == R * C - N:
                    shaded, times = cand, d
                    break


def generate(R, C, N, seed=None, require_detour=True, max_tries=500,
             min_clues=None, terrain_predicate=None):
    """
    Generate a Firebreak puzzle with a unique solution that is provably
    solvable by single-cell deductions (no guessing).

    Strategy: build a full solution on witnessed terrain (every break, if
    opened, changes some burn time — no break is justified by the count
    alone), start from the complete clue set (which is trivially unique),
    then greedily delete clues; a deletion is kept only if the exact solver
    still reports exactly one solution AND the deduction-only solver still
    finishes AND every break stays witnessed by the remaining clues.

    Pipeline extensions (default-off; defaults reproduce the reference
    byte-for-byte):

    * ``min_clues``: stop deleting once the clue count reaches this floor —
      the remaining clues are deliberately redundant (easy bands). Every
      intermediate clue set already satisfies unique + deducible +
      witnessed, so stopping early is always safe.
    * ``terrain_predicate(spark, shaded, times)``: reject a terrain before
      the expensive removal phase (curation pre-filter on the full time
      map, e.g. detour depth).
    """
    rng = random.Random(seed)
    for _ in range(max_tries):
        spark, shaded, times = witnessed_terrain(R, C, N, rng)
        if require_detour and not any(
                t > abs(x[0] - spark[0]) + abs(x[1] - spark[1])
                for x, t in times.items()):
            continue  # boring: no clue can ever force a detour
        if terrain_predicate is not None and not terrain_predicate(
                spark, shaded, times):
            continue
        clues = {x: t for x, t in times.items() if x != spark}
        pz = Puzzle(R, C, spark, clues, N)
        assert count_solutions(pz) == 1
        # Greedy clue removal, repeated until no clue can be removed. A clue
        # is dropped only if the puzzle stays unique AND deduction-solvable
        # AND every firebreak keeps a clue that justifies it.
        removed_any = True
        while removed_any and (min_clues is None or len(pz.clues) > min_clues):
            removed_any = False
            order = list(pz.clues)
            rng.shuffle(order)
            for c in order:
                if min_clues is not None and len(pz.clues) <= min_clues:
                    break
                trial_clues = {k: v for k, v in pz.clues.items() if k != c}
                trial = Puzzle(R, C, spark, trial_clues, N)
                if (breaks_witnessed(R, C, spark, shaded, trial_clues)
                        and count_solutions(trial, node_budget=60000) == 1
                        and deduction_solve(trial)):
                    pz = trial
                    removed_any = True
        solution = {x: (SHADED if x in shaded else OPEN)
                    for x in pz.cells()}
        return pz, solution, times
    raise RuntimeError("could not generate an interesting terrain")


# ---------------------------------------------------------------------------
# Structured certificates (mirrors the reference vector-emission block; the
# frozen scan/check orders are documented in contracts/vectors/README.md).
# ---------------------------------------------------------------------------

def flat_times(R, C, spark, shaded):
    """Row-major burn times over unshaded cells; -1 = shaded or unreached."""
    helper = Puzzle(R, C, spark, {}, 0)
    d = bfs_times(helper, lambda x: x not in shaded)
    return [d.get((r, c), -1) if (r, c) not in shaded else -1
            for r in range(R) for c in range(C)]


def shading_bits(R, C, shaded):
    return "".join("1" if (r, c) in shaded else "0"
                   for r in range(R) for c in range(C))


def burn_verdict(R, C, spark, clues, n_breaks, shaded):
    """Validity verdict with a FROZEN check order (vectors README):
    spark_shaded -> clue_shaded (row-major) -> wrong_break_count ->
    unreachable_cell (row-major first) -> clue_time_mismatch (row-major
    first) -> ok."""
    if spark in shaded:
        return False, "spark_shaded"
    for cell in sorted(clues):
        if cell in shaded:
            return False, "clue_shaded"
    if len(shaded) != n_breaks:
        return False, "wrong_break_count"
    helper = Puzzle(R, C, spark, {}, 0)
    d = bfs_times(helper, lambda x: x not in shaded)
    for r in range(R):
        for c in range(C):
            if (r, c) not in shaded and (r, c) not in d:
                return False, "unreachable_cell"
    for cell in sorted(clues):
        if d.get(cell) != clues[cell]:
            return False, "clue_time_mismatch"
    return True, "ok"


def first_violation(pz, state):
    """Structured reason for an infeasible partial state, mirroring
    feasible()'s check order. Returns dict or None if feasible."""
    n_shaded = sum(1 for v in state.values() if v == SHADED)
    n_unknown = sum(1 for v in state.values() if v == UNKNOWN)
    if n_shaded > pz.n_breaks:
        return {"kind": "too_many_breaks", "clue": None, "minute": None}
    if n_shaded + n_unknown < pz.n_breaks:
        return {"kind": "not_enough_breaks_left", "clue": None, "minute": None}
    d_opt = bfs_times(pz, lambda x: state[x] != SHADED)
    for cell in sorted(pz.clues):
        v = pz.clues[cell]
        if d_opt.get(cell, INF) > v:
            return {"kind": "clue_unreachable_in_time",
                    "clue": list(cell), "minute": v}
    for x in pz.cells():
        if state[x] == OPEN and x not in d_opt:
            return {"kind": "open_cell_unreachable", "clue": None,
                    "minute": None}
    d_pes = bfs_times(pz, lambda x: state[x] == OPEN)
    for cell in sorted(pz.clues):
        v = pz.clues[cell]
        if d_pes.get(cell, INF) < v:
            return {"kind": "clue_reached_too_fast",
                    "clue": list(cell), "minute": v}
    return None


def deduction_steps(pz):
    """Mirror of deduction_solve() that records structured steps. Scan is
    row-major; OPEN is assumed before SHADED; count-fills emit one step per
    cell in row-major order. Must match deduction.v1.jsonl exactly."""
    state = initial_state(pz)
    steps = []
    progress = True
    while progress:
        progress = False
        n_shaded = sum(1 for v in state.values() if v == SHADED)
        n_unknown = sum(1 for v in state.values() if v == UNKNOWN)
        if n_unknown == 0:
            break
        if n_shaded == pz.n_breaks:
            for x in pz.cells():
                if state[x] == UNKNOWN:
                    state[x] = OPEN
                    steps.append({"cell": list(x), "state": "open",
                                  "reason": {"kind": "all_breaks_placed",
                                             "clue": None, "minute": None}})
            break
        if n_shaded + n_unknown == pz.n_breaks:
            for x in pz.cells():
                if state[x] == UNKNOWN:
                    state[x] = SHADED
                    steps.append({"cell": list(x), "state": "break",
                                  "reason": {"kind": "rest_must_be_breaks",
                                             "clue": None, "minute": None}})
            break
        for x in pz.cells():
            if state[x] != UNKNOWN:
                continue
            state[x] = OPEN
            ok_open, _ = feasible(pz, state)
            why_open = None if ok_open else first_violation(pz, state)
            state[x] = SHADED
            ok_shaded, _ = feasible(pz, state)
            why_shaded = None if ok_shaded else first_violation(pz, state)
            state[x] = UNKNOWN
            if not ok_open and not ok_shaded:
                return None, None
            if not ok_open:
                state[x] = SHADED
                steps.append({"cell": list(x), "state": "break",
                              "reason": why_open})
                progress = True
            elif not ok_shaded:
                state[x] = OPEN
                steps.append({"cell": list(x), "state": "open",
                              "reason": why_shaded})
                progress = True
    if any(s == UNKNOWN for s in state.values()):
        return None, None
    return steps, state


def board_json(pz):
    return {
        "rows": pz.R, "cols": pz.C, "breaks": pz.n_breaks,
        "spark": list(pz.spark),
        "clues": [{"r": r, "c": c, "m": pz.clues[(r, c)]}
                  for (r, c) in sorted(pz.clues)],
    }
