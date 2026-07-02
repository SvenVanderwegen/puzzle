# Burnfront print book

Generates a print-ready PDF of Firebreak puzzles: the same generator and
uniqueness/deduction guarantees as [`firebreak.py`](../firebreak.py), laid
out as a puzzle book.

## Print rules this follows

The interior is **strictly black and white** for offset/POD printing:

* Every fill and stroke is pure black `(0, 0, 0)` or pure white -- never a
  gray value, so there's nothing for a B/W print run to misrender as a
  screen/halftone pattern.
* No gradients, no drop shadows, no transparency.
* All lines are real vector strokes at print-safe widths (0.6pt grid
  lines, 1.75pt outer borders) -- sharp at any resolution, no antialiased
  raster edges.
* Fonts (Liberation Sans, SIL Open Font License) are embedded in the PDF,
  so it renders identically on any print vendor's RIP.
* Given clue numbers are **bold**, solved/deduced numbers are **regular
  weight** -- on solution pages this lets a solver's eye tell "what the
  puzzle told you" from "what you had to work out" at a glance.

## Usage

```bash
cd book
python3 generate_book.py                                  # default: 15 easy + 15 medium + 10 hard
python3 generate_book.py --easy 20 --medium 20 --hard 15   # bigger book
python3 generate_book.py --trim letter -o burnfront_letter.pdf
```

First run generates puzzles with the reference solver and caches them to
`puzzle_cache.json` (gitignored) -- `7x7`/12-break ("hard") puzzles take
roughly a minute each on one core, so generation is parallelized across
all CPU cores. Later runs (layout tweaks, a different trim size) reuse the
cache and finish instantly. Delete `puzzle_cache.json` to force a fresh
set of puzzles, or pass `--seed` to get a different (still cached) set.

### Where the solutions go

```bash
python3 generate_book.py --solutions end      # default: compact answer key at the back of the book
python3 generate_book.py --solutions facing   # full solution on the very next page
python3 generate_book.py --solutions none     # puzzles only, no answer key
```

* **`end`** -- the classic puzzle-book layout: all puzzles first, then a
  compact answer-key section (12 mini solution grids per page, shading
  pattern only) at the back.
* **`facing`** -- each puzzle is immediately followed by its full-size
  solution page. Puzzles are forced onto odd (right-hand/recto) pages, so
  when the book is duplex-printed or perfect-bound, the solution lands
  physically **on the back of the puzzle's own sheet** -- exactly "flip
  the page to check your answer," no flipping to the back of the book.

### Trim sizes

`6x9` (default, the standard KDP/IngramSpark puzzle-book trim), `8.5x8.5`,
`letter`, `a4`. Margins widen on the inside edge (the gutter) so text
never disappears into the spine, and swap sides between recto/verso pages.

## Files

* `generate_book.py` -- CLI entry point; builds the whole PDF.
* `pdf_common.py` -- page geometry, embedded fonts, and the grid renderer
  (`draw_grid`) shared by every puzzle/solution page.
* `puzzle_gen.py` -- calls `firebreak.generate()` in parallel worker
  processes and caches results to JSON.
* `fonts/` -- embedded Liberation Sans (regular/bold/italic) + license.

## Extending

* To change difficulty tiers or grid sizes, edit `SECTION_DEFS` in
  `generate_book.py`.
* To restyle a page, edit the corresponding `draw_*` function -- all
  drawing goes through `pdf_common.draw_grid`, `draw_paragraph`, and the
  `BLACK`/`WHITE`-only color constants, so any new page automatically
  follows the same print constraints.
