#!/usr/bin/env python3
"""
Build the Burnfront print book: a PDF of Firebreak puzzles for
print-on-demand or home printing, in the game's Case File identity --
tiers named Lookout / Crew / Hotshot, every puzzle labeled as a named
incident, and a step-by-step worked case derived from the reference
solver's own trace.

Print constraints (see pdf_common.py): the interior is strictly black and
white -- pure (0,0,0) fills/strokes only, no gradients, no grayscale, no
transparency -- and every solid line is a real vector stroke so it stays
sharp at any print resolution. The front/back covers are the sanctioned
exception: full-color vector art (cover_art.py), as covers print on
separate stock.

Usage:
  python3 generate_book.py                       # default 40-case book
  python3 generate_book.py --easy 20 --medium 20 --hard 12
  python3 generate_book.py --solutions facing     # solution on the back
                                                   # of each puzzle's sheet
  python3 generate_book.py --solutions end        # solutions gathered in
                                                   # the back of the book
  python3 generate_book.py --covers none          # bare interior, for POD
                                                   # (the cover is a separate
                                                   # wraparound file there --
                                                   # see generate_cover.py)
  python3 generate_book.py --trim letter -o burnfront_letter.pdf

First run generates puzzles with the reference solver in
reference/firebreak.py (slow for "hard" -- roughly a minute each on one
core, parallelized across all cores here) and caches them to
book/puzzle_cache.json so later runs (layout tweaks, different trim size)
are instant.
"""

import argparse
import os
import sys

from reportlab.pdfgen import canvas

sys.path.insert(0, os.path.join(
    os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "reference"))
import firebreak as fb

import cover_art
from incidents import assign_incidents
from pdf_common import (
    BLACK, DISPLAY, SANS, SANS_BOLD, SANS_ITALIC, TRIM_SIZES,
    PageGeometry, draw_flame, draw_grid, draw_page_number, draw_paragraph,
    register_fonts,
)
from puzzle_gen import generate_batch

BOOK_TITLE = "BURNFRONT"
TAGLINE = "a logic puzzle of fire, distance, and deduction"

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

# The difficulty ladder from docs/concept.md -- real wildland-fire crew
# ranks, same names the game uses.
TIERS = [
    {"key": "easy", "name": "Lookout", "R": 5, "C": 5, "N": 4, "pips": 1,
     "desc": "A single spotter's log -- a small incident, sparse and "
             "quick to close."},
    {"key": "medium", "name": "Crew", "R": 6, "C": 6, "N": 8, "pips": 2,
     "desc": "A hand crew's sector report -- more line on the ground, "
             "more ways to be wrong."},
    {"key": "hard", "name": "Hotshot", "R": 7, "C": 7, "N": 12, "pips": 3,
     "desc": "An elite crew's report on a fire that got away from the "
             "first line. Every timestamp will have to earn its keep."},
]


class Book:
    def __init__(self, path, page_size, folio_offset=0):
        register_fonts()
        self.c = canvas.Canvas(path, pagesize=page_size)
        self.geo = PageGeometry(page_size)
        self.page_no = 1               # physical PDF page (1 = first)
        self.folio_offset = folio_offset

    def box(self):
        return self.geo.content_box(self.page_no)

    def end_page(self, footer_label=None, number=True):
        folio = self.page_no - self.folio_offset
        if number and folio >= 1:
            draw_page_number(self.c, self.geo, self.page_no, folio,
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


def demo_dict():
    """The frozen worked example from firebreak.py, solved, as the plain
    dict shape the page renderers use."""
    pz = fb.demo_puzzle()
    state = fb.deduction_solve(pz)
    times = fb.bfs_times(pz, lambda x: state[x] == fb.OPEN) if state else {}
    return {"R": pz.R, "C": pz.C, "spark": pz.spark, "clues": pz.clues,
            "solution": state, "times": times}


def draw_pips(c, x_right, y, count, size=8.5):
    """Difficulty pips: 1-3 small flame marks, right-aligned at x_right."""
    step = size * 1.05
    for i in range(count):
        draw_flame(c, x_right - (count - 1 - i) * step - size / 2,
                   y + size * 0.38, size, fill=BLACK)


# ---------------------------------------------------------------------------
# Covers (in-book edition)
# ---------------------------------------------------------------------------

def draw_front_cover_page(book, meta):
    w, h = book.geo.width, book.geo.height
    cover_art.draw_front(book.c, (0, 0, w, h), (0, 0, w, h), meta)
    book.end_page(number=False)


def draw_back_cover_page(book, meta):
    w, h = book.geo.width, book.geo.height
    cover_art.draw_back(book.c, (0, 0, w, h), (0, 0, w, h), meta,
                        demo=demo_dict(), barcode=False)
    book.end_page(number=False)


def draw_blank_page(book):
    book.end_page(number=False)


# ---------------------------------------------------------------------------
# Front matter
# ---------------------------------------------------------------------------

def draw_half_title_page(book):
    c, box = book.c, book.box()
    cx = (box["x0"] + box["x1"]) / 2
    y = box["y1"] - box["height"] * 0.22
    draw_flame(c, cx, y + 30, 22, fill=BLACK)
    c.setFont(DISPLAY, 21)
    c.setFillColorRGB(*BLACK)
    c.drawCentredString(cx, y, BOOK_TITLE)
    book.end_page(number=False)


def draw_epigraph_page(book):
    """Verso of the half-title: a dispatch-log epigraph setting the tone."""
    c, box = book.c, book.box()
    cx = (box["x0"] + box["x1"]) / 2
    y = box["y0"] + box["height"] * 0.42
    c.setFont(SANS_ITALIC, 9.5)
    c.setFillColorRGB(*BLACK)
    for line in ["0611 — smoke reported, origin unknown.",
                 "0642 — crews on scene, cutting line.",
                 "0715 — line reported holding.",
                 "Prove it."]:
        c.drawCentredString(cx, y, line)
        y -= 15
    book.end_page(number=False)


def draw_title_page(book, total_puzzles):
    c, box = book.c, book.box()
    cx = (box["x0"] + box["x1"]) / 2
    top = box["y1"] - box["height"] * 0.16

    cover_art.draw_tracked(c, cx, top, "LINE VERIFICATION UNIT", SANS, 8.5,
                           2.2, BLACK, align="center")
    c.setLineWidth(0.8)
    c.setStrokeColorRGB(*BLACK)
    c.line(cx - 70, top - 10, cx + 70, top - 10)

    draw_flame(c, cx, top - 58, 34, fill=BLACK)

    c.setFont(DISPLAY, 44)
    c.setFillColorRGB(*BLACK)
    c.drawCentredString(cx, top - 118, BOOK_TITLE)

    c.setLineWidth(1.4)
    c.line(cx - 55, top - 134, cx + 55, top - 134)

    cover_art.draw_tracked(c, cx, top - 158, f"{total_puzzles} FIREBREAK PUZZLES",
                           DISPLAY, 15, 2.4, BLACK, align="center")
    c.setFont(SANS_ITALIC, 10.5)
    c.drawCentredString(cx, top - 178, TAGLINE)

    c.setFont(SANS, 9)
    c.drawCentredString(cx, box["y0"] + 26, "CASE FILES · VOLUME ONE")
    book.end_page(number=False)


def draw_colophon_page(book, spec, edition):
    c, box = book.c, book.box()
    y = draw_paragraph(
        c,
        "Every puzzle in this book has a verified unique solution and is "
        "solvable by pure deduction -- no guessing is ever required. Each "
        "board was produced and checked by the Burnfront reference "
        "generator: an exact solution counter proves uniqueness, a "
        "no-search deduction solver certifies a guess-free solving path, "
        "and every firebreak is witnessed -- provable from the timestamps "
        "themselves, never from the shading count alone -- before the "
        "board is printed.",
        box["x0"], box["y1"] - 60, box["width"], size=9.5, leading=13)
    y -= 20
    draw_paragraph(
        c,
        f"This edition: {spec['easy']} Lookout (5×5, shade 4), "
        f"{spec['medium']} Crew (6×6, shade 8), and {spec['hard']} "
        f"Hotshot (7×7, shade 12) cases. Incident names and causes are "
        f"fictional; any resemblance to a real fire is coincidence.",
        box["x0"], y, box["width"], size=9.5, leading=13)
    c.setFont(SANS, 8.5)
    c.drawString(box["x0"], box["y0"] + 52, edition)
    c.drawString(box["x0"], box["y0"] + 40,
                 "Set in Staatliches and Liberation Sans "
                 "(both SIL Open Font License).")
    book.end_page(number=False)


# ---------------------------------------------------------------------------
# How to play + the worked case
# ---------------------------------------------------------------------------

def draw_rules_page(book):
    c, box = book.c, book.box()
    y_top = box["y1"] - 4

    c.setFont(DISPLAY, 19)
    c.drawString(box["x0"], y_top, "HOW TO PLAY")
    c.setLineWidth(1)
    c.line(box["x0"], y_top - 8, box["x1"], y_top - 8)

    y = draw_paragraph(c, RULES_TEXT, box["x0"], y_top - 26, box["width"],
                       size=10, leading=14)
    y -= 10
    for head, body in RULES_TIPS:
        draw_flame(c, box["x0"] + 4, y + 3, 9, fill=BLACK)
        c.setFont(SANS_BOLD, 9.5)
        c.setFillColorRGB(*BLACK)
        c.drawString(box["x0"] + 12, y, head)
        y = draw_paragraph(c, body, box["x0"] + 12, y - 13,
                           box["width"] - 12, size=9.5, leading=12.5)
        y -= 7

    y -= 6
    draw_paragraph(
        c,
        "In the fiction of this book, each puzzle is an incident report: "
        "the star is where the fire started, the bold numbers are sensor "
        "timestamps -- the exact minute the fire arrived -- and the cells "
        "you shade are the containment lines the crew dug. Your job at "
        "the Line Verification Unit is to reconstruct the one line "
        "placement the evidence allows.",
        box["x0"], y, box["width"], size=9, leading=12.5)

    book.end_page(footer_label="how to play")


def _walkthrough_stages():
    """Split the reference solver's own trace on the demo puzzle into
    stages for the worked-case spread, so the tutorial can never drift
    from what the engine actually deduces.

    Returns (pz, stages, final_state, times) where each stage is
    (state_after, new_cells, kinds) -- kinds is the set of reason kinds
    that fired in the stage."""
    pz = fb.demo_puzzle()
    steps, final_state = fb._deduction_steps(pz)
    times = fb.bfs_times(pz, lambda x: final_state[x] == fb.OPEN)

    # Stage 1: the leading run of same-state forced cells. Stage 2: every
    # other individually-forced step. Stage 3: the endgame count-fill.
    fill_kinds = {"all_breaks_placed", "rest_must_be_breaks"}
    lead_state = steps[0]["state"]
    i = 0
    while i < len(steps) and steps[i]["state"] == lead_state \
            and steps[i]["reason"]["kind"] not in fill_kinds:
        i += 1
    j = i
    while j < len(steps) and steps[j]["reason"]["kind"] not in fill_kinds:
        j += 1

    state = fb.initial_state(pz)
    stages = []
    for lo, hi in ((0, i), (i, j), (j, len(steps))):
        new_cells, kinds = set(), set()
        for s in steps[lo:hi]:
            cell = tuple(s["cell"])
            state[cell] = fb.SHADED if s["state"] == "break" else fb.OPEN
            new_cells.add(cell)
            kinds.add(s["reason"]["kind"])
        stages.append((dict(state), new_cells, kinds))
    return pz, stages, final_state, times


_KIND_CAPTIONS = [
    ("clue_reached_too_fast",
     "Too fast means a wall: through open ground the fire would reach a "
     "timestamp sooner than the report allows, so the ringed cells are "
     "forced firebreaks."),
    ("clue_unreachable_in_time",
     "Too slow means a channel: shading any ringed cell would leave some "
     "timestamp unreachable by its minute, so they are forced open "
     "(marked with a dot)."),
    ("all_breaks_placed",
     "Follow the count: every firebreak is placed, so everything still "
     "blank is open ground -- the case is closed."),
    ("rest_must_be_breaks",
     "Follow the count: only as many blanks remain as firebreaks still "
     "owed, so all of them are shaded -- the case is closed."),
]


def _stage_caption(n, kinds):
    if {"clue_reached_too_fast", "clue_unreachable_in_time"} <= kinds:
        return (f"{n} — The evidence squeezes both ways: cells the fire "
                "would otherwise reach too soon are forced firebreaks "
                "(ringed black); cells some timestamp still needs are "
                "forced open (dotted).")
    for kind, text in _KIND_CAPTIONS:
        if kind in kinds:
            return f"{n} — {text}"
    return f"{n} — The solver records another forced move."


def draw_worked_case_page(book):
    c, box = book.c, book.box()
    c.setFont(DISPLAY, 19)
    c.setFillColorRGB(*BLACK)
    c.drawString(box["x0"], box["y1"] - 4, "A WORKED CASE")
    c.setFont(SANS, 9)
    c.drawRightString(box["x1"], box["y1"] - 4, "5×5 · shade 4")
    c.setLineWidth(1)
    c.line(box["x0"], box["y1"] - 12, box["x1"], box["y1"] - 12)

    pz, stages, final_state, times = _walkthrough_stages()

    panels = [
        (None, set(), set(),
         "1 — The report: a spark, five timestamps, four containment "
         "cells to account for. Bold numbers are the minutes the fire "
         "arrived."),
    ]
    for k, (state, new_cells, kinds) in enumerate(stages[:2], start=2):
        panels.append((state, new_cells,
                       {x for x in new_cells if state[x] == fb.OPEN},
                       _stage_caption(k, kinds)))
    panels.append((final_state, stages[-1][1], set(),
                   _stage_caption(4, stages[-1][2]) +
                   " Light numbers are the burn minutes the finished "
                   "line forces."))

    # 2x2 grid of panels, caption under each.
    gap = 16
    top = box["y1"] - 24
    panel_w = (box["width"] - gap) / 2
    panel_h = (top - box["y0"]) / 2 - gap / 2
    grid_h = panel_h - 52

    for idx, (state, highlight, open_marks, caption) in enumerate(panels):
        col, row = idx % 2, idx // 2
        px0 = box["x0"] + col * (panel_w + gap)
        py1 = top - row * (panel_h + gap / 2)
        gbox = {"x0": px0, "x1": px0 + panel_w,
                "y0": py1 - grid_h, "y1": py1,
                "width": panel_w, "height": grid_h}
        show_times = times if idx == len(panels) - 1 else None
        draw_grid(c, gbox, pz.R, pz.C, pz.spark, pz.clues,
                  solution=state, times=show_times, max_cell=34,
                  highlight=highlight, open_marks=open_marks)
        draw_paragraph(c, caption, px0, py1 - grid_h - 11, panel_w,
                       size=7.8, leading=10)

    book.end_page(footer_label="how to play")


# ---------------------------------------------------------------------------
# Section dividers, puzzle and solution pages
# ---------------------------------------------------------------------------

def draw_section_divider(book, tier, first_case, count):
    c = book.c
    box = book.box()
    cx = (box["x0"] + box["x1"]) / 2
    cy = box["y0"] + box["height"] * 0.56

    # Tier badge: the tier's flame pips in a ring above the name.
    c.setLineWidth(1.25)
    c.setStrokeColorRGB(*BLACK)
    c.circle(cx, cy + 64, 26, fill=0, stroke=1)
    pip = {1: 32, 2: 22, 3: 16}[tier["pips"]]
    total_w = tier["pips"] * pip * 0.92
    x = cx - total_w / 2 + pip * 0.46
    for _ in range(tier["pips"]):
        draw_flame(c, x, cy + 64, pip, fill=BLACK)
        x += pip * 0.92

    c.setFont(DISPLAY, 34)
    c.setFillColorRGB(*BLACK)
    c.drawCentredString(cx, cy, tier["name"].upper())
    c.setLineWidth(1.25)
    c.line(cx - 60, cy - 14, cx + 60, cy - 14)
    c.setFont(SANS, 10.5)
    c.drawCentredString(cx, cy - 34,
                        f"{tier['R']}×{tier['C']} grid · shade {tier['N']} "
                        f"cells · cases {first_case}–{first_case + count - 1}")
    draw_paragraph(c, tier["desc"], box["x0"] + box["width"] * 0.12, cy - 58,
                   box["width"] * 0.76, font=SANS_ITALIC, size=9.5,
                   leading=13, align="center")
    book.end_page(number=False)


def draw_puzzle_page(book, puzzle, number, tier):
    c, box = book.c, book.box()
    header_h = 46
    footer_h = 58

    c.setFont(DISPLAY, 20)
    c.setFillColorRGB(*BLACK)
    c.drawString(box["x0"], box["y1"] - 14, f"PUZZLE {number}")
    c.setFont(SANS, 9.5)
    c.drawRightString(box["x1"], box["y1"] - 6,
                      f"{tier['name'].upper()} · {puzzle['R']}×{puzzle['C']}"
                      f" · SHADE {puzzle['N']}")
    draw_pips(c, box["x1"], box["y1"] - 24, tier["pips"])

    # Incident line: the case this report belongs to.
    inc = puzzle["incident"]
    c.setFont(SANS_BOLD, 9.5)
    c.drawString(box["x0"], box["y1"] - header_h + 14, inc["name"].upper())
    name_w = c.stringWidth(inc["name"].upper(), SANS_BOLD, 9.5)
    c.setFont(SANS_ITALIC, 9)
    c.drawString(box["x0"] + name_w + 8, box["y1"] - header_h + 14,
                 "— " + inc["blurb"])
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
    c.drawCentredString((box["x0"] + box["x1"]) / 2, box["y0"] + 40,
                        "Every unshaded cell must burn; every numbered cell, "
                        "at exactly its minute.")

    # Field-notes strip: date/time blanks, the way a report form has them.
    y = box["y0"] + 16
    c.setLineWidth(0.6)
    c.setStrokeColorRGB(*BLACK)
    c.setFont(SANS, 7.5)
    seg_w = box["width"] / 3
    for i, label in enumerate(("DATE", "STARTED", "CONTAINED")):
        x = box["x0"] + i * seg_w
        c.drawString(x, y, label)
        lw = c.stringWidth(label, SANS, 7.5)
        c.line(x + lw + 5, y - 1, x + seg_w - 14, y - 1)

    book.end_page(footer_label=BOOK_TITLE)


def draw_solution_page_facing(book, puzzle, number, tier):
    c, box = book.c, book.box()
    header_h = 30

    c.setFont(DISPLAY, 15)
    c.setFillColorRGB(*BLACK)
    c.drawString(box["x0"], box["y1"] - 12, f"SOLUTION · PUZZLE {number}")
    c.setFont(SANS, 9.5)
    c.drawRightString(box["x1"], box["y1"] - 12,
                      f"{puzzle['incident']['name'].upper()} · "
                      f"{tier['name'].upper()}")
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
    """entries: list of (number, puzzle) to place in a grid of mini
    answer-key boards (shading pattern only, no burn-time numbers -- too
    small to typeset legibly, and the shading alone confirms a solve)."""
    c, box = book.c, book.box()
    cols, rows = 3, 4
    cell_w = box["width"] / cols
    cell_h = box["height"] / rows
    for idx, (number, puzzle) in enumerate(entries):
        col = idx % cols
        row = idx // cols
        cx0 = box["x0"] + col * cell_w
        cy1 = box["y1"] - row * cell_h
        sub_box = {
            "x0": cx0 + 6, "x1": cx0 + cell_w - 6,
            "y0": cy1 - cell_h + 6, "y1": cy1 - 26,
            "width": cell_w - 12, "height": cell_h - 32,
        }
        c.setFont(SANS_BOLD, 8.5)
        c.setFillColorRGB(*BLACK)
        c.drawCentredString((sub_box["x0"] + sub_box["x1"]) / 2, cy1 - 10,
                            f"Puzzle {number}")
        c.setFont(SANS_ITALIC, 7)
        c.drawCentredString((sub_box["x0"] + sub_box["x1"]) / 2, cy1 - 19,
                            puzzle["incident"]["name"])
        draw_grid(c, sub_box, puzzle["R"], puzzle["C"], puzzle["spark"], puzzle["clues"],
                  solution=puzzle["solution"], times=None, show_numbers=False)
    book.end_page(footer_label="solutions")


def draw_solutions_divider(book):
    c = book.c
    box = book.box()
    cx = (box["x0"] + box["x1"]) / 2
    cy = box["y0"] + box["height"] / 2
    c.setFont(DISPLAY, 30)
    c.setFillColorRGB(*BLACK)
    c.drawCentredString(cx, cy + 10, "SOLUTIONS")
    c.setLineWidth(1.25)
    c.line(cx - 60, cy - 6, cx + 60, cy - 6)
    c.setFont(SANS, 10.5)
    c.drawCentredString(cx, cy - 26,
                        "shaded cells are firebreaks; the star is the spark")
    book.end_page(number=False)


# ---------------------------------------------------------------------------
# Back matter
# ---------------------------------------------------------------------------

def draw_about_page(book):
    c, box = book.c, book.box()
    y_top = box["y1"] - 4
    c.setFont(DISPLAY, 19)
    c.setFillColorRGB(*BLACK)
    c.drawString(box["x0"], y_top, "ABOUT BURNFRONT")
    c.setLineWidth(1)
    c.line(box["x0"], y_top - 8, box["x1"], y_top - 8)

    y = draw_paragraph(
        c,
        "Burnfront models the leading edge of a fire: a wavefront "
        "expanding one ring per minute from the spark, bent out of shape "
        "by the lines dug against it. The firebreaks you shade are the "
        "negative space of that front -- the puzzle is reading the shape "
        "of the fire backward from a handful of timestamps.",
        box["x0"], y_top - 26, box["width"], size=9.5, leading=13.5)
    y -= 14
    y = draw_paragraph(
        c,
        "Every case in this file honors the Line Verification Unit's "
        "rules of evidence: exactly one reconstruction fits the log, that "
        "reconstruction is reachable by forced moves alone, and every "
        "line on the record is witnessed by the timestamps themselves.",
        box["x0"], y, box["width"], size=9.5, leading=13.5)
    y -= 14
    draw_paragraph(
        c,
        "Burnfront also burns daily on the web -- the incident desk "
        "posts one fresh case a day, alongside an endless queue and a "
        "career of campaign cases for analysts working toward Hotshot "
        "certification.",
        box["x0"], y, box["width"], size=9.5, leading=13.5)

    cx = (box["x0"] + box["x1"]) / 2
    draw_flame(c, cx, box["y0"] + 60, 18, fill=BLACK)
    c.setFont(SANS, 8.5)
    c.drawCentredString(cx, box["y0"] + 40,
                        "LINE VERIFICATION UNIT · CASE FILES · VOLUME ONE")
    book.end_page(footer_label="about")


def draw_field_notes_page(book):
    c, box = book.c, book.box()
    c.setFont(DISPLAY, 14)
    c.setFillColorRGB(*BLACK)
    c.drawString(box["x0"], box["y1"] - 10, "FIELD NOTES")
    c.setLineWidth(1)
    c.line(box["x0"], box["y1"] - 18, box["x1"], box["y1"] - 18)
    c.setLineWidth(0.5)
    y = box["y1"] - 44
    while y > box["y0"] + 8:
        c.line(box["x0"], y, box["x1"], y)
        y -= 22
    book.end_page(footer_label="field notes")


# ---------------------------------------------------------------------------
# Build
# ---------------------------------------------------------------------------

def build(args):
    counts = {t["key"]: getattr(args, t["key"]) for t in TIERS}
    sections = [t for t in TIERS if counts[t["key"]] > 0]

    specs = []
    section_ranges = []
    for t in sections:
        start = len(specs)
        for i in range(counts[t["key"]]):
            specs.append((t["R"], t["C"], t["N"], args.seed + start + i))
        section_ranges.append((t, start, counts[t["key"]]))

    total = len(specs)
    if total == 0:
        print("Nothing to generate: all section counts are zero.", file=sys.stderr)
        sys.exit(1)

    def progress(done, todo, spec):
        print(f"  generated {done}/{todo} (size {spec[0]}x{spec[1]}, N={spec[2]}, seed={spec[3]})")

    print(f"Generating {total} puzzles (cached results are reused)...")
    puzzles = generate_batch(specs, workers=args.workers, progress=progress)
    for puzzle, incident in zip(puzzles, assign_incidents(total, args.seed)):
        puzzle["incident"] = incident

    covers = args.covers == "in-book"
    edition = f"First edition · {total} cases · seed {args.seed}"
    cover_meta = {"count": total, "edition": edition}

    page_size = TRIM_SIZES[args.trim]
    book = Book(args.output, page_size, folio_offset=2 if covers else 0)

    if covers:
        draw_front_cover_page(book, cover_meta)
        draw_blank_page(book)          # inside front cover

    draw_half_title_page(book)         # folio 1 (recto)
    draw_epigraph_page(book)           # folio 2
    draw_title_page(book, total)       # folio 3
    draw_colophon_page(book, counts, edition)   # folio 4
    draw_rules_page(book)              # folio 5
    draw_worked_case_page(book)        # folio 6

    numbered = []  # (number, puzzle) in book order
    n = 1
    for tier, start, count in section_ranges:
        draw_section_divider(book, tier, n, count)
        for i in range(count):
            puzzle = puzzles[start + i]
            if args.solutions == "facing":
                book.ensure_recto()
            draw_puzzle_page(book, puzzle, n, tier)
            if args.solutions == "facing":
                draw_solution_page_facing(book, puzzle, n, tier)
            numbered.append((n, puzzle))
            n += 1

    if args.solutions == "end":
        draw_solutions_divider(book)
        per_page = 12
        for i in range(0, len(numbered), per_page):
            draw_solutions_compact_page(book, numbered[i:i + per_page])

    draw_about_page(book)

    # Pad so the physical page count comes out even -- duplex printing
    # and POD both want an even count, and the back cover (when present)
    # must land on a verso. The covers block adds an even 2 pages, so one
    # Field Notes page at most. (book.page_no is the NEXT page to draw:
    # even page_no means an odd number of pages so far.)
    if book.page_no % 2 == 0:
        draw_field_notes_page(book)
    if covers:
        draw_blank_page(book)          # inside back cover
        draw_back_cover_page(book, cover_meta)

    book.save()
    pages = book.page_no - 1
    print(f"Wrote {args.output} ({pages} pages, {total} puzzles).")
    if covers:
        print(f"  Interior page count (rebuild with --covers none for a "
              f"POD/KDP interior): {pages - 4} pages.")
    else:
        print(f"  Interior page count for generate_cover.py: {pages} pages.")


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
    ap.add_argument("--covers", choices=["in-book", "none"], default="in-book",
                    help="'in-book' (default): full-color front and back "
                         "cover art as the first and last pages -- right "
                         "for the digital / home-print edition. 'none': "
                         "bare interior for print-on-demand, where the "
                         "cover is the separate wraparound PDF from "
                         "generate_cover.py.")
    ap.add_argument("--seed", type=int, default=1000, help="base seed for reproducible generation")
    ap.add_argument("--workers", type=int, default=None, help="parallel generator processes (default: CPU count)")
    return ap.parse_args()


if __name__ == "__main__":
    build(parse_args())
