#!/usr/bin/env python3
"""
Firebreak — reference implementation.

A Firebreak puzzle is played on an R x C grid. One cell is the spark (*).
Some cells carry a number. The solver shades exactly N cells (firebreaks).
Fire starts on the spark at minute 0 and each minute spreads from every
burning cell to every orthogonally adjacent unshaded cell. Every unshaded
cell must eventually burn, and every numbered cell must catch fire at
exactly the minute printed in it. Numbered cells and the spark are never
shaded.

Formally: let S be the set of shaded cells (|S| = N, spark not in S, no
clue cell in S). Let d(x) be the BFS distance from the spark in the grid
graph restricted to unshaded cells. Constraints: d(x) is finite for every
unshaded x, and d(c) == v for every clue (c, v).

This module provides:
  * an exact solver that counts solutions (used to guarantee uniqueness),
  * a deduction-only solver (no search) used to certify that a puzzle is
    solvable by a chain of single-cell inferences — i.e. no guessing,
  * a generator: build a random terrain repaired so every break is
    "witnessed" (opening it would change some burn time — no break is
    justified by the count alone), take the full clue set, then greedily
    remove clues while uniqueness, deducibility, and the witness property
    all hold.

Usage:
  python3 firebreak.py --demo             # the worked example from README
  python3 firebreak.py --generate 7 7 8   # generate a 7x7 with 8 breaks
  python3 firebreak.py --selftest         # regression checks
"""

import argparse
import random
import sys
from collections import deque

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


def generate(R, C, N, seed=None, require_detour=True, max_tries=500):
    """
    Generate a Firebreak puzzle with a unique solution that is provably
    solvable by single-cell deductions (no guessing).

    Strategy: build a full solution on witnessed terrain (every break, if
    opened, changes some burn time — no break is justified by the count
    alone), start from the complete clue set (which is trivially unique),
    then greedily delete clues; a deletion is kept only if the exact solver
    still reports exactly one solution AND the deduction-only solver still
    finishes AND every break stays witnessed by the remaining clues.
    """
    rng = random.Random(seed)
    for _ in range(max_tries):
        spark, shaded, times = witnessed_terrain(R, C, N, rng)
        if require_detour and not any(
                t > abs(x[0] - spark[0]) + abs(x[1] - spark[1])
                for x, t in times.items()):
            continue  # boring: no clue can ever force a detour
        clues = {x: t for x, t in times.items() if x != spark}
        pz = Puzzle(R, C, spark, clues, N)
        assert count_solutions(pz) == 1
        # Greedy clue removal, repeated until no clue can be removed. A clue
        # is dropped only if the puzzle stays unique AND deduction-solvable
        # AND every firebreak keeps a clue that justifies it.
        removed_any = True
        while removed_any:
            removed_any = False
            order = list(pz.clues)
            rng.shuffle(order)
            for c in order:
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
# Rendering
# ---------------------------------------------------------------------------

def render(pz, solution=None, times=None):
    """ASCII grid. '*' spark, numbers are clues, '#' shaded, '.' open."""
    out = []
    for r in range(pz.R):
        row = []
        for c in range(pz.C):
            x = (r, c)
            if x == pz.spark:
                cell = "*"
            elif x in pz.clues:
                cell = str(pz.clues[x])
            elif solution is not None and solution[x] == SHADED:
                cell = "#"
            elif solution is not None and times is not None:
                cell = str(times[x])
            elif solution is not None:
                cell = "."
            else:
                cell = "."
            row.append(cell.rjust(2))
        out.append(" ".join(row))
    return "\n".join(out)


# ---------------------------------------------------------------------------
# The worked example used in README.md (fixed instance, verified here).
# ---------------------------------------------------------------------------

def demo_puzzle():
    """The fixed instance used in the README, kept stable across generator
    changes; the self-test verifies it is unique, deduction-solvable, and
    that every break is witnessed. Columns A-E, rows 1-5: spark at A4,
    clues B4=1, B5=2, C3=5, E2=8, D5=8."""
    clues = {
        (1, 4): 8,
        (2, 2): 5,
        (3, 1): 1,
        (4, 1): 2,
        (4, 3): 8,
    }
    return Puzzle(5, 5, (3, 0), clues, 4)


def run_demo(verbose=True):
    pz = demo_puzzle()
    n = count_solutions(pz, limit=3)
    trace = []
    state = deduction_solve(pz, trace=trace)
    ok = (n == 1 and state is not None)
    if verbose:
        print("Puzzle (5x5, 4 firebreaks):\n")
        print(render(pz))
        print(f"\nSolution count (exact solver): {n}")
        print(f"Solvable by pure deduction:    {state is not None}\n")
        print("Deduction trace:")
        for i, line in enumerate(trace, 1):
            print(f"  {i:2d}. {line}")
        if state:
            times = bfs_times(pz, lambda x: state[x] == OPEN)
            print("\nSolution (# = firebreak, numbers = burn minute):\n")
            print(render(pz, solution=state, times=times))
    return ok


# ---------------------------------------------------------------------------
# Self-test
# ---------------------------------------------------------------------------

def selftest():
    ok = run_demo(verbose=False)
    assert ok, "demo puzzle must be unique and deducible"

    # The demo's breaks must all be witnessed by its clues.
    pz = demo_puzzle()
    demo_breaks = {(1, 3), (2, 1), (3, 2), (4, 2)}
    assert breaks_witnessed(pz.R, pz.C, pz.spark, demo_breaks, pz.clues)

    # Generated puzzles must be unique, deducible, their published solution
    # must be found by the exact solver, and every break must be witnessed.
    for seed in range(5):
        pz, solution, _ = generate(5, 5, 4, seed=seed)
        sols = []
        assert count_solutions(pz, limit=3, collect=sols) == 1
        assert sols[0] == solution
        assert deduction_solve(pz) == solution
        shaded = {x for x, s in solution.items() if s == SHADED}
        assert breaks_witnessed(pz.R, pz.C, pz.spark, shaded, pz.clues)

    # Removing one more clue from the demo must break uniqueness or
    # deducibility (the clue set is irredundant).
    pz = demo_puzzle()
    for c in list(pz.clues):
        trial = Puzzle(pz.R, pz.C, pz.spark,
                       {k: v for k, v in pz.clues.items() if k != c},
                       pz.n_breaks)
        assert count_solutions(trial, limit=3) != 1 or not deduction_solve(trial), \
            f"clue at {c} is redundant"
    print("all self-tests passed")




# ---------------------------------------------------------------------------
# Vector emission (WS-01) — the sole producer of contracts/vectors/*.
# This block is the one sanctioned post-WS-00 edit to the reference
# implementation (pure addition; nothing above it changed — see
# docs/adr/0011 when the contract freeze lands). Determinism rules and
# check/scan orders are documented in contracts/vectors/README.md; TS and
# PHP implementations must reproduce them exactly.
# ---------------------------------------------------------------------------

import hashlib
import json
import os


def _flat_times(R, C, spark, shaded):
    """Row-major burn times over unshaded cells; -1 = shaded or unreached."""
    helper = Puzzle(R, C, spark, {}, 0)
    d = bfs_times(helper, lambda x: x not in shaded)
    return [d.get((r, c), -1) if (r, c) not in shaded else -1
            for r in range(R) for c in range(C)]


def _shading_bits(R, C, shaded):
    return "".join("1" if (r, c) in shaded else "0"
                   for r in range(R) for c in range(C))


def _burn_verdict(R, C, spark, clues, n_breaks, shaded):
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


def _first_violation(pz, state):
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


def _deduction_steps(pz):
    """Mirror of deduction_solve() that records structured steps. Scan is
    row-major; OPEN is assumed before SHADED; count-fills emit one step per
    cell in row-major order."""
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
            why_open = None if ok_open else _first_violation(pz, state)
            state[x] = SHADED
            ok_shaded, _ = feasible(pz, state)
            why_shaded = None if ok_shaded else _first_violation(pz, state)
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


def _jsonl_line(obj):
    return json.dumps(obj, sort_keys=True, separators=(",", ":")) + "\n"


def _board_json(pz):
    return {
        "rows": pz.R, "cols": pz.C, "breaks": pz.n_breaks,
        "spark": list(pz.spark),
        "clues": [{"r": r, "c": c, "m": pz.clues[(r, c)]}
                  for (r, c) in sorted(pz.clues)],
    }


def emit_vectors(outdir):
    """Emit contracts/vectors/{burn,generate,deduction}.v1.jsonl.
    Fully deterministic: fixed seed plan, canonical orderings, sorted keys."""
    os.makedirs(outdir, exist_ok=True)
    seed_plan = ([(3, 3, 2, s) for s in range(6)]
                 + [(4, 4, 3, s) for s in range(8)]
                 + [(5, 5, 4, s) for s in range(20)]
                 + [(6, 6, 8, s) for s in range(13)]
                 + [(7, 7, 12, s) for s in (4, 2, 1)])
    assert len(seed_plan) == 50
    mutation_bases = 29   # first N of seed_plan get burn mutations

    gen_lines, ded_lines, burn_lines = [], [], []
    burn_no = 0

    def burn_case(R, C, spark, clues, n_breaks, shaded):
        nonlocal burn_no
        burn_no += 1
        valid, reason = _burn_verdict(R, C, spark, clues, n_breaks, shaded)
        burn_lines.append(_jsonl_line({
            "id": f"burn-{burn_no:04d}",
            "rows": R, "cols": C, "breaks": n_breaks, "spark": list(spark),
            "clues": [{"r": r, "c": c, "m": clues[(r, c)]}
                      for (r, c) in sorted(clues)],
            "shading": _shading_bits(R, C, shaded),
            "times": _flat_times(R, C, spark, shaded),
            "valid": valid, "reason": reason,
        }))

    for idx, (R, C, N, seed) in enumerate(seed_plan):
        pz, solution, times = generate(R, C, N, seed=seed)
        shaded = {x for x, s in solution.items() if s == SHADED}
        gid = f"gen-{idx:04d}"

        steps, state = _deduction_steps(pz)
        assert steps is not None and state == solution, f"{gid} deduction"

        nonunique = None
        if R <= 6:
            for cell in sorted(pz.clues):
                trial = Puzzle(R, C, pz.spark,
                               {k: v for k, v in pz.clues.items()
                                if k != cell}, N)
                if count_solutions(trial, limit=2, node_budget=100000) == 2:
                    nonunique = list(cell)
                    break

        gen_lines.append(_jsonl_line({
            "id": gid, "seed": seed, **_board_json(pz),
            "solution": _shading_bits(R, C, shaded),
            "times": _flat_times(R, C, pz.spark, shaded),
            "unique": True, "deduction_steps": len(steps),
            "nonunique_without_clue": nonunique,
        }))
        ded_lines.append(_jsonl_line({"id": gid, "steps": steps}))

        # the solved board is always a burn case
        burn_case(R, C, pz.spark, pz.clues, N, shaded)

        if idx >= mutation_bases:
            continue

        rng = random.Random(10_000 + idx)
        cells = [(r, c) for r in range(R) for c in range(C)]
        breaks_rm = sorted(shaded)
        open_free = [x for x in cells
                     if x not in shaded and x != pz.spark
                     and x not in pz.clues]

        muts = []
        if open_free:
            muts.append((shaded - {breaks_rm[0]}) | {open_free[0]})   # swap
            muts.append((shaded - {breaks_rm[0]}) | {pz.spark})       # spark
            first_clue = sorted(pz.clues)[0]
            muts.append((shaded - {breaks_rm[0]}) | {first_clue})     # clue
            muts.append(shaded | {open_free[0]})                      # N+1
            muts.append(set())                                        # all open
        muts.append(shaded - {breaks_rm[-1]})                         # N-1
        for _ in range(7):                                            # random
            pool = [x for x in cells if x != pz.spark
                    and x not in pz.clues]
            if len(pool) >= N:
                muts.append(set(rng.sample(pool, N)))
        # sealed corner pocket
        for corner in ((0, 0), (0, C - 1), (R - 1, 0), (R - 1, C - 1)):
            nb = [x for x in Puzzle(R, C, pz.spark, {}, 0).neighbors(corner)]
            bad = ([corner] + nb)
            if any(x == pz.spark or x in pz.clues for x in bad):
                continue
            if len(nb) == 2 and N >= 2:
                donors = [b for b in breaks_rm if b not in nb][:2]
                needed = [x for x in nb if x not in shaded]
                if len(donors) >= len(needed):
                    muts.append((shaded - set(donors[:len(needed)]))
                                | set(nb))
                    break
        for m in muts:
            burn_case(R, C, pz.spark, pz.clues, N, m)
        # off-by-one clues (board differs, solution shading kept)
        first_clue = sorted(pz.clues)[0]
        for delta in (1, -1):
            m2 = pz.clues[first_clue] + delta
            if m2 >= 1:
                clues2 = dict(pz.clues)
                clues2[first_clue] = m2
                burn_case(R, C, pz.spark, clues2, N, shaded)

    with open(os.path.join(outdir, "generate.v1.jsonl"), "w") as f:
        f.writelines(gen_lines)
    with open(os.path.join(outdir, "deduction.v1.jsonl"), "w") as f:
        f.writelines(ded_lines)
    with open(os.path.join(outdir, "burn.v1.jsonl"), "w") as f:
        f.writelines(burn_lines)
    print(f"emitted {len(gen_lines)} generate, {len(ded_lines)} deduction, "
          f"{len(burn_lines)} burn vectors to {outdir}")


def main():
    ap = argparse.ArgumentParser(description="Firebreak puzzle toolkit")
    ap.add_argument("--demo", action="store_true", help="run the README example")
    ap.add_argument("--selftest", action="store_true")
    ap.add_argument("--generate", nargs=3, type=int, metavar=("R", "C", "N"))
    ap.add_argument("--seed", type=int, default=None)
    ap.add_argument("--emit-vectors", metavar="OUTDIR",
                    help="write contracts/vectors/*.jsonl (WS-01)")
    args = ap.parse_args()
    if args.emit_vectors:
        emit_vectors(args.emit_vectors)
    elif args.selftest:
        selftest()
    elif args.generate:
        R, C, N = args.generate
        pz, solution, times = generate(R, C, N, seed=args.seed)
        print(f"Firebreak {R}x{C}, shade exactly {N} cells:\n")
        print(render(pz))
        print(f"\nClues: {len(pz.clues)}\n\nSolution:\n")
        print(render(pz, solution=solution, times=times))
    else:
        run_demo()


if __name__ == "__main__":
    main()
