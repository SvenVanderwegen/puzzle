"""Distribution measurement for GRADING.md (WS-05).

Generates N boards per profile from sequential seeds, grades each with
grading v2, and writes one JSON object per board. The numbers quoted in
pipeline/GRADING.md come from these runs and are reproducible:

    python -m burnfront_pipeline.cli measure --profile 6x6-minimal \
        --count 200 --jobs 4 --out 6x6-minimal.jsonl

Multiprocessing is safe for determinism: each board depends only on its own
seed, and results are ordered by seed before writing.
"""

import json
from multiprocessing import Pool

from . import engine, grader

PROFILES = {
    "5x5-minimal": {"rows": 5, "cols": 5, "n_breaks": 4, "min_clues": None},
    "5x5-floor10": {"rows": 5, "cols": 5, "n_breaks": 4, "min_clues": 10},
    "6x6-minimal": {"rows": 6, "cols": 6, "n_breaks": 8, "min_clues": None},
    "7x7-minimal": {"rows": 7, "cols": 7, "n_breaks": 12, "min_clues": None},
    "8x8-floor28": {"rows": 8, "cols": 8, "n_breaks": 16, "min_clues": 28},
}


def measure_one(args):
    profile_name, seed = args
    p = PROFILES[profile_name]
    pz, solution, times = engine.generate(
        p["rows"], p["cols"], p["n_breaks"], seed=seed,
        min_clues=p["min_clues"])
    g = grader.grade(pz)
    kinds = sorted({s["reason"]["kind"] for s in g.steps})
    # longest run of consecutive count-fill steps (lesson 6 cascade)
    best = run = 0
    for s in g.steps:
        if s["reason"]["kind"] in ("all_breaks_placed", "rest_must_be_breaks"):
            run += 1
            best = max(best, run)
        else:
            run = 0
    # deepest same-clue chain of clue_unreachable_in_time open-steps (lesson 4)
    per_clue = {}
    for s in g.steps:
        r = s["reason"]
        if (s["state"] == "open" and r["kind"] == "clue_unreachable_in_time"
                and r["minute"] is not None and r["minute"] > 1):
            key = tuple(r["clue"])
            per_clue[key] = per_clue.get(key, 0) + 1
    chain_depth = max(per_clue.values(), default=0)
    return {
        "profile": profile_name, "seed": seed,
        "n_clues": g.n_clues, "score": g.score, "rule_tier": g.rule_tier,
        "detour": g.detour, "kinds": kinds,
        "countfill_cascade": best, "same_clue_chain": chain_depth,
    }


def run_measure(profile_name, count, jobs, out_path):
    tasks = [(profile_name, seed) for seed in range(count)]
    if jobs > 1:
        with Pool(jobs) as pool:
            rows = pool.map(measure_one, tasks)
    else:
        rows = [measure_one(t) for t in tasks]
    rows.sort(key=lambda r: r["seed"])
    with open(out_path, "w") as f:
        for r in rows:
            f.write(json.dumps(r, sort_keys=True) + "\n")
    return rows


def summarize(rows):
    """Compact summary dict for one profile's measurement rows."""
    n = len(rows)

    def dist(key):
        vals = {}
        for r in rows:
            vals[r[key]] = vals.get(r[key], 0) + 1
        return dict(sorted(vals.items()))

    def pct_with_kind(kind):
        return sum(1 for r in rows if kind in r["kinds"]) * 100.0 / n

    scores = sorted(r["score"] for r in rows)

    def pctile(p):
        return scores[min(n - 1, int(p * n))]

    return {
        "n": n,
        "rule_tier": dist("rule_tier"),
        "score_min_med_max": [scores[0], pctile(0.5), scores[-1]],
        "score_p10_p25_p75_p90": [pctile(0.10), pctile(0.25),
                                  pctile(0.75), pctile(0.90)],
        "detour": dist("detour"),
        "kind_presence_pct": {
            k: round(pct_with_kind(k), 1)
            for k in ("clue_reached_too_fast", "clue_unreachable_in_time",
                      "too_many_breaks", "open_cell_unreachable",
                      "not_enough_breaks_left", "all_breaks_placed",
                      "rest_must_be_breaks")},
        "countfill_cascade": dist("countfill_cascade"),
        "same_clue_chain": dist("same_clue_chain"),
    }
