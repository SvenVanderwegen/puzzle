"""Curation (WS-05): weekly-banded dailies + the 7-lesson academy pack.

Everything is a pure function of the seeds config — no wall clock, no
global RNG. Candidate boards for day *i*, attempt *a* come from seed
``master_seed + DAY_STRIDE * (i + 1) + a``; academy lesson *k* uses its own
stream. Re-running with the same seeds file reproduces the identical
content set, which is what makes the published-dates-immutable rule
enforceable downstream (re-sorts ship as a new content_version and may only
change future dates — see README).

Lesson operationalizations are documented in pipeline/GRADING.md §5.
"""

from dataclasses import dataclass, field
import datetime
import hashlib
import json

from . import engine, grader

DAY_STRIDE = 10_000_019
ACADEMY_BASE = 900_000_000
LESSON_STRIDE = 1_000_000
MAX_ATTEMPTS_DAILY = 200
MAX_ATTEMPTS_LESSON = 150


class CurationError(Exception):
    pass


@dataclass(frozen=True)
class SeedsConfig:
    master_seed: int
    start_date: datetime.date
    incident_base: int


def load_seeds(path):
    with open(path, "r", encoding="utf-8") as f:
        raw = json.load(f)
    return SeedsConfig(
        master_seed=int(raw["master_seed"]),
        start_date=datetime.date.fromisoformat(raw["start_date"]),
        incident_base=int(raw["incident_base"]),
    )


@dataclass
class CuratedPuzzle:
    pz: engine.Puzzle
    solution: dict = field(repr=False)
    grade: grader.Grade
    seed: int
    id: str = ""
    # dailies
    date: datetime.date = None
    incident: int = None
    band: grader.Band = None
    # academy
    lesson: int = None
    lesson_slug: str = None
    technique: str = None

    @property
    def player_tier(self):
        return grader.player_tier(self.pz.R, self.pz.C)

    @property
    def solution_bits(self):
        shaded = {x for x, s in self.solution.items() if s == engine.SHADED}
        return engine.shading_bits(self.pz.R, self.pz.C, shaded)

    @property
    def solution_sha256(self):
        return hashlib.sha256(self.solution_bits.encode("ascii")).hexdigest()


def _terrain_min_detour(threshold):
    def pred(spark, shaded, times):
        return any(t - engine.manhattan(spark, x) >= threshold
                   for x, t in times.items())
    return pred


# Cheap necessary conditions on the full time map, applied before the
# expensive clue-removal phase. The band predicate is still checked on the
# finished board; these only skip terrains that cannot possibly pass.
_TERRAIN_PREFILTER = {
    "thu": _terrain_min_detour(grader.THU_MIN_DETOUR),
    "sat": _terrain_min_detour(grader.SAT_MIN_DETOUR),
}


def curate_daily(cfg, day_index):
    """Curate the board for start_date + day_index. Deterministic."""
    date = cfg.start_date + datetime.timedelta(days=day_index)
    band = grader.BANDS[grader.WEEKDAY_NAMES[date.weekday()]]
    base = cfg.master_seed + DAY_STRIDE * (day_index + 1)
    prefilter = _TERRAIN_PREFILTER.get(band.weekday)
    for attempt in range(MAX_ATTEMPTS_DAILY):
        seed = base + attempt
        pz, solution, _times = engine.generate(
            band.rows, band.cols, band.n_breaks, seed=seed,
            min_clues=band.min_clues, terrain_predicate=prefilter)
        g = grader.grade(pz)
        if g is not None and g.solution == solution and grader.band_accepts(band, g):
            return CuratedPuzzle(
                pz=pz, solution=solution, grade=g, seed=seed, date=date,
                incident=cfg.incident_base + day_index, band=band)
    raise CurationError(
        f"no {band.label} board accepted for {date} "
        f"after {MAX_ATTEMPTS_DAILY} candidates")


def curate_dailies(cfg, days):
    return [curate_daily(cfg, i) for i in range(days)]


# ---------------------------------------------------------------------------
# Academy pack — 7 lessons, 2 practice boards each (product §5).
# ---------------------------------------------------------------------------

def _requires_kind(pz, kind):
    """True iff the board is deduction-solvable but stops being solvable
    when single-cell deductions justified by `kind` are taken away — the
    strict sense in which a board *requires* that argument."""
    reduced, _ = grader.tiered_deduction_steps(pz, grader.ALL_KINDS - {kind})
    return reduced is None


def _open_step_chain(g, min_minute=2):
    """Deepest count of open-steps citing one clue via
    clue_unreachable_in_time with minute >= min_minute (lesson 4)."""
    per_clue = {}
    for s in g.steps:
        r = s["reason"]
        if (s["state"] == "open" and r["kind"] == "clue_unreachable_in_time"
                and r["minute"] is not None and r["minute"] >= min_minute):
            key = tuple(r["clue"])
            per_clue[key] = per_clue.get(key, 0) + 1
    return max(per_clue.values(), default=0)


def _countfill_cascade(g):
    best = run = 0
    for s in g.steps:
        if s["reason"]["kind"] in ("all_breaks_placed", "rest_must_be_breaks"):
            run += 1
            best = max(best, run)
        else:
            run = 0
    return best


@dataclass(frozen=True)
class Lesson:
    number: int
    slug: str
    title: str
    rows: int
    cols: int
    n_breaks: int
    min_clues: int            # None = minimal
    technique: str            # pack.v1 technique tag; None = untagged
    terrain_prefilter: object = None


def _accept_l1(pz, g):
    # Rules walkthrough practice: gentle redundant-clue Lookout board.
    return g.rule_tier in ("A", "B")


def _accept_l2(pz, g):
    # Too Fast Means Walls: removing clue_reached_too_fast breaks the board.
    return ("clue_reached_too_fast" in g.techniques
            and _requires_kind(pz, "clue_reached_too_fast"))


def _accept_l3(pz, g):
    # Too Slow Means Roads: an open-step forced by clue_unreachable_in_time,
    # and the argument is load-bearing.
    has_open = any(s["state"] == "open"
                   and s["reason"]["kind"] == "clue_unreachable_in_time"
                   for s in g.steps)
    return has_open and _requires_kind(pz, "clue_unreachable_in_time")


def _accept_l4(pz, g):
    # Chains to the Spark: one clue forces a chain of >= 2 open cells via
    # clue_unreachable_in_time at minute > 1 (GRADING.md §5).
    return (_open_step_chain(g, min_minute=2) >= 2
            and _requires_kind(pz, "clue_unreachable_in_time"))


def _accept_l5_primary(pz, g):
    # Nothing Is Spared: the pocket argument proper.
    return "open_cell_unreachable" in g.techniques


def _accept_l5_fallback(pz, g):
    # Nearest exercised argument (GRADING.md §5): blocking would starve a
    # region — an open-step where the too-slow argument fires on a clue
    # whose only surviving route was about to be sealed.
    return _accept_l3(pz, g)


def _accept_l6(pz, g):
    # Counting the Endgame: a prominent count-fill cascade.
    return (_countfill_cascade(g) >= 6
            and any(s["reason"]["kind"] in ("all_breaks_placed",
                                            "rest_must_be_breaks")
                    for s in g.steps))


def _accept_l7(pz, g):
    # Capstone: a huge clue read the long way around — some clue's minute
    # exceeds its straight-line distance from the spark by >= 8.
    return g.detour >= 8


LESSONS = (
    Lesson(1, "first-shift", "First Shift", 5, 5, 4,
           grader.MON_MIN_CLUES, None),
    Lesson(2, "too-fast-means-walls", "Too Fast Means Walls", 5, 5, 4,
           None, "clue_reached_too_fast"),
    Lesson(3, "too-slow-means-roads", "Too Slow Means Roads", 5, 5, 4,
           None, "clue_unreachable_in_time"),
    Lesson(4, "chains-to-the-spark", "Chains to the Spark", 5, 5, 4,
           None, "clue_unreachable_in_time"),
    Lesson(5, "nothing-is-spared", "Nothing Is Spared", 6, 6, 8,
           None, "clue_unreachable_in_time"),
    Lesson(6, "counting-the-endgame", "Counting the Endgame", 5, 5, 4,
           None, "all_breaks_placed"),
    Lesson(7, "the-long-way-around", "The Long Way Around", 7, 7, 12,
           None, None, _terrain_min_detour(8)),
)

_LESSON_ACCEPT = {
    1: (_accept_l1,),
    2: (_accept_l2,),
    3: (_accept_l3,),
    4: (_accept_l4,),
    5: (_accept_l5_primary, _accept_l5_fallback),
    6: (_accept_l6,),
    7: (_accept_l7,),
}

PACK_ID = "academy-1"
PACK_TITLE = "The Academy — practice boards"
PACK_DESCRIPTION = ("Two practice boards per lesson. Each board is filtered "
                    "to require its lesson's argument. Academy boards are "
                    "unrated.")


def curate_lesson(cfg, lesson, per_lesson=2):
    """Two practice boards for one lesson. Acceptance predicates are tried
    in order over the same deterministic seed stream: if the primary
    predicate cannot fill the pair within budget (e.g. open_cell_unreachable
    is not producible — GRADING.md §5), the documented fallback re-scans
    the stream so both boards of the pair share one criterion."""
    base = cfg.master_seed + ACADEMY_BASE + LESSON_STRIDE * lesson.number
    for accept in _LESSON_ACCEPT[lesson.number]:
        found = []
        for attempt in range(MAX_ATTEMPTS_LESSON):
            if len(found) == per_lesson:
                break
            seed = base + attempt
            pz, solution, _times = engine.generate(
                lesson.rows, lesson.cols, lesson.n_breaks, seed=seed,
                min_clues=lesson.min_clues,
                terrain_predicate=lesson.terrain_prefilter)
            g = grader.grade(pz)
            if g is None or g.solution != solution:
                continue
            if accept(pz, g):
                found.append(CuratedPuzzle(
                    pz=pz, solution=solution, grade=g, seed=seed,
                    lesson=lesson.number, lesson_slug=lesson.slug,
                    technique=lesson.technique))
        if len(found) == per_lesson:
            return found
    raise CurationError(
        f"lesson {lesson.number} ({lesson.slug}): fewer than {per_lesson} "
        f"boards accepted after {MAX_ATTEMPTS_LESSON} candidates")


def curate_academy(cfg, per_lesson=2):
    records = []
    for lesson in LESSONS:
        records.extend(curate_lesson(cfg, lesson, per_lesson))
    return records


# ---------------------------------------------------------------------------
# Id assignment: bf1-{R}x{C}-{serial}, serial per size over the whole
# content set in curation order (dailies by date, then academy by lesson).
# ---------------------------------------------------------------------------

def assign_ids(records):
    counters = {}
    for rec in records:
        key = (rec.pz.R, rec.pz.C)
        counters[key] = counters.get(key, 0) + 1
        rec.id = f"bf1-{rec.pz.R}x{rec.pz.C}-{counters[key]:06d}"
    return records
