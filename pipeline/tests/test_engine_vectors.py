"""The vectors keep the engine copy honest (CLAUDE.md rule 3)."""

from burnfront_pipeline import engine


def _cells_from_bits(rows, cols, bits):
    return {(r, c) for r in range(rows) for c in range(cols)
            if bits[r * cols + c] == "1"}


def test_burn_vectors_full_replay(burn_vectors):
    assert len(burn_vectors) == 509
    for v in burn_vectors:
        R, C = v["rows"], v["cols"]
        spark = tuple(v["spark"])
        clues = {(c["r"], c["c"]): c["m"] for c in v["clues"]}
        shaded = _cells_from_bits(R, C, v["shading"])
        valid, reason = engine.burn_verdict(R, C, spark, clues,
                                            v["breaks"], shaded)
        assert (valid, reason) == (v["valid"], v["reason"]), v["id"]
        assert engine.flat_times(R, C, spark, shaded) == v["times"], v["id"]


def test_generate_parity_with_reference(parity_boards):
    for gid, (v, pz, solution, _times) in parity_boards.items():
        assert engine.board_json(pz) == {
            "rows": v["rows"], "cols": v["cols"], "breaks": v["breaks"],
            "spark": v["spark"], "clues": v["clues"]}, gid
        shaded = {x for x, s in solution.items() if s == engine.SHADED}
        assert engine.shading_bits(pz.R, pz.C, shaded) == v["solution"], gid
        assert engine.flat_times(pz.R, pz.C, pz.spark, shaded) == v["times"], gid


def test_deduction_step_parity(parity_boards, deduction_vectors):
    for gid, (v, pz, _solution, _times) in parity_boards.items():
        steps, state = engine.deduction_steps(pz)
        assert steps == deduction_vectors[gid]["steps"], gid
        assert len(steps) == v["deduction_steps"], gid


def test_parity_boards_hold_all_three_certificates(parity_boards):
    for gid, (_v, pz, solution, _times) in parity_boards.items():
        sols = []
        assert engine.count_solutions(pz, limit=3, collect=sols) == 1, gid
        assert sols[0] == solution, gid
        assert engine.deduction_solve(pz) == solution, gid
        shaded = {x for x, s in solution.items() if s == engine.SHADED}
        assert engine.breaks_witnessed(pz.R, pz.C, pz.spark, shaded,
                                       pz.clues), gid


def test_demo_puzzle_matches_reference_selftest():
    pz = engine.Puzzle(5, 5, (3, 0),
                       {(1, 4): 8, (2, 2): 5, (3, 1): 1, (4, 1): 2,
                        (4, 3): 8}, 4)
    assert engine.count_solutions(pz, limit=3) == 1
    state = engine.deduction_solve(pz)
    assert state is not None
    demo_breaks = {(1, 3), (2, 1), (3, 2), (4, 2)}
    assert {x for x, s in state.items() if s == engine.SHADED} == demo_breaks
    assert engine.breaks_witnessed(pz.R, pz.C, pz.spark, demo_breaks, pz.clues)
    # irredundant: removing any clue breaks uniqueness or deducibility
    for c in list(pz.clues):
        trial = engine.Puzzle(pz.R, pz.C, pz.spark,
                              {k: v for k, v in pz.clues.items() if k != c},
                              pz.n_breaks)
        assert (engine.count_solutions(trial, limit=3) != 1
                or not engine.deduction_solve(trial)), c


def test_min_clues_floor_keeps_all_certificates():
    pz, solution, _times = engine.generate(5, 5, 4, seed=11, min_clues=10)
    assert len(pz.clues) >= 10
    assert engine.count_solutions(pz, limit=2) == 1
    assert engine.deduction_solve(pz) == solution
    shaded = {x for x, s in solution.items() if s == engine.SHADED}
    assert engine.breaks_witnessed(pz.R, pz.C, pz.spark, shaded, pz.clues)


def test_terrain_predicate_only_prefilters():
    # A never-true predicate exhausts tries; a permissive one changes nothing.
    pz_a, sol_a, _ = engine.generate(5, 5, 4, seed=3)
    pz_b, sol_b, _ = engine.generate(5, 5, 4, seed=3,
                                     terrain_predicate=lambda *a: True)
    assert engine.board_json(pz_a) == engine.board_json(pz_b)
    assert sol_a == sol_b
