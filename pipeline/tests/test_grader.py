"""Grading v2: tiered rule sets, chain length, bands."""

import pytest

from burnfront_pipeline import engine, grader

DEMO = engine.Puzzle(5, 5, (3, 0),
                     {(1, 4): 8, (2, 2): 5, (3, 1): 1, (4, 1): 2, (4, 3): 8},
                     4)


def test_tier_c_equals_reference_solver(parity_boards):
    for gid, (_v, pz, _solution, _times) in parity_boards.items():
        ref_steps, ref_state = engine.deduction_steps(pz)
        steps, state = grader.tiered_deduction_steps(pz, grader.ALL_KINDS)
        assert steps == ref_steps, gid
        assert state == ref_state, gid


def test_demo_board_grades_tier_c():
    g = grader.grade(DEMO)
    assert g.rule_tier == "C"
    assert g.score == 5 * 5 - 1 - 5 == 19
    assert "clue_unreachable_in_time" in g.techniques
    assert g.detour == 4
    assert g.solution == engine.deduction_solve(DEMO)


def test_score_is_chain_length_and_tier_invariant(parity_boards):
    for gid, (v, pz, _solution, _times) in parity_boards.items():
        g = grader.grade(pz)
        assert g.score == pz.R * pz.C - 1 - len(pz.clues), gid
        assert g.score == v["deduction_steps"], gid
        if g.rule_tier in ("A", "B"):
            steps, _ = grader.tiered_deduction_steps(
                pz, grader.TIER_SINGLETON_KINDS[g.rule_tier])
            assert len(steps) == g.score, gid


def test_tier_b_board_exists_and_needs_no_too_slow_argument():
    # Deterministic: seed 2 of the 5x5-floor10 measurement profile is tier B.
    pz, _sol, _times = engine.generate(5, 5, 4, seed=2, min_clues=10)
    g = grader.grade(pz)
    assert g.rule_tier == "B"
    steps, state = grader.tiered_deduction_steps(
        pz, grader.TIER_SINGLETON_KINDS["B"])
    assert state is not None
    used = {s["reason"]["kind"] for s in steps}
    assert used <= {"clue_reached_too_fast", "all_breaks_placed",
                    "rest_must_be_breaks"}


def test_tier_a_solves_fully_clued_board():
    # With every open cell clued, the unknowns are exactly the breaks:
    # the rest_must_be_breaks count-fill finishes alone.
    pz, solution, times = engine.generate(4, 4, 3, seed=0)
    full_clues = {x: t for x, t in times.items() if x != pz.spark}
    full = engine.Puzzle(4, 4, pz.spark, full_clues, pz.n_breaks)
    steps, state = grader.tiered_deduction_steps(
        full, grader.TIER_SINGLETON_KINDS["A"])
    assert state is not None
    assert {s["reason"]["kind"] for s in steps} == {"rest_must_be_breaks"}
    assert grader.grade(full).rule_tier == "A"


def test_tier_ladder_is_monotone(parity_boards):
    ranks = {"A": 0, "B": 1, "C": 2}
    for gid, (_v, pz, _solution, _times) in parity_boards.items():
        g = grader.grade(pz)
        rank = ranks[g.rule_tier]
        for tier in ("A", "B"):
            steps, _ = grader.tiered_deduction_steps(
                pz, grader.TIER_SINGLETON_KINDS[tier])
            if ranks[tier] < rank:
                assert steps is None, (gid, tier)
            else:
                assert steps is not None, (gid, tier)


def test_player_tier_mapping():
    assert grader.player_tier(5, 5) == "lookout"
    assert grader.player_tier(6, 6) == "crew"
    assert grader.player_tier(7, 7) == "hotshot"
    assert grader.player_tier(8, 8) == "crew"


def test_bands_cover_the_week():
    assert set(grader.BANDS) == set(grader.WEEKDAY_NAMES)
    assert (grader.BANDS["mon"].rows, grader.BANDS["mon"].cols) == (5, 5)
    assert grader.BANDS["mon"].min_clues is not None       # redundant
    assert grader.BANDS["tue"].min_clues is None           # minimal
    assert (grader.BANDS["sat"].rows, grader.BANDS["sat"].n_breaks) == (7, 12)
    assert (grader.BANDS["sun"].rows, grader.BANDS["sun"].cols) == (8, 8)
    assert grader.BANDS["sun"].min_clues is not None       # redundant


def test_band_accepts_demo_criteria():
    g = grader.grade(DEMO)                 # tier C, score 19, detour 4
    assert grader.band_accepts(grader.BANDS["tue"], g)     # >= TUE_MIN_SCORE
    assert not grader.band_accepts(grader.BANDS["mon"], g)  # tier C, too big
    assert not grader.band_accepts(grader.BANDS["thu"], g)  # detour too small
    assert not grader.band_accepts(grader.BANDS["mon"], None)


def test_unsolvable_board_grades_none():
    # Contradictory clue: neighbor of the spark claiming minute 3 while a
    # spark-adjacent cell must burn at minute 1.
    pz = engine.Puzzle(3, 3, (0, 0), {(0, 1): 3, (1, 0): 1, (2, 2): 4}, 2)
    if engine.deduction_solve(pz) is None:
        assert grader.grade(pz) is None
    else:
        pytest.fail("expected an unsolvable construction")
