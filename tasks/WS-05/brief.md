# WS-05: Content pipeline productionization + grading v2

Lane: C · Deps: WS-01 · Sessions: 2

## Scope
Restructure the Python reference into `pipeline/` (the copy in `reference/` stays frozen):
`generate → grade → curate → sign → emit`. **Grading v2:** run the deduction solver with
tiered rule sets; grade = required tier + chain length; map to weekly bands (Mon Lookout
redundant … Sat Hotshot minimal, Sun 8×8 redundant — product §5 table). Curate dailies 60+
days ahead + the 7-lesson academy packs (2 practice boards per lesson filtered to require
exactly that lesson's argument). Sign manifests Ed25519 (PyNaCl; private key path from env,
never committed). Emit `dist/content/{version}/…` per `contracts/schemas/`, spoiler-free OG
card PNGs (Pillow: unsolved clue grid, incident number, tier), and `puzzles.csv` DB seed.
Rules: published dates immutable from T-48h; calibration re-sorting applies to future dates
only (critique #16); every emitted puzzle re-verified unique + deduction-solvable +
witnessed (refuse to emit otherwise). Commit exactly 7 days of dailies + one academy pack as
test fixtures (not 90 — critique #48).

## Inputs
`contracts/schemas/*`, `contracts/RATING.md` (board-rating priors), `reference/firebreak.py`.

## Outputs
`pipeline/` package + `make content` + fixtures + `pipeline/calibration/` ingest stub for
the playtest loop.

## Acceptance
- [ ] Deterministic: same seeds + version → byte-identical dist
- [ ] Emitted JSON validates against schemas; signatures verify (PyNaCl + PHP sodium test)
- [ ] A deliberately-broken board (non-unique) is refused with a nonzero exit
- [ ] Weekly band distribution matches the product table over a 60-day emit
- [ ] OG PNGs contain no solution information (manual check documented in STATUS)

## Non-goals
No R2 upload automation (WS-16), no Laravel importer (WS-07), no live recalibration.
