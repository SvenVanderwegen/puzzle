import json
import pathlib

import pytest

from burnfront_pipeline import engine

REPO = pathlib.Path(__file__).resolve().parents[2]
VECTORS = REPO / "contracts" / "vectors"
FIXTURES = pathlib.Path(__file__).resolve().parent / "fixtures"
DEV_KEY = FIXTURES / "dev-signing-key"
DEV_PUB = FIXTURES / "dev-signing-key.pub"


def load_jsonl(name):
    with open(VECTORS / name, "r", encoding="utf-8") as f:
        return [json.loads(line) for line in f]


# Vector-parity subset: all 3x3 and 4x4 boards plus the first five 5x5 and
# the first 6x6 (seed plan order pinned by the reference emitter). The full
# 50-board replay lives in the slow marker (test_fixture_sample.py runs the
# whole content build; CI's vectors-fresh job replays the reference itself).
PARITY_IDS = tuple(
    [f"gen-{i:04d}" for i in range(14)]        # 3x3 x6, 4x4 x8
    + [f"gen-{i:04d}" for i in range(14, 19)]  # 5x5 seeds 0..4
    + ["gen-0034"]                             # 6x6 seed 0
)


@pytest.fixture(scope="session")
def generate_vectors():
    return {v["id"]: v for v in load_jsonl("generate.v1.jsonl")}


@pytest.fixture(scope="session")
def deduction_vectors():
    return {v["id"]: v for v in load_jsonl("deduction.v1.jsonl")}


@pytest.fixture(scope="session")
def burn_vectors():
    return load_jsonl("burn.v1.jsonl")


@pytest.fixture(scope="session")
def parity_boards(generate_vectors):
    """Regenerate the parity subset with the pipeline engine."""
    boards = {}
    for gid in PARITY_IDS:
        v = generate_vectors[gid]
        pz, solution, times = engine.generate(
            v["rows"], v["cols"], v["breaks"], seed=v["seed"])
        boards[gid] = (v, pz, solution, times)
    return boards
