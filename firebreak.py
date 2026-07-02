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
  * a generator: build a random terrain, take the full clue set, then
    greedily remove clues while both uniqueness and deducibility hold.

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

def count_solutions(pz, limit=2, collect=None):
    state = initial_state(pz)
    count = 0

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
        if count >= limit:
            return
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


def generate(R, C, N, seed=None, require_detour=True, max_tries=500):
    """
    Generate a Firebreak puzzle with a unique solution that is provably
    solvable by single-cell deductions (no guessing).

    Strategy: build a full solution, start from the complete clue set
    (which is trivially unique), then greedily delete clues; a deletion is
    kept only if the exact solver still reports exactly one solution AND
    the deduction-only solver still finishes.
    """
    rng = random.Random(seed)
    for _ in range(max_tries):
        spark, shaded, times = random_terrain(R, C, N, rng)
        if require_detour and not any(
                t > abs(x[0] - spark[0]) + abs(x[1] - spark[1])
                for x, t in times.items()):
            continue  # boring: no clue can ever force a detour
        clues = {x: t for x, t in times.items() if x != spark}
        pz = Puzzle(R, C, spark, clues, N)
        assert count_solutions(pz) == 1
        # Greedy clue removal, repeated until no clue can be removed. A clue
        # is dropped only if the puzzle stays unique AND deduction-solvable.
        removed_any = True
        while removed_any:
            removed_any = False
            order = list(pz.clues)
            rng.shuffle(order)
            for c in order:
                trial = Puzzle(R, C, spark,
                               {k: v for k, v in pz.clues.items() if k != c},
                               N)
                if count_solutions(trial) == 1 and deduction_solve(trial):
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
    """The instance produced by generate(5, 5, 4, seed=12), hard-coded so
    the README example stays stable. Columns A-E, rows 1-5: spark at A4,
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

    # Generated puzzles must be unique, deducible, and their published
    # solution must be found by the exact solver.
    for seed in range(5):
        pz, solution, _ = generate(5, 5, 4, seed=seed)
        sols = []
        assert count_solutions(pz, limit=3, collect=sols) == 1
        assert sols[0] == solution
        assert deduction_solve(pz) == solution

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


def main():
    ap = argparse.ArgumentParser(description="Firebreak puzzle toolkit")
    ap.add_argument("--demo", action="store_true", help="run the README example")
    ap.add_argument("--selftest", action="store_true")
    ap.add_argument("--generate", nargs=3, type=int, metavar=("R", "C", "N"))
    ap.add_argument("--seed", type=int, default=None)
    args = ap.parse_args()
    if args.selftest:
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
