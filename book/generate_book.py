#!/usr/bin/env python3
"""
Build the Burnfront print book: a PDF of Firebreak puzzles for
print-on-demand or home printing.

Print constraints (see pdf_common.py): interior is strictly black and
white -- pure (0,0,0) fills/strokes only, no gradients, no grayscale, no
transparency, no soft shadows -- and every solid line is a real vector
stroke so it stays sharp at any print resolution.

Usage:
  python3 generate_book.py                       # default 40-puzzle book
  python3 generate_book.py --easy 20 --medium 20 --hard 12
  python3 generate_book.py --solutions facing     # solution on the back
                                                   # of each puzzle's sheet
  python3 generate_book.py --solutions end        # solutions gathered in
                                                   # the back of the book
  python3 generate_book.py --trim letter -o burnfront_letter.pdf

First run generates puzzles with the reference solver in firebreak.py
(slow for "hard" -- roughly a minute each on one core, parallelized
across all cores here) and caches them to book/puzzle_cache.json so
later runs (layout tweaks, different trim size) are instant.
"""

import argparse
import os
import sys

from reportlab.pdfgen import canvas

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
import firebreak as fb

from pdf_common import (
    BLACK, SANS, SANS_BOLD, SANS_ITALIC, TRIM_SIZES,
    PageGeometry, draw_grid, draw_page_number, draw_paragraph,
    register_fonts,
)
from puzzle_gen import generate_batch

BOOK_TITLE = "BURNFRONT"

RULES_TEXT = (
    "Fire starts on the star at minute 0. Every minute, it spreads from "
    "every burning cell to every orthogonally adjacent unshaded cell. "
    "Shade exactly the number of cells printed under the puzzle's title "
    "as firebreaks -- the star and every numbered cell can never be "
    "shaded. Every unshaded cell must eventually catch fire, and every "
    "numbered cell must catch fire at exactly the minute printed in it."
)

RULES_TIPS = [
    ("Too fast means a wall.", "If a clue's number is smaller than the "
     "length of some open route to it, that route must be blocked -- "
     "shade a cell on it."),
    ("Too slow means a channel.", "If only one route of the right length "
     "can still reach a clue, that whole route must be open, and its "
     "cells burn 1, 2, 3... in order outward from the star."),
    ("Follow the count.", "Once every firebreak is placed, everything "
     "left over is open; once every remaining cell must be shaded to "
     "reach the count, shade it."),
]


class Book:
    def __init__(self, path, page_size):
        register_fonts()
        self.c = canvas.Canvas(path, pagesize=page_size)
        self.geo = PageGeometry(page_size)
        self.page_no = 1

    def box(self):
        return self.geo.content_box(self.page_no)

    def end_page(self, footer_label=None, number=True):
        if number:
            draw_page_number(self.c, self.geo, self.page_no,
                              footer_label or BOOK_TITLE)
        self.c.showPage()
        self.page_no += 1

    def ensure_recto(self):
        """So a puzzle always starts on an odd (right-hand) page -- needed
        for --solutions facing, so its solution lands on the physical back
        of the same printed sheet."""
        if self.page_no % 2 == 0:
            self.c.showPage()
            self.page_no += 1

    def save(self):
        self.c.save()


# ---------------------------------------------------------------------------
# Front matter
# ---------------------------------------------------------------------------

def draw_title_page(book, total_puzzles):
    c, box = book.c, book.box()
    c.setFont(SANS_BOLD, 34)
    c.setFillColorRGB(*BLACK)
    c.drawCentredString((box["x0"] + box["x1"]) / 2, box["y1"] - box["height"] * 0.32, BOOK_TITLE)
    c.setFont(SANS, 15)
    c.drawCentredString((box["x0"] + box["x1"]) / 2,
                         box["y1"] - box["height"] * 0.32 - 26,
                         f"{total_puzzles} Firebreak Puzzles")
    c.setFont(SANS_ITALIC, 10.5)
    c.drawCentredString((box["x0"] + box["x1"]) / 2,
                         box["y1"] - box["height"] * 0.32 - 46,
                         "a logic puzzle of fire, distance, and deduction")
    book.end_page(number=False)


def draw_colophon_page(book, spec):
    box = book.box()
    y = draw_paragraph(
        book.c,
        "Every puzzle in this book has a verified unique solution and is "
        "solvable by pure deduction -- no guessing is ever required. Each "
        "board was produced and checked by the reference generator in "
        "firebreak.py: an exact solution counter proves uniqueness, and a "
        "no-search deduction solver certifies a guess-free solving path, "
        "before the board is printed.",
        box["x0"], box["y1"] - 60, box["width"], size=9.5, leading=13)
    y -= 20
    y = draw_paragraph(
        book.c,
        f"This edition: {spec['easy']} easy (5×5, shade 4), "
        f"{spec['medium']} medium (6×6, shade 8), and {spec['hard']} "
        f"hard (7×7, shade 12) puzzles.",
        box["x0"], y, box["width"], size=9.5, leading=13)
    y -= 20
    c2 = book.c
    c2.setFont(SANS, 8.5)
    c2.drawString(box["x0"], box["y0"] + 40,
                  "Set in Liberation Sans (SIL Open Font License).")
    book.end_page(number=False)


def draw_worked_example_page(book):
    """Reuses firebreak.py's own README worked example (already verified
    unique + deduction-solvable) so the tutorial illustration is always
    correct, never hand-drawn separately from the engine."""
    c, box = book.c, book.box()
    y_top = box["y1"] - 4

    c.setFont(SANS_BOLD, 16)
    c.drawString(box["x0"], y_top, "How to Play")
    c.setLineWidth(1)
    c.line(box["x0"], y_top - 8, box["x1"], y_top - 8)

    y = draw_paragraph(c, RULES_TEXT, box["x0"], y_top - 26, box["width"],
                        size=10, leading=14)
    y -= 8
    for head, body in RULES_TIPS:
        c.setFont(SANS_BOLD, 9.5)
        c.drawString(box["x0"], y, "• " + head)
        y = draw_paragraph(c, body, box["x0"] + 12, y - 13,
                            box["width"] - 12, size=9.5, leading=12.5)
        y -= 6

    book.end_page(footer_label="how to play")

    # Second page: the worked example, puzzle and solution side by side.
    box = book.box()
    c.setFont(SANS_BOLD, 16)
    c.drawString(box["x0"], box["y1"] - 4, "Worked Example")
    c.setLineWidth(1)
    c.line(box["x0"], box["y1"] - 12, box["x1"], box["y1"] - 12)

    pz = fb.demo_puzzle()
    state = fb.deduction_solve(pz)
    times = fb.bfs_times(pz, lambda x: state[x] == fb.OPEN) if state else {}

    half_w = (box["width"] - 24) / 2
    top_box = {"x0": box["x0"], "y0": box["y0"] + 40,
               "x1": box["x0"] + half_w, "y1": box["y1"] - 40,
               "width": half_w, "height": box["y1"] - 40 - (box["y0"] + 40)}
    bottom_box = {"x0": box["x0"] + half_w + 24, "y0": box["y0"] + 40,
                  "x1": box["x1"], "y1": box["y1"] - 40,
                  "width": half_w, "height": top_box["height"]}

    c.setFont(SANS_BOLD, 10)
    c.drawCentredString((top_box["x0"] + top_box["x1"]) / 2, top_box["y1"] + 14,
                         f"5×5 · shade {pz.n_breaks}")
    draw_grid(c, top_box, pz.R, pz.C, pz.spark, pz.clues, max_cell=52)

    c.drawCentredString((bottom_box["x0"] + bottom_box["x1"]) / 2, bottom_box["y1"] + 14,
                         "solution")
    draw_grid(c, bottom_box, pz.R, pz.C, pz.spark, pz.clues,
              solution=state, times=times, max_cell=52)

    y = draw_paragraph(
        c, "The 5 near the top is only 3 steps from the star along an open "
        "route -- too close, so a cell on every such route is a firebreak. "
        "Once the count of firebreaks is used up, the rest fall into "
        "place: read the full step-by-step reasoning at the Burnfront "
        "project page.",
        box["x0"], box["y0"] + 24, box["width"], size=8.5, leading=11.5)

    book.end_page(footer_label="how to play")


def draw_section_divider(book, label, description):
    c = book.c
    box = book.box()
    cx = (box["x0"] + box["x1"]) / 2
    cy = box["y0"] + box["height"] / 2
    c.setFont(SANS_BOLD, 30)
    c.drawCentredString(cx, cy + 10, label.upper())
    c.setLineWidth(1.25)
    c.line(cx - 60, cy - 6, cx + 60, cy - 6)
    c.setFont(SANS, 10.5)
    c.drawCentredString(cx, cy - 26, description)
    book.end_page(number=False)


# ---------------------------------------------------------------------------
# Puzzle / solution pages
# ---------------------------------------------------------------------------

def draw_puzzle_page(book, puzzle, number, label):
    c, box = book.c, book.box()
    header_h = 30
    footer_h = 34

    c.setFont(SANS_BOLD, 14)
    c.drawString(box["x0"], box["y1"] - 12, f"PUZZLE {number}")
    c.setFont(SANS, 10)
    c.drawRightString(box["x1"], box["y1"] - 12,
                       f"{label} · {puzzle['R']}×{puzzle['C']} · shade {puzzle['N']}")
    c.setLineWidth(1)
    c.line(box["x0"], box["y1"] - header_h, box["x1"], box["y1"] - header_h)

    grid_box = {
        "x0": box["x0"], "x1": box["x1"],
        "y0": box["y0"] + footer_h,
        "y1": box["y1"] - header_h - 6,
        "width": box["width"],
        "height": box["y1"] - header_h - 6 - (box["y0"] + footer_h),
    }
    draw_grid(c, grid_box, puzzle["R"], puzzle["C"], puzzle["spark"], puzzle["clues"])

    c.setFont(SANS_ITALIC, 8.5)
    c.drawCentredString((box["x0"] + box["x1"]) / 2, box["y0"] + 16,
                         "Every unshaded cell must burn; every numbered cell, at exactly its minute.")

    book.end_page(footer_label=BOOK_TITLE)


def draw_solution_page_facing(book, puzzle, number, label):
    c, box = book.c, book.box()
    header_h = 30

    c.setFont(SANS_BOLD, 14)
    c.drawString(box["x0"], box["y1"] - 12, f"SOLUTION · PUZZLE {number}")
    c.setFont(SANS, 10)
    c.drawRightString(box["x1"], box["y1"] - 12, label)
    c.setLineWidth(1)
    c.line(box["x0"], box["y1"] - header_h, box["x1"], box["y1"] - header_h)

    grid_box = {
        "x0": box["x0"], "x1": box["x1"],
        "y0": box["y0"], "y1": box["y1"] - header_h - 6,
        "width": box["width"],
        "height": box["y1"] - header_h - 6 - box["y0"],
    }
    draw_grid(c, grid_box, puzzle["R"], puzzle["C"], puzzle["spark"], puzzle["clues"],
              solution=puzzle["solution"], times=puzzle["times"])

    book.end_page(footer_label=BOOK_TITLE)


def draw_solutions_compact_page(book, entries):
    """entries: list of (number, label, puzzle) to place in a grid of mini
    answer-key boards (shading pattern only, no burn-time numbers -- too
    small to typeset legibly, and the shading alone confirms a solve)."""
    c, box = book.c, book.box()
    cols, rows = 3, 4
    cell_w = box["width"] / cols
    cell_h = box["height"] / rows
    for idx, (number, label, puzzle) in enumerate(entries):
        col = idx % cols
        row = idx // cols
        cx0 = box["x0"] + col * cell_w
        cy1 = box["y1"] - row * cell_h
        sub_box = {
            "x0": cx0 + 6, "x1": cx0 + cell_w - 6,
            "y0": cy1 - cell_h + 6, "y1": cy1 - 16,
            "width": cell_w - 12, "height": cell_h - 22,
        }
        c.setFont(SANS_BOLD, 8.5)
        c.drawCentredString((sub_box["x0"] + sub_box["x1"]) / 2, cy1 - 10, f"Puzzle {number}")
        draw_grid(c, sub_box, puzzle["R"], puzzle["C"], puzzle["spark"], puzzle["clues"],
                  solution=puzzle["solution"], times=None, show_numbers=False)
    book.end_page(footer_label="solutions")


# ---------------------------------------------------------------------------
# Build
# ---------------------------------------------------------------------------

SECTION_DEFS = [
    ("Easy", 5, 5, 4),
    ("Medium", 6, 6, 8),
    ("Hard", 7, 7, 12),
]


def build(args):
    counts = {"easy": args.easy, "medium": args.medium, "hard": args.hard}
    sections = [(label, R, C, N, counts[label.lower()])
                for label, R, C, N in SECTION_DEFS if counts[label.lower()] > 0]

    specs = []
    section_ranges = []
    for label, R, C, N, count in sections:
        start = len(specs)
        for i in range(count):
            specs.append((R, C, N, args.seed + start + i))
        section_ranges.append((label, R, C, N, start, count))

    total = len(specs)
    if total == 0:
        print("Nothing to generate: all section counts are zero.", file=sys.stderr)
        sys.exit(1)

    def progress(done, todo, spec):
        print(f"  generated {done}/{todo} (size {spec[0]}x{spec[1]}, N={spec[2]}, seed={spec[3]})")

    print(f"Generating {total} puzzles (cached results are reused)...")
    puzzles = generate_batch(specs, workers=args.workers, progress=progress)

    page_size = TRIM_SIZES[args.trim]
    book = Book(args.output, page_size)

    draw_title_page(book, total)
    draw_colophon_page(book, {"easy": counts["easy"], "medium": counts["medium"], "hard": counts["hard"]})
    draw_worked_example_page(book)

    numbered = []  # (number, label, puzzle) in book order
    n = 1
    for label, R, C, N, start, count in section_ranges:
        draw_section_divider(book, label, f"{R}×{C} grid · shade {N} cells · {count} puzzles")
        for i in range(count):
            puzzle = puzzles[start + i]
            if args.solutions == "facing":
                book.ensure_recto()
            draw_puzzle_page(book, puzzle, n, label)
            if args.solutions == "facing":
                draw_solution_page_facing(book, puzzle, n, label)
            numbered.append((n, label, puzzle))
            n += 1

    if args.solutions == "end":
        draw_section_divider(book, "Solutions", "shaded cells are firebreaks; the star is the spark")
        per_page = 12
        for i in range(0, len(numbered), per_page):
            draw_solutions_compact_page(book, numbered[i:i + per_page])

    book.save()
    print(f"Wrote {args.output} ({book.page_no - 1} pages, {total} puzzles).")


def parse_args():
    ap = argparse.ArgumentParser(description=__doc__,
                                  formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("-o", "--output", default="burnfront_book.pdf")
    ap.add_argument("--trim", choices=sorted(TRIM_SIZES), default="6x9")
    ap.add_argument("--easy", type=int, default=15)
    ap.add_argument("--medium", type=int, default=15)
    ap.add_argument("--hard", type=int, default=10)
    ap.add_argument("--solutions", choices=["end", "facing", "none"], default="end",
                     help="'end': compact answer key at the back (default). "
                          "'facing': full solution on the very next page, "
                          "which prints as the back of the puzzle's sheet "
                          "in a duplex-printed / perfect-bound book. "
                          "'none': no solutions at all.")
    ap.add_argument("--seed", type=int, default=1000, help="base seed for reproducible generation")
    ap.add_argument("--workers", type=int, default=None, help="parallel generator processes (default: CPU count)")
    return ap.parse_args()


if __name__ == "__main__":
    build(parse_args())
