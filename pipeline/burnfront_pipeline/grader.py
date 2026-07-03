"""Grading v2 — tiered deduction rule sets (WS-05).

A board's grade is the pair ``(rule tier, chain length)``:

* **Tier A** — count-fills only (``all_breaks_placed`` /
  ``rest_must_be_breaks`` bulk fills). No single-cell tests.
* **Tier B** — tier A plus single-cell deductions justified by
  ``clue_reached_too_fast`` ("too fast means walls").
* **Tier C** — full single-cell feasibility, i.e. the reference solver
  (adds ``clue_unreachable_in_time``, ``open_cell_unreachable`` and the
  counting singletons ``too_many_breaks`` / ``not_enough_breaks_left``).

The rule tier is the *lowest* tier whose solver finishes the board.
``grade_score`` is the deduction-chain length. Because every solving tier
assigns each initially-unknown cell exactly once, the chain length equals
``rows*cols - 1 - n_clues`` and is identical across tiers — the score keys
on clue sparsity, the tier keys on the reasoning required. RATING.md §2
consumes ``grade_score`` unchanged (board_rating = base(tier) + 4 * score).

Thresholds for the weekly bands are documented with measured distributions
in pipeline/GRADING.md.
"""

from dataclasses import dataclass, field

from . import engine

ALL_KINDS = frozenset({
    "too_many_breaks", "not_enough_breaks_left", "clue_unreachable_in_time",
    "open_cell_unreachable", "clue_reached_too_fast",
})

RULE_TIERS = ("A", "B", "C")

TIER_SINGLETON_KINDS = {
    "A": frozenset(),
    "B": frozenset({"clue_reached_too_fast"}),
    "C": ALL_KINDS,
}


def tiered_deduction_steps(pz, allowed_kinds):
    """Reference deduction solver restricted to a rule set: a single-cell
    deduction is only *used* when the violated assumption's reason kind is
    in ``allowed_kinds``. Count-fills are always available (they open every
    pass, as in the reference). With ``allowed_kinds == ALL_KINDS`` this is
    step-for-step identical to ``engine.deduction_steps`` (vector-checked).

    Returns (steps, state); (None, None) when the rule set gets stuck or
    the board is contradictory.
    """
    state = engine.initial_state(pz)
    steps = []
    progress = True
    while progress:
        progress = False
        n_shaded = sum(1 for v in state.values() if v == engine.SHADED)
        n_unknown = sum(1 for v in state.values() if v == engine.UNKNOWN)
        if n_unknown == 0:
            break
        if n_shaded == pz.n_breaks:
            for x in pz.cells():
                if state[x] == engine.UNKNOWN:
                    state[x] = engine.OPEN
                    steps.append({"cell": list(x), "state": "open",
                                  "reason": {"kind": "all_breaks_placed",
                                             "clue": None, "minute": None}})
            break
        if n_shaded + n_unknown == pz.n_breaks:
            for x in pz.cells():
                if state[x] == engine.UNKNOWN:
                    state[x] = engine.SHADED
                    steps.append({"cell": list(x), "state": "break",
                                  "reason": {"kind": "rest_must_be_breaks",
                                             "clue": None, "minute": None}})
            break
        for x in pz.cells():
            if state[x] != engine.UNKNOWN:
                continue
            state[x] = engine.OPEN
            ok_open, _ = engine.feasible(pz, state)
            why_open = None if ok_open else engine.first_violation(pz, state)
            state[x] = engine.SHADED
            ok_shaded, _ = engine.feasible(pz, state)
            why_shaded = None if ok_shaded else engine.first_violation(pz, state)
            state[x] = engine.UNKNOWN
            if not ok_open and not ok_shaded:
                return None, None
            if not ok_open and why_open["kind"] in allowed_kinds:
                state[x] = engine.SHADED
                steps.append({"cell": list(x), "state": "break",
                              "reason": why_open})
                progress = True
            elif ok_open and not ok_shaded and why_shaded["kind"] in allowed_kinds:
                state[x] = engine.OPEN
                steps.append({"cell": list(x), "state": "open",
                              "reason": why_shaded})
                progress = True
    if any(s == engine.UNKNOWN for s in state.values()):
        return None, None
    if not engine.exact_check(pz, state):
        return None, None
    return steps, state


@dataclass
class Grade:
    """Grading-v2 result for one board."""
    rule_tier: str            # lowest solving rule tier: "A" | "B" | "C"
    score: int                # deduction-chain length (== certificate steps)
    steps: list               # tier-C certified step list (Coach fuel)
    techniques: list          # sorted reason kinds appearing in `steps`
    detour: int               # max over clues of (minute - manhattan(spark))
    n_clues: int
    solution: dict = field(repr=False, default=None)


def grade(pz):
    """Grade a board. Returns a Grade, or None if the board is not
    deduction-solvable at all (such a board must never be emitted)."""
    c_steps, c_state = engine.deduction_steps(pz)
    if c_steps is None or not engine.exact_check(pz, c_state):
        return None
    rule_tier = "C"
    for tier in ("A", "B"):
        steps, state = tiered_deduction_steps(pz, TIER_SINGLETON_KINDS[tier])
        if steps is not None:
            rule_tier = tier
            break
    techniques = sorted({s["reason"]["kind"] for s in c_steps})
    detour = max(
        (v - engine.manhattan(pz.spark, cell) for cell, v in pz.clues.items()),
        default=0)
    return Grade(rule_tier=rule_tier, score=len(c_steps), steps=c_steps,
                 techniques=techniques, detour=detour,
                 n_clues=len(pz.clues), solution=c_state)


def player_tier(rows, cols):
    """Player-facing tier from the product §5 table: 5x5 Lookout, 6x6 Crew,
    7x7 Hotshot, 8x8 Sunday Burn plays as Crew (big, mid difficulty)."""
    size = max(rows, cols)
    if size <= 5:
        return "lookout"
    if size == 6:
        return "crew"
    if size == 7:
        return "hotshot"
    return "crew"


# ---------------------------------------------------------------------------
# Weekly bands (product §5). Thresholds are set from the measured
# distributions in pipeline/GRADING.md; change them there and here together.
# ---------------------------------------------------------------------------

# Clue floors for the redundant-clue bands (see GRADING.md §3).
MON_MIN_CLUES = 10        # 5x5: score fixed at 14, tier almost always A/B
SUN_MIN_CLUES = 28        # 8x8: score fixed at 35, generation stays fast

# Minimal-band score thresholds (score = R*C - 1 - n_clues).
TUE_MIN_SCORE = 17        # 5x5 minimal: reject the over-clued easy tail
WED_SCORE_RANGE = (25, 28)  # 6x6 minimal: the fat middle of the distribution
THU_MIN_DETOUR = 10       # 6x6 minimal: upper ~40% detour depth
FRI_MIN_SCORE = 28        # 6x6 minimal: sparse-clue hard band
SAT_MIN_DETOUR = 8        # 7x7 minimal: the summit keeps a deep detour


@dataclass(frozen=True)
class Band:
    weekday: str              # "mon".."sun"
    rows: int
    cols: int
    n_breaks: int
    min_clues: int            # None = minimal (remove to irredundancy)
    label: str


BANDS = {
    "mon": Band("mon", 5, 5, 4, MON_MIN_CLUES, "Lookout 5x5, redundant clues"),
    "tue": Band("tue", 5, 5, 4, None, "Lookout 5x5, minimal clues"),
    "wed": Band("wed", 6, 6, 8, None, "Crew 6x6"),
    "thu": Band("thu", 6, 6, 8, None, "Crew 6x6, deeper detours"),
    "fri": Band("fri", 6, 6, 8, None, "Crew 6x6, minimal clues"),
    "sat": Band("sat", 7, 7, 12, None, "Hotshot 7x7, minimal clues"),
    "sun": Band("sun", 8, 8, 16, SUN_MIN_CLUES, "Sunday Burn 8x8, redundant clues"),
}

WEEKDAY_NAMES = ("mon", "tue", "wed", "thu", "fri", "sat", "sun")


def band_accepts(band, g):
    """Acceptance predicate per weekday (GRADING.md §4)."""
    if g is None:
        return False
    day = band.weekday
    if day == "mon":
        return g.rule_tier in ("A", "B") and g.score <= 25 - 1 - MON_MIN_CLUES
    if day == "tue":
        return g.score >= TUE_MIN_SCORE
    if day == "wed":
        return WED_SCORE_RANGE[0] <= g.score <= WED_SCORE_RANGE[1]
    if day == "thu":
        return g.detour >= THU_MIN_DETOUR
    if day == "fri":
        return g.rule_tier == "C" and g.score >= FRI_MIN_SCORE
    if day == "sat":
        return g.rule_tier == "C" and g.detour >= SAT_MIN_DETOUR
    if day == "sun":
        return g.score <= 64 - 1 - SUN_MIN_CLUES
    raise ValueError(f"unknown weekday {day!r}")
