"""
Incident names for the print book: a Python port of the word lists in
app/Support/Burnfront/IncidentNamer.php, so the book labels its puzzles
the same way the game labels its boards ("Coldwater Fire -- lightning
strike, contained on day 3"). Cosmetic only -- never touches puzzle
generation.

Assignment is deterministic for a given book seed and puzzle order, and
place words are dealt from a seeded shuffle so no two puzzles in a book
share one until the pool (20 places x 2 designations = 40 combos, exactly
a default 40-puzzle book) is exhausted.
"""

import random

PLACE_WORDS = [
    "Coldwater", "Deadhorse", "Widow Creek", "Six Mile", "Chalk Bluff",
    "Blackrock", "Dry Wash", "Tinder Ridge", "Salt Fork", "Hollow Point",
    "Cinder Pass", "Ashfall", "Rimrock", "Split Timber", "Quail Canyon",
    "Bitter Springs", "Redshale", "Windrow", "Stonebreak", "Ember Flat",
]

DESIGNATIONS = ["Fire", "Complex"]

BLURBS = [
    "Red flag wind warning, ignition unconfirmed.",
    "Lightning strike, contained on day 3.",
    "Escaped agricultural burn, wind-driven.",
    "Dry lightning bust, multiple starts merged.",
    "Power line fault, confirmed by line crew.",
    "Human-caused, under investigation.",
    "Holdover from a prior season, reburned.",
    "Spot fire jumped the initial line overnight.",
    "Slash pile escape, low humidity that week.",
    "Cause undetermined, case remains open.",
]


def assign_incidents(count, book_seed):
    """Return `count` unique-until-exhausted {name, blurb} dicts,
    deterministic in `book_seed`."""
    rng = random.Random(book_seed)
    combos = [(p, d) for d in DESIGNATIONS for p in PLACE_WORDS]
    rng.shuffle(combos)
    out = []
    for i in range(count):
        place, designation = combos[i % len(combos)]
        out.append({
            "name": f"{place} {designation}",
            "blurb": rng.choice(BLURBS),
        })
    return out
