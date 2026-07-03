"""Curation: deterministic weekly bands + academy filtering."""

import datetime
import json

import pytest

from burnfront_pipeline import curator, engine, grader

# 2026-07-06 is a Monday. Test seed stream is independent of the committed
# content-sample seeds.
CFG = curator.SeedsConfig(master_seed=424242,
                          start_date=datetime.date(2026, 7, 6),
                          incident_base=100)


def test_load_seeds(tmp_path):
    p = tmp_path / "seeds.json"
    p.write_text(json.dumps({"master_seed": 7, "start_date": "2026-07-06",
                             "incident_base": 1}))
    cfg = curator.load_seeds(p)
    assert cfg.master_seed == 7
    assert cfg.start_date.weekday() == 0
    assert cfg.incident_base == 1


def test_monday_band_redundant_and_gentle():
    rec = curator.curate_daily(CFG, 0)
    assert rec.date.weekday() == 0
    assert (rec.pz.R, rec.pz.C, rec.pz.n_breaks) == (5, 5, 4)
    assert rec.grade.rule_tier in ("A", "B")
    assert len(rec.pz.clues) >= grader.MON_MIN_CLUES
    assert rec.incident == 100
    assert rec.player_tier == "lookout"


def test_tuesday_band_minimal():
    rec = curator.curate_daily(CFG, 1)
    assert rec.date.weekday() == 1
    assert rec.grade.score >= grader.TUE_MIN_SCORE
    assert rec.incident == 101


def test_curation_is_deterministic():
    a = curator.curate_daily(CFG, 0)
    b = curator.curate_daily(CFG, 0)
    assert engine.board_json(a.pz) == engine.board_json(b.pz)
    assert a.solution == b.solution
    assert a.seed == b.seed


def test_assign_ids_are_unique_sized_serials():
    def rec(rows, cols):
        pz = engine.Puzzle(rows, cols, (0, 0), {(0, 1): 1}, 1)
        return curator.CuratedPuzzle(pz=pz, solution={}, grade=None, seed=0)

    records = [rec(5, 5), rec(6, 6), rec(5, 5), rec(7, 7)]
    curator.assign_ids(records)
    ids = [r.id for r in records]
    assert ids == ["bf1-5x5-000001", "bf1-6x6-000001", "bf1-5x5-000002",
                   "bf1-7x7-000001"]
    assert len(set(ids)) == len(ids)


def test_lesson_2_boards_require_too_fast_walls():
    lesson = curator.LESSONS[1]
    assert lesson.slug == "too-fast-means-walls"
    found = curator.curate_lesson(CFG, lesson)
    assert len(found) == 2
    for rec in found:
        assert rec.technique == "clue_reached_too_fast"
        assert "clue_reached_too_fast" in rec.grade.techniques
        # strict requirement: the rule set without the argument stalls
        reduced, _ = grader.tiered_deduction_steps(
            rec.pz, grader.ALL_KINDS - {"clue_reached_too_fast"})
        assert reduced is None


def test_lesson_table_matches_product_spec():
    assert [l.number for l in curator.LESSONS] == [1, 2, 3, 4, 5, 6, 7]
    l7 = curator.LESSONS[6]
    assert (l7.rows, l7.cols) == (7, 7)
    assert l7.slug == "the-long-way-around"
    assert curator.LESSONS[0].technique is None      # rules walkthrough
    assert curator.LESSONS[5].technique == "all_breaks_placed"


def test_curation_error_when_band_cannot_be_met(monkeypatch):
    monkeypatch.setattr(curator, "MAX_ATTEMPTS_DAILY", 2)
    impossible = grader.Band("thu", 5, 5, 4, None, "unmeetable test band")
    monkeypatch.setitem(grader.BANDS, "thu", impossible)
    monkeypatch.setattr(grader, "band_accepts", lambda band, g: False)
    with pytest.raises(curator.CurationError):
        curator.curate_daily(CFG, 3)   # 2026-07-09 is a Thursday
