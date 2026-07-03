"""The committed content sample: exactly 7 days of dailies + the academy
pack, emitted with the DEV key from seeds-sample.json (brief: fixtures are
7 days, never 60/90). The slow marker rebuilds it from scratch and proves
byte-identity."""

import hashlib
import json
import subprocess
import sys
from pathlib import Path

import pytest

from burnfront_pipeline import emitter, signer

from conftest import DEV_KEY, DEV_PUB, FIXTURES

SAMPLE_DIR = FIXTURES / "content-sample" / "v20260706-1"
SEEDS_SAMPLE = FIXTURES / "seeds-sample.json"

# product §5 weekly table: Mon..Sun tiers as emitted (Sun 8x8 plays as crew)
EXPECTED_TIERS = ["lookout", "lookout", "crew", "crew", "crew", "hotshot",
                  "crew"]
EXPECTED_SIZES = [(5, 5), (5, 5), (6, 6), (6, 6), (6, 6), (7, 7), (8, 8)]

LESSON_TECHNIQUES = {
    1: None,
    2: "clue_reached_too_fast",
    3: "clue_unreachable_in_time",
    4: "clue_unreachable_in_time",
    5: "clue_unreachable_in_time",
    6: "all_breaks_placed",
    7: None,
}


def _calendar():
    return json.loads((SAMPLE_DIR / "calendar.json").read_bytes())


def _pack():
    return json.loads((SAMPLE_DIR / "packs" / "academy-1.json").read_bytes())


def test_sample_verifies_end_to_end():
    vk = signer.load_verify_key(str(DEV_PUB))
    verified = emitter.verify_content(SAMPLE_DIR, vk)
    assert "calendar.json" in verified


def test_sample_is_exactly_seven_days():
    doc = _calendar()
    assert len(doc["days"]) == 7
    assert doc["from"] == "2026-07-06" and doc["to"] == "2026-07-12"
    assert [d["grade_tier"] for d in doc["days"]] == EXPECTED_TIERS


def test_sample_days_follow_the_weekly_band_table():
    doc = _calendar()
    for day, (rows, cols) in zip(doc["days"], EXPECTED_SIZES):
        puzzle = json.loads(
            (SAMPLE_DIR / "puzzles" / f"{day['puzzle']}.json").read_bytes())
        board = puzzle["board"]
        assert (board["rows"], board["cols"]) == (rows, cols), day["date"]
        assert puzzle["grade"]["tier"] == day["grade_tier"]
        assert puzzle["certificates"] == {
            "unique": True, "witnessed": True,
            "deduction_steps": puzzle["grade"]["score"]}
    # redundant-clue bands carry their clue floors
    mon = json.loads((SAMPLE_DIR / "puzzles"
                      / f"{doc['days'][0]['puzzle']}.json").read_bytes())
    assert len(mon["board"]["clues"]) >= 10
    sun = json.loads((SAMPLE_DIR / "puzzles"
                      / f"{doc['days'][6]['puzzle']}.json").read_bytes())
    assert len(sun["board"]["clues"]) >= 28


def test_sample_pack_covers_all_seven_lessons():
    pack = _pack()
    assert pack["id"] == "academy-1"
    assert len(pack["puzzles"]) == 14
    for i, entry in enumerate(pack["puzzles"]):
        lesson = i // 2 + 1
        expected = LESSON_TECHNIQUES[lesson]
        assert entry.get("technique") == expected, f"lesson {lesson}"
        target = (SAMPLE_DIR / "packs" / entry["file"]).resolve()
        assert target.is_file()
        assert hashlib.sha256(target.read_bytes()).hexdigest() == entry["sha256"]
    # capstone boards are 7x7
    for entry in pack["puzzles"][12:]:
        assert entry["id"].startswith("bf1-7x7-")


def test_sample_csv_seeds_all_puzzles():
    lines = (SAMPLE_DIR / "puzzles.csv").read_text().splitlines()
    assert len(lines) == 1 + 7 + 14
    header = lines[0].split(",")
    assert header == ["id", "rows", "cols", "n_breaks", "grade_tier",
                      "grade_score", "solution_sha256", "gen_version",
                      "content_version", "pack_id"]
    pack_ids = {row.split(",")[9] for row in lines[1:]}
    assert pack_ids == {"", "academy-1"}
    versions = {row.split(",")[8] for row in lines[1:]}
    assert versions == {"v20260706-1"}


def test_sample_every_puzzle_has_a_spoiler_free_og_card():
    doc = _calendar()
    ids = [d["puzzle"] for d in doc["days"]]
    ids += [e["id"] for e in _pack()["puzzles"]]
    for pid in ids:
        png = SAMPLE_DIR / "og" / f"{pid}.png"
        assert png.is_file(), pid
        assert png.read_bytes()[:8] == b"\x89PNG\r\n\x1a\n"


@pytest.mark.slow
def test_sample_regenerates_byte_identical(tmp_path):
    """Full rebuild of the committed sample (minutes of CPU)."""
    rc = subprocess.run(
        [sys.executable, "-m", "burnfront_pipeline.cli", "emit",
         "--date", "20260706", "--seq", "1", "--days", "7",
         "--seeds", str(SEEDS_SAMPLE), "--out", str(tmp_path),
         "--key", str(DEV_KEY)],
        cwd=Path(__file__).resolve().parents[1]).returncode
    assert rc == 0
    rebuilt = tmp_path / "v20260706-1"
    committed = {p.relative_to(SAMPLE_DIR): p for p in SAMPLE_DIR.rglob("*")
                 if p.is_file()}
    fresh = {p.relative_to(rebuilt): p for p in rebuilt.rglob("*")
             if p.is_file()}
    assert set(committed) == set(fresh)
    for rel, path in committed.items():
        assert path.read_bytes() == fresh[rel].read_bytes(), rel
