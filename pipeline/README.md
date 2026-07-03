# pipeline/ — Burnfront content pipeline (WS-05)

`generate -> grade v2 -> curate -> sign -> emit`. Python 3.11+.

The behavioral authority is `reference/firebreak.py` (frozen) together with
`contracts/vectors/`. `burnfront_pipeline/engine.py` is a copy of the
reference algorithms; `tests/test_engine_vectors.py` replays the vectors to
keep the copy honest. Grading thresholds and their measured distributions
live in [GRADING.md](GRADING.md).

## Setup

```sh
make -C pipeline venv          # python3 -m venv .venv + pinned deps
```

Dependencies (allowlisted in `contracts/DEPENDENCIES.md`): pynacl, pillow,
jsonschema; pytest (dev). Exact versions are pinned in `requirements.lock`;
byte-reproducibility of a dist is guaranteed for a fixed environment (same
lockfile), which the lockfile makes the default.

## Emitting content

```sh
make -C pipeline content DATE=20260706 SEEDS=path/to/seeds.json \
    [SEQ=1] [DAYS=60] [KEY=path/to/signing-key] [OUT=dist/content]
```

which runs

```sh
.venv/bin/python -m burnfront_pipeline.cli emit \
    --date 20260706 --seq 1 --days 60 --seeds seeds.json --out dist/content
```

- **Determinism:** the pipeline never reads the clock or global RNG. The
  date (`content_version = v{DATE}-{SEQ}`) and the seeds file are inputs:
  same seeds + same date argument => byte-identical `dist/`.
- **Seeds file:** `{"master_seed": int, "start_date": "YYYY-MM-DD",
  "incident_base": int}`. `start_date` is the first daily (UTC days,
  ADR-0002); `incident_base` numbers that day's Incident #N.
- **Signing key:** `--key` or `$BURNFRONT_SIGNING_KEY`, a file holding a
  32-byte Ed25519 seed in hex (`#` comments allowed). The committed
  `tests/fixtures/dev-signing-key` is a DEV key for tests only; production
  keys live in Forge (CLAUDE.md rule 5) and never enter the repo.
- The emitter re-verifies every board (unique solution, deduction-solvable,
  every break witnessed, solution burns exactly) and **refuses the whole
  emit with a nonzero exit** if any check fails.

Output, per `contracts/schemas/`:

```
dist/content/v20260706-1/
  calendar.json (+ .sig)       burnfront.calendar/1 — 60 dailies
  packs/academy-1.json (+ .sig)  burnfront.pack/1 — 7 lessons x 2 boards
  puzzles/bf1-6x6-000001.json  burnfront.puzzle/1
  og/bf1-6x6-000001.png        spoiler-free OG card (no solution info)
  puzzles.csv                  DB seed for the WS-07 importer
```

Chain of trust: detached Ed25519 signature (`<manifest>.sig`, raw 64 bytes
over the exact manifest bytes) -> the manifest sha256 map -> files. Verify
with PyNaCl here or `sodium_crypto_sign_verify_detached` in PHP (WS-07):

```sh
make -C pipeline verify DIR=dist/content/v20260706-1 PUB=key.pub
```

## Publishing rules (brief / critique #16)

Published dates are **immutable from T-48h**. The pipeline cannot see the
clock, so this is enforced at the publish step (WS-16 refuses to overwrite
near dates). Calibration re-sorts ship as a new `content_version` and may
only change future dates — see `calibration/README.md`.

## Tests

```sh
make -C pipeline test      # pytest; slow full-content rebuild excluded
.venv/bin/pytest -m slow   # regenerate the committed content sample (minutes)
```

`tests/fixtures/content-sample/` holds exactly 7 days of dailies + the
academy pack, emitted with the DEV key from `tests/fixtures/seeds-sample.json`
(`make -C pipeline content DATE=20260706 DAYS=7 OUT=tests/fixtures/content-sample`).
`dist/` is never committed.

## Grading distributions

```sh
.venv/bin/python -m burnfront_pipeline.cli measure \
    --profile 6x6-minimal --count 200 --jobs 4 --out 6x6.jsonl
```

reproduces the numbers in GRADING.md.
