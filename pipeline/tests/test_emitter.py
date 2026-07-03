"""Emit: determinism, schema conformance, refusal, spoiler-free OG cards."""

import datetime
import hashlib
import inspect
import json
from pathlib import Path

import pytest

from burnfront_pipeline import cli, curator, emitter, engine, grader, signer

from conftest import DEV_KEY, DEV_PUB

CFG = curator.SeedsConfig(master_seed=424242,
                          start_date=datetime.date(2026, 7, 6),  # a Monday
                          incident_base=100)


def _emit_two_days(out_root, with_pack=False):
    """Full pipeline for Mon+Tue (plus lesson-1 pack when asked)."""
    dailies = curator.curate_dailies(CFG, 2)
    academy = curator.curate_lesson(CFG, curator.LESSONS[0]) if with_pack else []
    curator.assign_ids(dailies + academy)
    key = signer.load_signing_key(str(DEV_KEY))
    return emitter.emit_content(out_root, "v20260706-1", dailies, academy, key)


def _tree_digest(root):
    root = Path(root)
    return {str(p.relative_to(root)): hashlib.sha256(p.read_bytes()).hexdigest()
            for p in sorted(root.rglob("*")) if p.is_file()}


@pytest.fixture(scope="module")
def emitted(tmp_path_factory):
    out = tmp_path_factory.mktemp("dist-a")
    version_dir = _emit_two_days(out, with_pack=True)
    return version_dir


def test_same_inputs_give_byte_identical_dist(tmp_path_factory, emitted):
    out_b = tmp_path_factory.mktemp("dist-b")
    version_b = _emit_two_days(out_b, with_pack=True)
    da, db = _tree_digest(emitted), _tree_digest(version_b)
    assert da == db
    assert set(da) >= {"calendar.json", "calendar.json.sig", "puzzles.csv",
                       "packs/academy-1.json", "packs/academy-1.json.sig"}


def test_emitted_content_verifies_end_to_end(emitted):
    vk = signer.load_verify_key(str(DEV_PUB))
    verified = emitter.verify_content(emitted, vk)
    assert "calendar.json" in verified
    assert any(p.startswith("puzzles/") for p in verified)
    assert any(p.startswith("og/") for p in verified)


def test_calendar_matches_weekday_bands(emitted):
    doc = json.loads((emitted / "calendar.json").read_bytes())
    assert doc["content_version"] == "v20260706-1"
    assert doc["from"] == "2026-07-06" and doc["to"] == "2026-07-07"
    tiers = [d["grade_tier"] for d in doc["days"]]
    assert tiers == ["lookout", "lookout"]         # Mon + Tue are 5x5


def test_csv_layout(emitted):
    lines = (emitted / "puzzles.csv").read_text().splitlines()
    assert lines[0] == ("id,rows,cols,n_breaks,grade_tier,grade_score,"
                        "solution_sha256,gen_version,content_version,pack_id")
    assert len(lines) == 1 + 2 + 2                 # header + 2 dailies + L1 pair
    daily = lines[1].split(",")
    assert daily[0].startswith("bf1-5x5-")
    assert daily[9] == ""                          # dailies carry no pack_id
    pack_row = lines[3].split(",")
    assert pack_row[9] == "academy-1"


def test_tampering_with_a_puzzle_fails_verification(tmp_path_factory):
    out = tmp_path_factory.mktemp("dist-tamper")
    version_dir = _emit_two_days(out)
    victim = next((version_dir / "puzzles").glob("*.json"))
    doc = json.loads(victim.read_bytes())
    doc["grade"]["score"] += 1
    victim.write_bytes(emitter.canonical_json_bytes(doc))
    vk = signer.load_verify_key(str(DEV_PUB))
    with pytest.raises(emitter.RefusalError):
        emitter.verify_content(version_dir, vk)


def _corrupt(rec):
    """Hand-edit one clue minute: the board is no longer certifiable."""
    cell = sorted(rec.pz.clues)[0]
    clues = dict(rec.pz.clues)
    clues[cell] += 1
    rec.pz = engine.Puzzle(rec.pz.R, rec.pz.C, rec.pz.spark, clues,
                           rec.pz.n_breaks)
    return rec


def test_refusal_hand_edited_clue(tmp_path):
    rec = _corrupt(curator.curate_daily(CFG, 0))
    curator.assign_ids([rec])
    key = signer.load_signing_key(str(DEV_KEY))
    with pytest.raises(emitter.RefusalError):
        emitter.emit_content(tmp_path, "v20260706-1", [rec], [], key)


def test_refusal_non_unique_board(tmp_path):
    # Dropping a clue from a minimal board breaks uniqueness or deducibility;
    # either way the emit must refuse.
    rec = curator.curate_daily(CFG, 1)             # Tuesday: minimal clues
    cell = sorted(rec.pz.clues)[0]
    clues = {k: v for k, v in rec.pz.clues.items() if k != cell}
    rec.pz = engine.Puzzle(rec.pz.R, rec.pz.C, rec.pz.spark, clues,
                           rec.pz.n_breaks)
    curator.assign_ids([rec])
    key = signer.load_signing_key(str(DEV_KEY))
    with pytest.raises(emitter.RefusalError):
        emitter.emit_content(tmp_path, "v20260706-1", [rec], [], key)


def test_cli_emit_exits_nonzero_on_refusal(tmp_path, monkeypatch):
    seeds = tmp_path / "seeds.json"
    seeds.write_text(json.dumps({"master_seed": 424242,
                                 "start_date": "2026-07-06",
                                 "incident_base": 100}))

    def corrupted_dailies(cfg, days):
        return [_corrupt(curator.curate_daily(cfg, 0))]

    monkeypatch.setattr(curator, "curate_dailies", corrupted_dailies)
    rc = cli.main(["emit", "--date", "20260706", "--seeds", str(seeds),
                   "--days", "1", "--out", str(tmp_path / "dist"),
                   "--key", str(DEV_KEY), "--skip-pack"])
    assert rc != 0


def test_cli_emit_and_verify_roundtrip(tmp_path):
    seeds = tmp_path / "seeds.json"
    seeds.write_text(json.dumps({"master_seed": 424242,
                                 "start_date": "2026-07-06",
                                 "incident_base": 100}))
    rc = cli.main(["emit", "--date", "20260706", "--seeds", str(seeds),
                   "--days", "1", "--out", str(tmp_path / "dist"),
                   "--key", str(DEV_KEY), "--skip-pack"])
    assert rc == 0
    rc = cli.main(["verify", "--dir", str(tmp_path / "dist" / "v20260706-1"),
                   "--pubkey", str(DEV_PUB)])
    assert rc == 0


def test_cli_rejects_bad_date(tmp_path):
    rc = cli.main(["emit", "--date", "2026-07-06", "--seeds", "x",
                   "--out", str(tmp_path)])
    assert rc != 0


def test_og_renderer_cannot_see_the_solution():
    params = set(inspect.signature(emitter.render_og_card).parameters)
    assert params == {"rows", "cols", "spark", "clues", "heading",
                      "subheading"}
    # deterministic bytes; heading changes bytes (text is actually drawn)
    clues = [{"r": 0, "c": 1, "m": 3}, {"r": 2, "c": 2, "m": 5}]
    a = emitter.render_og_card(5, 5, [3, 0], clues, "Incident #100",
                               "Lookout 5x5")
    b = emitter.render_og_card(5, 5, [3, 0], clues, "Incident #100",
                               "Lookout 5x5")
    c = emitter.render_og_card(5, 5, [3, 0], clues, "Incident #101",
                               "Lookout 5x5")
    assert a == b != c
    assert a[:8] == b"\x89PNG\r\n\x1a\n"


def test_og_bytes_identical_for_any_solution(emitted):
    # Two records sharing a board but with different solutions must render
    # the same card: the renderer input is the public board only.
    rec = curator.curate_daily(CFG, 0)
    board = engine.board_json(rec.pz)
    png1 = emitter.render_og_card(board["rows"], board["cols"],
                                  board["spark"], board["clues"],
                                  "Incident #100", "Lookout 5x5")
    rec.solution = {x: engine.OPEN for x in rec.pz.cells()}   # nonsense fill
    png2 = emitter.render_og_card(board["rows"], board["cols"],
                                  board["spark"], board["clues"],
                                  "Incident #100", "Lookout 5x5")
    assert png1 == png2
