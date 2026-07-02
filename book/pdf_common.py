"""
Shared PDF building blocks for the Burnfront print book: page geometry,
fonts, and the black-and-white grid renderer.

Print constraints driving every choice here: interior pages are B/W-only
(no grayscale, no gradients, no transparency) so every fill and stroke
uses pure black (0, 0, 0) or pure white -- nothing in between -- and all
strokes are solid, sharp lines at print-safe widths (no hairlines that
can drop out at 300 dpi).
"""

import os

from reportlab.lib.units import inch, mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont

BLACK = (0, 0, 0)
WHITE = (1, 1, 1)

FONTS_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "fonts")

SANS = "LiberationSans"
SANS_BOLD = "LiberationSans-Bold"
SANS_ITALIC = "LiberationSans-Italic"

_FONTS_REGISTERED = False


def register_fonts():
    """Embed Liberation Sans so the PDF is self-contained for print (no
    reliance on the reader/RIP having a system font)."""
    global _FONTS_REGISTERED
    if _FONTS_REGISTERED:
        return
    pdfmetrics.registerFont(TTFont(SANS, os.path.join(FONTS_DIR, "LiberationSans-Regular.ttf")))
    pdfmetrics.registerFont(TTFont(SANS_BOLD, os.path.join(FONTS_DIR, "LiberationSans-Bold.ttf")))
    pdfmetrics.registerFont(TTFont(SANS_ITALIC, os.path.join(FONTS_DIR, "LiberationSans-Italic.ttf")))
    _FONTS_REGISTERED = True


# ---------------------------------------------------------------------------
# Trim sizes (interior page size). 6x9in is the standard KDP puzzle-book
# trim; letter/A4 are offered for home printing.
# ---------------------------------------------------------------------------

TRIM_SIZES = {
    "6x9": (6 * inch, 9 * inch),
    "8.5x8.5": (8.5 * inch, 8.5 * inch),
    "letter": (8.5 * inch, 11 * inch),
    "a4": (210 * mm, 297 * mm),
}


class PageGeometry:
    """Margins for a book meant to be perfect-bound: the inside (gutter)
    margin is wider than the outside margin so text doesn't vanish into
    the spine, and left/right swap on facing pages."""

    def __init__(self, page_size, outer=0.5 * inch, top=0.7 * inch,
                 bottom=0.75 * inch, gutter=0.75 * inch):
        self.width, self.height = page_size
        self.outer = outer
        self.top = top
        self.bottom = bottom
        self.gutter = gutter

    def margins_for_page(self, page_no):
        """Returns (left, right, top, bottom) for a 1-indexed page number.
        Odd pages are recto (right-hand): gutter on the left. Even pages
        are verso (left-hand): gutter on the right."""
        if page_no % 2 == 1:
            return self.gutter, self.outer, self.top, self.bottom
        return self.outer, self.gutter, self.top, self.bottom

    def content_box(self, page_no):
        left, right, top, bottom = self.margins_for_page(page_no)
        return {
            "x0": left,
            "y0": bottom,
            "x1": self.width - right,
            "y1": self.height - top,
            "width": self.width - left - right,
            "height": self.height - top - bottom,
        }


def draw_page_number(c, geo, page_no, footer_label=""):
    """Footer: running header label on the outside, folio (page number) at
    the outer edge -- the standard book layout so numbers stay visible
    right up to the trim, not lost near the gutter."""
    box = geo.content_box(page_no)
    y = geo.bottom * 0.45
    c.setFont(SANS, 8.5)
    c.setFillColorRGB(*BLACK)
    if page_no % 2 == 1:
        if footer_label:
            c.drawString(box["x0"], y, footer_label.upper())
        c.drawRightString(box["x1"], y, str(page_no))
    else:
        c.drawString(box["x0"], y, str(page_no))
        if footer_label:
            c.drawRightString(box["x1"], y, footer_label.upper())


# ---------------------------------------------------------------------------
# Grid rendering
# ---------------------------------------------------------------------------

OUTER_LINE_WIDTH = 1.75
INNER_LINE_WIDTH = 0.6
STAR_OUTER_RATIO = 0.40   # relative to cell size
STAR_INNER_RATIO = 0.16


def _star_points(cx, cy, r_out, r_in):
    import math
    pts = []
    for i in range(10):
        angle = math.pi / 2 + i * math.pi / 5
        r = r_out if i % 2 == 0 else r_in
        pts.append((cx + r * math.cos(angle), cy + r * math.sin(angle)))
    return pts


def draw_star(c, cx, cy, cell_size):
    r_out = cell_size * STAR_OUTER_RATIO
    r_in = cell_size * STAR_INNER_RATIO
    pts = _star_points(cx, cy, r_out, r_in)
    p = c.beginPath()
    p.moveTo(*pts[0])
    for pt in pts[1:]:
        p.lineTo(*pt)
    p.close()
    c.setFillColorRGB(*BLACK)
    c.drawPath(p, fill=1, stroke=0)


def grid_cell_size(box, R, C, max_cell=None):
    cell = min(box["width"] / C, box["height"] / R)
    if max_cell is not None:
        cell = min(cell, max_cell)
    return cell


def wrap_text(text, font, size, max_width):
    words = text.split()
    lines, cur = [], ""
    for w in words:
        trial = (cur + " " + w).strip()
        if pdfmetrics.stringWidth(trial, font, size) <= max_width:
            cur = trial
        else:
            if cur:
                lines.append(cur)
            cur = w
    if cur:
        lines.append(cur)
    return lines


def draw_paragraph(c, text, x, top_y, width, font=SANS, size=10, leading=None, align="left"):
    """Draws a word-wrapped paragraph starting with its top line's baseline
    at top_y. Returns the y coordinate just below the last line."""
    leading = leading or size * 1.35
    lines = wrap_text(text, font, size, width)
    c.setFont(font, size)
    c.setFillColorRGB(*BLACK)
    y = top_y
    for line in lines:
        if align == "center":
            c.drawCentredString(x + width / 2, y, line)
        else:
            c.drawString(x, y, line)
        y -= leading
    return y


def draw_grid(c, box, R, C, spark, clues, solution=None, times=None,
              anchor="center", max_cell=None, show_numbers=True):
    """Draw one Firebreak grid inside `box` (a dict with x0/y0/width/height
    from PageGeometry.content_box, or an equivalent manually built dict).

    R, C: rows, cols. spark: (r, c). clues: {(r, c): minute}.
    solution: optional {(r, c): SHADED/OPEN} full assignment (firebreak.py
    state values). times: optional {(r, c): minute} burn times for the
    solution's open cells.

    Returns the pixel bounding box actually used: (gx0, gy0, gx1, gy1).
    """
    from firebreak import SHADED

    cell = grid_cell_size(box, R, C, max_cell=max_cell)
    gw, gh = C * cell, R * cell

    if anchor == "center":
        gx0 = box["x0"] + (box["width"] - gw) / 2
        gy0 = box["y0"] + (box["height"] - gh) / 2
    elif anchor == "top":
        gx0 = box["x0"] + (box["width"] - gw) / 2
        gy0 = box["y1"] - gh
    else:
        gx0 = box["x0"]
        gy0 = box["y0"]

    def cell_xy(r, col):
        # row 0 is the top row on the page; PDF y grows upward.
        x = gx0 + col * cell
        y = gy0 + (R - 1 - r) * cell
        return x, y

    # 1. Fills first (solution shading), so grid lines stay crisp on top.
    if solution is not None:
        c.setFillColorRGB(*BLACK)
        for r in range(R):
            for col in range(C):
                if solution.get((r, col)) == SHADED:
                    x, y = cell_xy(r, col)
                    c.rect(x, y, cell, cell, fill=1, stroke=0)

    # 2. Grid lines.
    c.setStrokeColorRGB(*BLACK)
    c.setLineWidth(INNER_LINE_WIDTH)
    c.setLineJoin(0)
    c.setLineCap(0)
    for i in range(R + 1):
        y = gy0 + i * cell
        c.line(gx0, y, gx0 + gw, y)
    for j in range(C + 1):
        x = gx0 + j * cell
        c.line(x, gy0, x, gy0 + gh)

    # 3. Thick outer border on top of the thin lines.
    c.setLineWidth(OUTER_LINE_WIDTH)
    c.rect(gx0, gy0, gw, gh, fill=0, stroke=1)

    # 4. Star and numbers.
    num_size = cell * 0.42
    for r in range(R):
        for col in range(C):
            x, y = cell_xy(r, col)
            cx, cy = x + cell / 2, y + cell / 2
            if (r, col) == spark:
                draw_star(c, cx, cy, cell)
                continue
            if not show_numbers:
                continue
            if (r, col) in clues:
                c.setFont(SANS_BOLD, num_size)
                c.setFillColorRGB(*BLACK)
                c.drawCentredString(cx, cy - num_size * 0.35, str(clues[(r, col)]))
            elif times is not None and (r, col) in times:
                c.setFont(SANS, num_size * 0.82)
                c.setFillColorRGB(*BLACK)
                c.drawCentredString(cx, cy - num_size * 0.82 * 0.35, str(times[(r, col)]))

    return gx0, gy0, gx0 + gw, gy0 + gh
