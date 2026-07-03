"""Burnfront content pipeline (WS-05): generate, grade v2, curate, sign, emit.

The behavioral authority for the puzzle genre is reference/firebreak.py plus
contracts/vectors/. engine.py is a copy of the reference algorithms; the
vector tests in tests/test_engine_vectors.py keep the copy honest.
"""

GEN_VERSION = "py-pipeline-ws05-0.1.0"
RULES_VERSION = 1
