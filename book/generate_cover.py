#!/usr/bin/env python3
"""
Build the print-on-demand wraparound cover for the Burnfront book: one
PDF page laid out back cover | spine | front cover, with 0.125in bleed on
all four outside edges and a spine width computed from the interior page
count -- the file KDP/IngramSpark ask for alongside a bare interior
(generate_book.py --covers none).

Spine width uses KDP's per-page paper thickness for B/W interiors:
0.002252in on white paper, 0.0025in on cream. KDP only allows spine text
on books of ~80+ pages; below that the spine is left as bare desk color.

Usage:
  python3 generate_cover.py --interior burnfront_book.pdf   # reads page count
  python3 generate_cover.py --pages 96                       # or state it
  python3 generate_cover.py --pages 96 --trim 6x9 --paper cream -o cover.pdf
"""

import argparse
import os
import re
import sys

from reportlab.lib.units import inch
from reportlab.pdfgen import canvas

sys.path.insert(0, os.path.join(
    os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "reference"))

import cover_art
from pdf_common import TRIM_SIZES, register_fonts

BLEED = 0.125 * inch
PAPER_THICKNESS = {"white": 0.002252 * inch, "cream": 0.0025 * inch}
SPINE_TEXT_MIN_PAGES = 80


def count_pdf_pages(path):
    """Page count of a PDF without extra dependencies: count page objects
    ("/Type /Page" excluding the "/Type /Pages" tree nodes). Reliable for
    the PDFs reportlab writes; for anything exotic, pass --pages."""
    data = open(path, "rb").read()
    return len(re.findall(rb"/Type\s*/Page[^s]", data))


def demo_dict():
    import firebreak as fb
    pz = fb.demo_puzzle()
    state = fb.deduction_solve(pz)
    times = fb.bfs_times(pz, lambda x: state[x] == fb.OPEN) if state else {}
    return {"R": pz.R, "C": pz.C, "spark": pz.spark, "clues": pz.clues,
            "solution": state, "times": times}


def build(args):
    if args.pages:
        pages = args.pages
    elif args.interior:
        pages = count_pdf_pages(args.interior)
        print(f"{args.interior}: {pages} interior pages")
    else:
        print("Pass --pages N or --interior interior.pdf", file=sys.stderr)
        sys.exit(1)
    if pages % 2 != 0:
        print(f"Warning: {pages} is odd; POD interiors are even-paged. "
              f"Did you build with --covers none?", file=sys.stderr)

    trim_w, trim_h = TRIM_SIZES[args.trim]
    spine = pages * PAPER_THICKNESS[args.paper]
    cover_w = 2 * trim_w + spine + 2 * BLEED
    cover_h = trim_h + 2 * BLEED

    register_fonts()
    c = canvas.Canvas(args.output, pagesize=(cover_w, cover_h))
    meta = {"count": args.count, "edition": args.edition}

    # Back panel: bleeds off the left/top/bottom, meets the spine cleanly.
    back_rect = (0, 0, BLEED + trim_w, cover_h)
    back_trim = (BLEED, BLEED, BLEED + trim_w, BLEED + trim_h)
    cover_art.draw_back(c, back_rect, back_trim, meta, demo=demo_dict(),
                        barcode=not args.no_barcode_box)

    spine_rect = (BLEED + trim_w, 0, BLEED + trim_w + spine, cover_h)
    cover_art.draw_spine(c, spine_rect, (BLEED, BLEED + trim_h),
                         with_text=pages >= SPINE_TEXT_MIN_PAGES)

    front_rect = (BLEED + trim_w + spine, 0, cover_w, cover_h)
    front_trim = (BLEED + trim_w + spine, BLEED,
                  BLEED + trim_w + spine + trim_w, BLEED + trim_h)
    cover_art.draw_front(c, front_rect, front_trim, meta)

    c.showPage()
    c.save()
    print(f"Wrote {args.output}: {cover_w / inch:.3f} x {cover_h / inch:.3f} in "
          f"({args.trim} trim, {pages}p {args.paper} paper, "
          f"spine {spine / inch:.3f} in"
          f"{', spine text' if pages >= SPINE_TEXT_MIN_PAGES else ', no spine text'}).")


def parse_args():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("-o", "--output", default="burnfront_cover.pdf")
    ap.add_argument("--trim", choices=sorted(TRIM_SIZES), default="6x9")
    ap.add_argument("--interior", help="interior PDF to read the page count from")
    ap.add_argument("--pages", type=int, help="interior page count (overrides --interior)")
    ap.add_argument("--paper", choices=sorted(PAPER_THICKNESS), default="white")
    ap.add_argument("--count", type=int, default=40,
                    help="puzzle count shown on the front cover")
    ap.add_argument("--edition", default="First edition",
                    help="edition line on the back cover")
    ap.add_argument("--no-barcode-box", action="store_true",
                    help="skip the white ISBN/barcode reserve on the back")
    return ap.parse_args()


if __name__ == "__main__":
    build(parse_args())
