# Burnfront print book

Generates a print-ready PDF of Burnfront (Firebreak) puzzles — the same
generator and uniqueness/deduction guarantees as
[`reference/firebreak.py`](../reference/firebreak.py), laid out as a
puzzle book in the game's **Case File** identity: Lookout / Crew /
Hotshot tiers, a named incident per puzzle, a solver-derived worked
case, and full-color cover art.

```bash
pip install -r requirements.txt
cd book
python3 generate_book.py            # 40-case book with in-book covers
```

## What's in the book

* **Covers** (front and back, full-color vector art): the front is a
  real burn-time field — a BFS runs from the spark, firebreak cells
  block it, and every cell is tinted by the minute it caught fire, so
  the wavefront visibly bends around the containment lines. The back
  carries the case-file blurb, the three generator guarantees, and a
  solved worked example.
* **Front matter**: half-title, dispatch-log epigraph, title page,
  colophon (with the uniqueness/deduction guarantee spelled out).
* **How to Play + A Worked Case**: the tutorial's four panels are
  derived from the reference solver's own deduction trace on the frozen
  demo puzzle (`fb._deduction_steps`), so the walkthrough can never
  drift from what the engine actually deduces.
* **Three sections** — Lookout (5×5, shade 4), Crew (6×6, shade 8),
  Hotshot (7×7, shade 12) — each opened by a tier-badge divider. Every
  puzzle page is styled as an incident report: a procedurally named
  fire ("Coldwater Fire — lightning strike, contained on day 3", the
  same word lists as `app/Support/Burnfront/IncidentNamer.php`),
  difficulty flame pips, and a field-notes strip.
* **Back matter**: answer key, an About page, and Field Notes pages
  padding the block to an even page count.

## Print rules this follows

The interior is **strictly black and white** for offset/POD printing:

* Every fill and stroke is pure black `(0, 0, 0)` or pure white — never
  a gray value, so there's nothing for a B/W print run to misrender as
  a screen/halftone pattern.
* No gradients, no drop shadows, no transparency. (The covers are the
  sanctioned exception: they print in color on separate stock, and even
  there the ember "glow" is stepped flat tones, not gradients.)
* All lines are real vector strokes at print-safe widths (0.6pt grid
  lines, 1.75pt outer borders) — sharp at any resolution.
* Fonts (Staatliches + Liberation Sans, both SIL OFL) are embedded, so
  the PDF renders identically on any print vendor's RIP.
* Given clue numbers are **bold**, solved/deduced numbers are **regular
  weight** — on solution pages this lets a solver's eye tell "what the
  puzzle told you" from "what you had to work out" at a glance.

## Usage

```bash
python3 generate_book.py                                   # default: 15 + 15 + 10
python3 generate_book.py --easy 20 --medium 20 --hard 15   # bigger book
python3 generate_book.py --trim letter -o burnfront_letter.pdf
```

First run generates puzzles with the reference solver and caches them to
`puzzle_cache.json` (gitignored) — `7x7`/12-break ("Hotshot") puzzles
take roughly a minute each on one core, so generation is parallelized
across all CPU cores. Later runs (layout tweaks, a different trim size)
reuse the cache and finish instantly. Delete `puzzle_cache.json` to
force a fresh set of puzzles, or pass `--seed` to get a different (still
cached) set — the seed also drives the incident-name deal.

### Covers

```bash
python3 generate_book.py                        # covers in the book (default)
python3 generate_book.py --covers none          # bare interior, for POD
python3 generate_cover.py --interior burnfront_book.pdf   # KDP wraparound
python3 generate_cover.py --pages 96 --paper cream        # or state the count
```

Two publishing paths:

* **Digital / home printing** — the default `--covers in-book` build is
  the whole product: color front cover, interior, color back cover in
  one PDF. (The covers add two unnumbered leaves; folios and
  recto/verso geometry stay correct for duplex printing.)
* **Print-on-demand (KDP/IngramSpark)** — build the interior with
  `--covers none`, then `generate_cover.py` produces the separate
  one-page wraparound (back | spine | front) with 0.125 in bleed, spine
  width computed from the page count (0.002252 in/page white,
  0.0025 in/page cream), a white ISBN/barcode reserve on the back
  (`--no-barcode-box` to drop it), and spine text only at 80+ pages,
  per KDP's rules.

### Where the solutions go

```bash
python3 generate_book.py --solutions end      # default: compact answer key at the back
python3 generate_book.py --solutions facing   # full solution on the very next page
python3 generate_book.py --solutions none     # puzzles only, no answer key
```

* **`end`** — the classic puzzle-book layout: all puzzles first, then a
  compact answer-key section (12 mini solution grids per page, shading
  pattern only) at the back.
* **`facing`** — each puzzle is immediately followed by its full-size
  solution page. Puzzles are forced onto odd (right-hand/recto) pages,
  so when the book is duplex-printed or perfect-bound, the solution
  lands physically **on the back of the puzzle's own sheet** — exactly
  "flip the page to check your answer."

### Trim sizes

`6x9` (default, the standard KDP/IngramSpark puzzle-book trim),
`8.5x8.5`, `letter`, `a4`. Margins widen on the inside edge (the gutter)
so text never disappears into the spine, and swap sides between
recto/verso pages.

## Files

* `generate_book.py` — CLI entry point; builds the whole book PDF.
* `generate_cover.py` — the separate POD wraparound cover PDF.
* `cover_art.py` — front/back/spine art, shared by both entry points.
* `pdf_common.py` — page geometry, embedded fonts, Case File color
  tokens, the flame mark (converted from the web app's
  `FlameGlyph.vue` SVG path), and the grid renderer (`draw_grid`)
  shared by every puzzle/solution/tutorial panel.
* `puzzle_gen.py` — calls `firebreak.generate()` in parallel worker
  processes and caches results to JSON.
* `incidents.py` — incident names/blurbs (port of the game's
  `IncidentNamer`).
* `fonts/` — embedded Staatliches + Liberation Sans, with licenses.

## Extending

* To change difficulty tiers or grid sizes, edit `TIERS` in
  `generate_book.py`.
* To restyle a page, edit the corresponding `draw_*` function — all
  interior drawing goes through `pdf_common.draw_grid`,
  `draw_paragraph`, and the `BLACK`/`WHITE`-only constants, so any new
  page automatically follows the same print constraints. Cover drawing
  lives in `cover_art.py` and is the only place `COVER_*` colors are
  allowed.
