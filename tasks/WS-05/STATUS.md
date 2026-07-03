# WS-05 STATUS

## Session a — 2026-07-03 (pipeline productionization + grading v2; builder agent)

## Done

- `pipeline/burnfront_pipeline/` package (commits d37d6ee, 84b6aab, 64746ae,
  b80dde4, plus the fixture/close-out commits after them; HEAD sha in the
  final ledger line below):
  - `engine.py` — reference algorithms copied from `reference/firebreak.py`
    (which stays untouched: `git diff` empty, selftest green). Two opt-in
    extensions, default-off so the default path is RNG-identical to the
    reference: `generate(min_clues=...)` (redundant-clue floor) and
    `generate(terrain_predicate=...)` (cheap curation pre-filter). Kept
    honest by `tests/test_engine_vectors.py`: all 509 burn vectors replayed
    byte-exact (verdict + times), generate/deduction step parity vs
    `contracts/vectors/` on the reference seed plan subset (3x3 all, 4x4
    all, 5x5 seeds 0–4, 6x6 seed 0).
  - `grader.py` — grading v2: tiered rule sets A (count-fills only), B (+
    `clue_reached_too_fast` singletons), C (full reference solver). Grade =
    (lowest solving tier, chain length); `grade_score` = chain length,
    feeding RATING.md §2 unchanged. Weekday bands Mon..Sun per product §5
    with measured thresholds (see `pipeline/GRADING.md`).
  - `curator.py` — deterministic 60-day-capable daily calendar (per-day
    seed streams from the seeds file) + the 7-lesson academy pack (2 boards
    per lesson, filtered by "requires kind K" = solver stalls when K's
    singletons are removed; lesson operationalizations in GRADING.md §5).
  - `signer.py` — Ed25519 (PyNaCl), key path from `$BURNFRONT_SIGNING_KEY`
    or `--key`; detached raw-64-byte `.sig` over exact manifest bytes;
    verify step included. DEV key committed at
    `tests/fixtures/dev-signing-key` (clearly marked, tests only).
  - `emitter.py` — `dist/content/{content_version}/`: puzzle JSONs
    (schema-validated against the frozen `contracts/schemas/*.json`),
    calendar + pack manifests (validated, signed), spoiler-free OG PNGs
    (Pillow; renderer signature admits only public board data), and
    `puzzles.csv`. Re-verifies unique + deduction-solvable + witnessed +
    exact burn per board; RefusalError -> exit 2.
  - `cli.py` (`emit` / `verify` / `measure`), `Makefile`
    (`make -C pipeline content DATE=... SEEDS=...`), `pyproject.toml`
    (pynacl/pillow/jsonschema + pytest, all allowlisted), pinned
    `requirements.lock`, venv at `pipeline/.venv` (gitignored).
  - `calibration/` ingest stub (playtest loop interface; v1 has no live
    recalibration; T-48h immutability + future-dates-only re-sorting
    documented there and in README).
- **Grading measurement** (GRADING.md §2; background jobs died twice
  mid-run, restarted with incremental writes; lead authorized reduced
  7x7/8x8 samples): n=200 for 5x5-minimal / 5x5-floor10 / 6x6-minimal,
  n=93 for 7x7-minimal, n=66 for 8x8-floor28. Headline: tier C dominates
  everywhere (only redundant 5x5 reaches 14% tier B); score =
  R*C-1-n_clues is tier-invariant; `open_cell_unreachable` is unproducible
  (0/759) so lesson 5 uses the documented fallback; Sat band acceptance
  94.6%, Sun structural 100%.
- **Fixture**: `tests/fixtures/content-sample/v20260706-1/` — exactly 7
  dailies (2026-07-06 Mon .. 07-12 Sun) + `academy-1` pack (14 boards),
  emitted with the DEV key from `tests/fixtures/seeds-sample.json`
  (master_seed 20260706). dist/ stays gitignored.
- **OG spoiler check (manual, brief acceptance)**: rendered the Monday
  daily and a lesson-2 card and inspected them visually: near-black ground,
  clue grid + spark star + incident/tier text only; no break/solution
  marks. The renderer cannot leak by construction (no solution parameter;
  asserted in tests) and identical boards with different solutions render
  identical bytes (test).
- Tests: **50 passed, 1 deselected (slow), 0 failed** from `pipeline/`:
  vectors replay, tier logic, curation determinism, sign/verify + tamper,
  emit determinism = two full pipeline runs byte-identical, refusal paths
  incl. CLI nonzero exit, fixture integrity. Slow marker (`pytest -m slow`)
  rebuilds the committed content sample in one process and byte-compares —
  left for the verifier on stable hardware (see Decisions 11).
- Gates at session end: `pytest` 50 passed/1 deselected; `python3
  reference/firebreak.py --selftest` -> "all self-tests passed" with zero
  diff under `reference/` and `contracts/`; `bash scripts/hygiene.sh` ->
  exit 0; `pnpm format:check` fails only on the pre-existing
  `packages/api-client/package.json` violation (present before this
  workstream; pipeline adds none — `.venv`/fixtures are ignored per
  Decisions 1–2).

## Remaining

- Session b (if the lead wants it): 60-day emit smoke on real hardware +
  timing budget write-up; PHP-side signature verification test lands with
  WS-07 (sodium verify of `calendar.json.sig` against the DEV pubkey).
- Verifier session: falsify the acceptance checklist (do not self-sign).

## Blockers

- None. (Compute contention killed two background measurement runs;
  resolved per lead ruling by reduced-n reuse of checkpointed partials —
  documented in GRADING.md §2.)

## Decisions made (lead audit trail)

1. **`scripts/hygiene.sh`: added `--exclude-dir='.venv'`** to the marker
   grep. The brief mandates a venv at `pipeline/.venv`; site-packages
   contain third-party TODO markers that made gate 9 fail locally. CI
   checkouts have no `.venv`, so the gate's strength is unchanged.
2. **`.prettierignore`: added `pipeline/`.** Prettier was scanning venv
   internals and would reformat the pipeline's canonical-bytes JSON
   fixtures (signed manifests must keep exact bytes). Python land is
   Prettier-free by stack decision; nothing weakened.
3. **Tier definitions taken literally from the brief** (A count-fills only,
   B + too-fast singletons, C full). Mid-scan counting singletons
   (`too_many_breaks`/`not_enough_breaks_left`) therefore sit in tier C,
   but provably never gate solvability (count-fill covers them next pass) —
   GRADING.md §1.
4. **Player tier for the 8x8 Sunday Burn = crew** (product §5 calls Sunday
   "big and satisfying, not the hardest"; the frozen tier enum has no
   fourth value). Base rating 1300 + 4x35 = 1440 sits between Fri and Sat —
   consistent with the intended curve.
5. **Redundant bands implemented as clue-removal floors** (`min_clues=10`
   on 5x5 Mon, `28` on 8x8 Sun) rather than clue re-adding: every
   intermediate clue set of the reference's greedy removal already
   satisfies unique + deducible + witnessed, so stopping early is provably
   safe and keeps 8x8 generation fast (GRADING.md §3).
6. **Lesson tagging**: lessons 1 and 7 carry no `technique` tag (optional
   in pack.v1; they are rules-walkthrough/board-shape lessons). Lesson 5
   falls back to `clue_unreachable_in_time` because
   `open_cell_unreachable` is unproducible (0/759 measured, 0 in vectors) —
   documented, not faked (GRADING.md §5).
7. **Reduced measurement samples for 7x7 (n=93) and 8x8 (n=66)** under
   explicit lead authorization (>=30 floor); confidence note in GRADING.md
   §2.
8. **`content_version` date is a CLI argument** (never wall clock) and the
   T-48h immutability rule is enforced at publish time (WS-16), because a
   deterministic pipeline cannot read the clock — README + calibration/.
9. Pack id `academy-1`; puzzle ids `bf1-{R}x{C}-{serial:06d}` with per-size
   serials in curation order (dailies by date, then pack by lesson).
10. pytest default excludes the `slow` marker (full content rebuild);
    `pytest -m slow` runs it. Documented in README.
11. **The committed fixture was produced by deterministic checkpointed
    curation** (one foreground process per day/lesson, pickled, then a
    single assemble+emit step): this session's environment reaps processes
    living longer than ~10–25 minutes (killed two measurement runs and two
    single-process fixture emits mid-flight). Equivalence to a
    single-process emit is structural (identical seeds -> identical
    records) and was evidenced in-session by (a) re-curating the Saturday
    unit from scratch -> identical board/solution, (b) a second
    assemble+emit -> `diff -r` clean against the committed tree, (c) the
    default suite's 2-day full-pipeline double emit being byte-identical.
    The one-process proof is `pytest -m slow` on stable hardware.
12. Lesson 5's unproducible primary predicate is probed over a bounded
    40-candidate prefix per emit (`PROBE_ATTEMPTS_LESSON`) instead of the
    full 150 budget — an unbounded probe cost ~8 minutes per emit for a
    kind that cannot occur; the accepted fallback boards are identical
    under any probe budget.

## Files touched

- `pipeline/**` (new package, tests, fixtures, docs, Makefile, lockfile)
- `tasks/WS-05/STATUS.md` (this file)
- `scripts/hygiene.sh` (decision 1)
- `.prettierignore` (decision 2)
- Nothing in `contracts/`, `reference/`, `packages/`, `api/`, other
  `tasks/`, or CI workflows.

## Resume instructions

1. `make -C pipeline venv` (installs pinned deps into `pipeline/.venv`).
2. `cd pipeline && .venv/bin/pytest` — full default suite must be green;
   `.venv/bin/pytest -m slow` rebuilds the committed content sample and
   byte-compares (minutes of CPU).
3. To emit real content: `make -C pipeline content DATE=YYYYMMDD DAYS=60
   SEEDS=<seeds.json> KEY=<signing key>`; verify with `make -C pipeline
   verify DIR=dist/content/vYYYYMMDD-1 PUB=<pubkey>`.
4. Band thresholds live in `burnfront_pipeline/grader.py` and must be
   changed together with `pipeline/GRADING.md` (measurement rerun:
   `cli measure`).
5. Next work: see Remaining above; the verifier should start from the
   brief's acceptance checklist and `pipeline/GRADING.md`.
