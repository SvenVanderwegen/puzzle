"""
Batch puzzle generation with a disk cache, so re-running the PDF layout
(tweaking margins, fonts, page count) never re-pays the generator's cost.
7x7/12-break puzzles take ~60-90s each with the pure-Python reference
solver, so a book's worth of "hard" puzzles is run in parallel processes
and cached to JSON.
"""

import concurrent.futures
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import firebreak as fb

CACHE_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "puzzle_cache.json")


def _generate_one(args):
    R, C, N, seed = args
    pz, solution, times = fb.generate(R, C, N, seed=seed)
    return {
        "R": R, "C": C, "N": N, "seed": seed,
        "spark": list(pz.spark),
        "clues": [[list(cell), v] for cell, v in pz.clues.items()],
        "solution": [[list(cell), v] for cell, v in solution.items()],
        "times": [[list(cell), v] for cell, v in times.items()],
    }


def _key(R, C, N, seed):
    return f"{R}x{C}x{N}#{seed}"


def _load_cache():
    if os.path.exists(CACHE_PATH):
        with open(CACHE_PATH) as f:
            return json.load(f)
    return {}


def _save_cache(cache):
    with open(CACHE_PATH, "w") as f:
        json.dump(cache, f)


def _decode(entry):
    entry = dict(entry)
    entry["spark"] = tuple(entry["spark"])
    entry["clues"] = {tuple(cell): v for cell, v in entry["clues"]}
    entry["solution"] = {tuple(cell): v for cell, v in entry["solution"]}
    entry["times"] = {tuple(cell): v for cell, v in entry["times"]}
    return entry


def generate_batch(specs, workers=None, progress=None):
    """specs: list of (R, C, N, seed) tuples. Returns a list of decoded
    puzzle dicts in the same order, using/populating the on-disk cache."""
    cache = _load_cache()
    results = [None] * len(specs)
    todo = []
    for i, (R, C, N, seed) in enumerate(specs):
        k = _key(R, C, N, seed)
        if k in cache:
            results[i] = _decode(cache[k])
        else:
            todo.append(i)

    if todo:
        workers = workers or min(len(todo), os.cpu_count() or 4)
        done = 0
        with concurrent.futures.ProcessPoolExecutor(max_workers=workers) as ex:
            futures = {ex.submit(_generate_one, specs[i]): i for i in todo}
            for fut in concurrent.futures.as_completed(futures):
                i = futures[fut]
                entry = fut.result()
                R, C, N, seed = specs[i]
                cache[_key(R, C, N, seed)] = entry
                results[i] = _decode(entry)
                done += 1
                if progress:
                    progress(done, len(todo), specs[i])
        _save_cache(cache)

    return results
