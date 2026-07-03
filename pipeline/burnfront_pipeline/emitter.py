"""Emit (WS-05): dist/content/{content_version}/ per contracts/schemas/.

Layout:
    {version}/calendar.json (+ .sig)     burnfront.calendar/1
    {version}/packs/academy-1.json (+ .sig)  burnfront.pack/1
    {version}/puzzles/{id}.json          burnfront.puzzle/1
    {version}/og/{id}.png                spoiler-free OG card
    {version}/puzzles.csv                DB seed (WS-07 importer)

Every puzzle is re-verified against the three fairness guarantees (unique,
guess-free, every break witnessed) immediately before writing; any failure
raises RefusalError and nothing ships. The emitter never reads the clock —
the date is an input, so the same inputs produce a byte-identical tree.
"""

import csv
import hashlib
import io
import json
from pathlib import Path

from jsonschema import Draft202012Validator, FormatChecker
from PIL import Image, ImageDraw, ImageFont

from . import GEN_VERSION, RULES_VERSION, engine, signer

REPO_ROOT = Path(__file__).resolve().parents[2]
SCHEMA_DIR = REPO_ROOT / "contracts" / "schemas"
TOKENS_PATH = REPO_ROOT / "contracts" / "design-tokens.json"


class RefusalError(Exception):
    """A board failed re-verification; the emit must abort."""


def canonical_json_bytes(obj):
    return (json.dumps(obj, sort_keys=True, separators=(",", ":")) + "\n").encode("ascii")


def sha256_hex(data):
    return hashlib.sha256(data).hexdigest()


def _load_schema(name):
    with open(SCHEMA_DIR / name, "r", encoding="utf-8") as f:
        schema = json.load(f)
    return Draft202012Validator(schema, format_checker=FormatChecker())


def _validate(validator, instance, what):
    errors = sorted(validator.iter_errors(instance), key=lambda e: list(e.path))
    if errors:
        first = errors[0]
        raise RefusalError(f"{what} does not validate: {first.message}")


# ---------------------------------------------------------------------------
# Certificate re-verification (the three fairness guarantees).
# ---------------------------------------------------------------------------

def verify_record(rec):
    """Re-verify a curated puzzle before it is allowed to ship. Raises
    RefusalError naming the first violated guarantee."""
    pz = rec.pz
    shaded = {x for x, s in rec.solution.items() if s == engine.SHADED}
    ok, reason = engine.burn_verdict(pz.R, pz.C, pz.spark, pz.clues,
                                     pz.n_breaks, shaded)
    if not ok:
        raise RefusalError(
            f"refusing to emit {rec.id or rec.seed}: solution invalid ({reason})")
    if engine.count_solutions(pz, limit=2) != 1:
        raise RefusalError(
            f"refusing to emit {rec.id or rec.seed}: solution is not unique")
    if engine.deduction_solve(pz) != rec.solution:
        raise RefusalError(
            f"refusing to emit {rec.id or rec.seed}: not solvable by deduction")
    if not engine.breaks_witnessed(pz.R, pz.C, pz.spark, shaded, pz.clues):
        raise RefusalError(
            f"refusing to emit {rec.id or rec.seed}: unwitnessed firebreak")


def puzzle_document(rec):
    return {
        "schema": "burnfront.puzzle/1",
        "id": rec.id,
        "engine": {"gen_version": GEN_VERSION, "rules_version": RULES_VERSION},
        "board": engine.board_json(rec.pz),
        "grade": {
            "tier": rec.player_tier,
            "score": rec.grade.score,
            "techniques": rec.grade.techniques,
        },
        "certificates": {
            "unique": True,
            "deduction_steps": rec.grade.score,
            "witnessed": True,
        },
        "solution_sha256": rec.solution_sha256,
    }


# ---------------------------------------------------------------------------
# OG cards. Drawn ONLY from public board data (rows/cols/spark/clues) plus a
# heading — the function cannot leak the solution because it never sees it.
# ---------------------------------------------------------------------------

OG_W, OG_H = 1200, 630


def _tokens():
    with open(TOKENS_PATH, "r", encoding="utf-8") as f:
        raw = json.load(f)
    return {name: entry["value"] for name, entry in raw["color"].items()}


def render_og_card(rows, cols, spark, clues, heading, subheading):
    """Spoiler-free OG PNG: the unsolved clue grid, the spark, the heading
    (incident number or pack line) and tier text. Returns PNG bytes."""
    col = _tokens()
    img = Image.new("RGB", (OG_W, OG_H), col["soot"])
    d = ImageDraw.Draw(img)

    # grid on the left
    pad = 60
    grid_side = OG_H - 2 * pad
    cell = grid_side // max(rows, cols)
    gx = pad
    gy = (OG_H - cell * rows) // 2
    clue_map = {(c["r"], c["c"]): c["m"] for c in clues}
    clue_font = ImageFont.load_default(size=max(16, int(cell * 0.42)))
    for r in range(rows):
        for c in range(cols):
            x0, y0 = gx + c * cell, gy + r * cell
            x1, y1 = x0 + cell - 2, y0 + cell - 2
            is_clue = (r, c) in clue_map
            fill = col["char2"] if is_clue else col["char"]
            d.rectangle([x0, y0, x1, y1], fill=fill, outline=col["line"])
            if (r, c) == tuple(spark):
                cx, cy = (x0 + x1) / 2, (y0 + y1) / 2
                s = cell * 0.30
                star = [(cx, cy - s), (cx + s * 0.35, cy - s * 0.35),
                        (cx + s, cy), (cx + s * 0.35, cy + s * 0.35),
                        (cx, cy + s), (cx - s * 0.35, cy + s * 0.35),
                        (cx - s, cy), (cx - s * 0.35, cy - s * 0.35)]
                d.polygon(star, fill=col["flame"])
            elif is_clue:
                d.text(((x0 + x1) / 2, (y0 + y1) / 2), str(clue_map[(r, c)]),
                       font=clue_font, fill=col["paper"], anchor="mm")

    # text block on the right
    tx = gx + cell * cols + 70
    d.text((tx, 200), "BURNFRONT", font=ImageFont.load_default(size=34),
           fill=col["ashDim"])
    d.text((tx, 260), heading, font=ImageFont.load_default(size=72),
           fill=col["paper"])
    d.text((tx, 360), subheading, font=ImageFont.load_default(size=44),
           fill=col["ember"])

    buf = io.BytesIO()
    img.save(buf, format="PNG")
    return buf.getvalue()


def _og_bytes_for(rec):
    board = engine.board_json(rec.pz)
    size_text = f"{rec.player_tier.capitalize()} {rec.pz.R}x{rec.pz.C}"
    if rec.incident is not None:
        heading = f"Incident #{rec.incident}"
    else:
        heading = "The Academy"
        size_text = f"Lesson {rec.lesson} · {size_text}"
    return render_og_card(board["rows"], board["cols"], board["spark"],
                          board["clues"], heading, size_text)


# ---------------------------------------------------------------------------
# The emit itself.
# ---------------------------------------------------------------------------

def emit_content(out_root, content_version, dailies, academy, signing_key):
    """Write one complete content version directory. `dailies` and
    `academy` are curated, id-assigned records (academy may be empty).
    Returns the version directory path."""
    puzzle_v = _load_schema("puzzle.v1.json")
    calendar_v = _load_schema("calendar.v1.json")
    pack_v = _load_schema("pack.v1.json")

    version_dir = Path(out_root) / content_version
    puzzles_dir = version_dir / "puzzles"
    og_dir = version_dir / "og"
    packs_dir = version_dir / "packs"
    for p in (puzzles_dir, og_dir, packs_dir):
        p.mkdir(parents=True, exist_ok=True)

    files = {}          # relpath -> sha256, chained under the calendar sig
    all_records = list(dailies) + list(academy)

    seen = set()
    for rec in all_records:
        if not rec.id or rec.id in seen:
            raise RefusalError(f"duplicate or missing puzzle id {rec.id!r}")
        seen.add(rec.id)
        verify_record(rec)
        doc = puzzle_document(rec)
        _validate(puzzle_v, doc, f"puzzle {rec.id}")
        data = canonical_json_bytes(doc)
        rel = f"puzzles/{rec.id}.json"
        (version_dir / rel).write_bytes(data)
        files[rel] = sha256_hex(data)
        png = _og_bytes_for(rec)
        og_rel = f"og/{rec.id}.png"
        (version_dir / og_rel).write_bytes(png)
        files[og_rel] = sha256_hex(png)

    # puzzles.csv — DB seed
    buf = io.StringIO()
    w = csv.writer(buf, lineterminator="\n")
    w.writerow(["id", "rows", "cols", "n_breaks", "grade_tier", "grade_score",
                "solution_sha256", "gen_version", "content_version", "pack_id"])
    for rec in all_records:
        w.writerow([rec.id, rec.pz.R, rec.pz.C, rec.pz.n_breaks,
                    rec.player_tier, rec.grade.score, rec.solution_sha256,
                    GEN_VERSION, content_version,
                    "" if rec.lesson is None else "academy-1"])
    csv_bytes = buf.getvalue().encode("ascii")
    (version_dir / "puzzles.csv").write_bytes(csv_bytes)
    files["puzzles.csv"] = sha256_hex(csv_bytes)

    # pack manifest (signed)
    if academy:
        pack_doc = {
            "schema": "burnfront.pack/1",
            "id": "academy-1",
            "title": "The Academy — practice boards",
            "description": ("Two practice boards per lesson, each filtered "
                            "to require its lesson's argument. Academy "
                            "boards are unrated."),
            "puzzles": [],
        }
        for rec in academy:
            entry = {
                "id": rec.id,
                "file": f"../puzzles/{rec.id}.json",
                "sha256": files[f"puzzles/{rec.id}.json"],
            }
            if rec.technique is not None:
                entry["technique"] = rec.technique
            pack_doc["puzzles"].append(entry)
        _validate(pack_v, pack_doc, "pack academy-1")
        pack_bytes = canonical_json_bytes(pack_doc)
        pack_path = packs_dir / "academy-1.json"
        pack_path.write_bytes(pack_bytes)
        files["packs/academy-1.json"] = sha256_hex(pack_bytes)
        signer.sign_manifest(pack_path, signing_key)

    # calendar manifest (signed) — the root of the trust chain
    calendar_doc = {
        "schema": "burnfront.calendar/1",
        "content_version": content_version,
        "from": dailies[0].date.isoformat(),
        "to": dailies[-1].date.isoformat(),
        "days": [{"date": rec.date.isoformat(), "puzzle": rec.id,
                  "grade_tier": rec.player_tier} for rec in dailies],
        "files": dict(sorted(files.items())),
    }
    _validate(calendar_v, calendar_doc, "calendar")
    calendar_path = version_dir / "calendar.json"
    calendar_path.write_bytes(canonical_json_bytes(calendar_doc))
    signer.sign_manifest(calendar_path, signing_key)

    return version_dir


# ---------------------------------------------------------------------------
# Verify step: walk the chain signature -> manifest sha256 map -> files.
# ---------------------------------------------------------------------------

def verify_content(version_dir, verify_key):
    """Verify a content version directory end to end. Returns the list of
    verified relative paths; raises RefusalError on the first failure."""
    version_dir = Path(version_dir)
    calendar_path = version_dir / "calendar.json"
    if not signer.verify_manifest(calendar_path, verify_key):
        raise RefusalError("calendar.json signature does not verify")
    calendar_doc = json.loads(calendar_path.read_bytes())
    _validate(_load_schema("calendar.v1.json"), calendar_doc, "calendar")

    verified = ["calendar.json"]
    for rel, expected in calendar_doc["files"].items():
        data = (version_dir / rel).read_bytes()
        if sha256_hex(data) != expected:
            raise RefusalError(f"{rel}: sha256 mismatch")
        verified.append(rel)

    puzzle_v = _load_schema("puzzle.v1.json")
    for rel in calendar_doc["files"]:
        if rel.startswith("puzzles/") and rel.endswith(".json"):
            _validate(puzzle_v, json.loads((version_dir / rel).read_bytes()),
                      rel)

    pack_v = _load_schema("pack.v1.json")
    for rel in list(calendar_doc["files"]):
        if rel.startswith("packs/") and rel.endswith(".json"):
            pack_path = version_dir / rel
            if not signer.verify_manifest(pack_path, verify_key):
                raise RefusalError(f"{rel} signature does not verify")
            pack_doc = json.loads(pack_path.read_bytes())
            _validate(pack_v, pack_doc, rel)
            for entry in pack_doc["puzzles"]:
                data = (pack_path.parent / entry["file"]).read_bytes()
                if sha256_hex(data) != entry["sha256"]:
                    raise RefusalError(f"pack entry {entry['id']}: sha256 mismatch")
    return verified
