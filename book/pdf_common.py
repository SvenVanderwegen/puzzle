"""
Shared PDF building blocks for the Burnfront print book: page geometry,
fonts, color tokens, the flame mark, and the black-and-white grid renderer.

Print constraints driving every choice here: interior pages are B/W-only
(no grayscale, no gradients, no transparency) so every fill and stroke
uses pure black (0, 0, 0) or pure white -- nothing in between -- and all
strokes are solid, sharp lines at print-safe widths (no hairlines that
can drop out at 300 dpi).

The COVER_* tokens below are the one sanctioned exception: covers are
printed in color on separate stock, so cover art (cover_art.py) may use
them freely. Interior drawing must keep to BLACK/WHITE.
"""

import math
import os
import re

from reportlab.lib.units import inch, mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont

BLACK = (0, 0, 0)
WHITE = (1, 1, 1)


def _hex(color):
    color = color.lstrip("#")
    return tuple(int(color[i:i + 2], 16) / 255 for i in (0, 2, 4))


# Cover-only palette: the game's Case File design tokens
# (resources/css/app.css on the Burnfront web app).
COVER_DESK = _hex("141010")        # char-black desk
COVER_FOLDER = _hex("1e1815")
COVER_RULE = _hex("3a2f24")        # faint rule lines on dark
COVER_STOCK = _hex("f1e7d5")       # paper stock
COVER_ASH = _hex("b6a890")
COVER_ASH_DIM = _hex("7c6f5d")
COVER_EMBER = _hex("ff7a2d")
COVER_EMBER_HI = _hex("ff9a4d")
COVER_EMBER_DEEP = _hex("c85618")
COVER_FLAME = _hex("ffd06b")

FONTS_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "fonts")

SANS = "LiberationSans"
SANS_BOLD = "LiberationSans-Bold"
SANS_ITALIC = "LiberationSans-Italic"
DISPLAY = "Staatliches"   # the game's display face, for titles and covers

_FONTS_REGISTERED = False


def register_fonts():
    """Embed every face so the PDF is self-contained for print (no
    reliance on the reader/RIP having a system font)."""
    global _FONTS_REGISTERED
    if _FONTS_REGISTERED:
        return
    pdfmetrics.registerFont(TTFont(SANS, os.path.join(FONTS_DIR, "LiberationSans-Regular.ttf")))
    pdfmetrics.registerFont(TTFont(SANS_BOLD, os.path.join(FONTS_DIR, "LiberationSans-Bold.ttf")))
    pdfmetrics.registerFont(TTFont(SANS_ITALIC, os.path.join(FONTS_DIR, "LiberationSans-Italic.ttf")))
    pdfmetrics.registerFont(TTFont(DISPLAY, os.path.join(FONTS_DIR, "Staatliches-Regular.ttf")))
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


def draw_page_number(c, geo, page_no, folio, footer_label=""):
    """Footer: running header label on the outside, folio (page number) at
    the outer edge -- the standard book layout so numbers stay visible
    right up to the trim, not lost near the gutter. `page_no` decides
    recto/verso geometry; `folio` is the number actually printed (they
    differ when unnumbered cover pages precede the content block)."""
    box = geo.content_box(page_no)
    y = geo.bottom * 0.45
    c.setFont(SANS, 8.5)
    c.setFillColorRGB(*BLACK)
    if page_no % 2 == 1:
        if footer_label:
            c.drawString(box["x0"], y, footer_label.upper())
        c.drawRightString(box["x1"], y, str(folio))
    else:
        c.drawString(box["x0"], y, str(folio))
        if footer_label:
            c.drawRightString(box["x1"], y, footer_label.upper())


# ---------------------------------------------------------------------------
# The Burnfront flame mark
# ---------------------------------------------------------------------------
# Same outline the web app uses (resources/js/Components/FlameGlyph.vue):
# one path for the ember body, a scaled copy for the hot core. The SVG
# path's arcs are converted to cubic beziers below so the mark is a real
# vector path here too.

FLAME_SVG_PATH = (
    "M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 "
    "2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153"
    ".433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"
)

_NUM_RE = re.compile(r"[-+]?(?:\d*\.\d+|\d+\.?)(?:[eE][-+]?\d+)?")


def _arc_to_beziers(x1, y1, rx, ry, phi, large_arc, sweep, x2, y2):
    """Convert an SVG elliptical arc (endpoint parameterization) to a list
    of cubic bezier segments [(c1, c2, end), ...]. Standard W3C algorithm."""
    if rx == 0 or ry == 0 or (x1 == x2 and y1 == y2):
        return [((x1, y1), (x2, y2), (x2, y2))]
    phi = math.radians(phi)
    cosp, sinp = math.cos(phi), math.sin(phi)
    dx, dy = (x1 - x2) / 2, (y1 - y2) / 2
    x1p = cosp * dx + sinp * dy
    y1p = -sinp * dx + cosp * dy
    rx, ry = abs(rx), abs(ry)
    lam = (x1p / rx) ** 2 + (y1p / ry) ** 2
    if lam > 1:
        s = math.sqrt(lam)
        rx, ry = rx * s, ry * s
    num = rx * rx * ry * ry - rx * rx * y1p * y1p - ry * ry * x1p * x1p
    den = rx * rx * y1p * y1p + ry * ry * x1p * x1p
    coef = math.sqrt(max(0.0, num / den)) if den else 0.0
    if large_arc == sweep:
        coef = -coef
    cxp = coef * rx * y1p / ry
    cyp = -coef * ry * x1p / rx
    cx = cosp * cxp - sinp * cyp + (x1 + x2) / 2
    cy = sinp * cxp + cosp * cyp + (y1 + y2) / 2

    def angle(ux, uy, vx, vy):
        dot = ux * vx + uy * vy
        length = math.hypot(ux, uy) * math.hypot(vx, vy)
        ang = math.acos(max(-1.0, min(1.0, dot / length)))
        if ux * vy - uy * vx < 0:
            ang = -ang
        return ang

    theta1 = angle(1, 0, (x1p - cxp) / rx, (y1p - cyp) / ry)
    dtheta = angle((x1p - cxp) / rx, (y1p - cyp) / ry,
                   (-x1p - cxp) / rx, (-y1p - cyp) / ry)
    if not sweep and dtheta > 0:
        dtheta -= 2 * math.pi
    elif sweep and dtheta < 0:
        dtheta += 2 * math.pi

    n_segs = max(1, int(math.ceil(abs(dtheta) / (math.pi / 2))))
    delta = dtheta / n_segs
    t = 4 / 3 * math.tan(delta / 4)
    beziers = []
    theta = theta1
    for _ in range(n_segs):
        cos1, sin1 = math.cos(theta), math.sin(theta)
        cos2, sin2 = math.cos(theta + delta), math.sin(theta + delta)
        p1x = cx + cosp * rx * cos1 - sinp * ry * sin1
        p1y = cy + sinp * rx * cos1 + cosp * ry * sin1
        p2x = cx + cosp * rx * cos2 - sinp * ry * sin2
        p2y = cy + sinp * rx * cos2 + cosp * ry * sin2
        d1x = -rx * sin1 * cosp - ry * cos1 * sinp
        d1y = -rx * sin1 * sinp + ry * cos1 * cosp
        d2x = -rx * sin2 * cosp - ry * cos2 * sinp
        d2y = -rx * sin2 * sinp + ry * cos2 * cosp
        beziers.append(((p1x + t * d1x, p1y + t * d1y),
                        (p2x - t * d2x, p2y - t * d2y),
                        (p2x, p2y)))
        theta += delta
    return beziers


def _parse_flame_path():
    """Parse FLAME_SVG_PATH (commands M, c, a/A, z only) into a list of
    ('move', pt) / ('curve', c1, c2, end) / ('close',) ops in SVG
    coordinates (24x24 viewBox, y down)."""
    ops = []
    tokens = re.findall(r"[MmCcAaZz]|" + _NUM_RE.pattern, FLAME_SVG_PATH)
    i = 0
    cur = (0.0, 0.0)

    def read(n):
        nonlocal i
        vals = [float(tokens[i + k]) for k in range(n)]
        i += n
        return vals

    while i < len(tokens):
        cmd = tokens[i]
        i += 1
        if cmd in "Mm":
            x, y = read(2)
            if cmd == "m":
                x, y = cur[0] + x, cur[1] + y
            cur = (x, y)
            ops.append(("move", cur))
        elif cmd in "Cc":
            while i < len(tokens) and tokens[i] not in "MmCcAaZz":
                x1, y1, x2, y2, x, y = read(6)
                if cmd == "c":
                    x1, y1 = cur[0] + x1, cur[1] + y1
                    x2, y2 = cur[0] + x2, cur[1] + y2
                    x, y = cur[0] + x, cur[1] + y
                ops.append(("curve", (x1, y1), (x2, y2), (x, y)))
                cur = (x, y)
        elif cmd in "Aa":
            while i < len(tokens) and tokens[i] not in "MmCcAaZz":
                rx, ry, rot, large, sweep, x, y = read(7)
                if cmd == "a":
                    x, y = cur[0] + x, cur[1] + y
                for c1, c2, end in _arc_to_beziers(cur[0], cur[1], rx, ry, rot,
                                                   int(large), int(sweep), x, y):
                    ops.append(("curve", c1, c2, end))
                cur = (x, y)
        elif cmd in "Zz":
            ops.append(("close",))
    return ops


_FLAME_OPS = _parse_flame_path()


def draw_flame(c, cx, cy, size, fill=BLACK, core=None):
    """Draw the Burnfront flame mark centered on (cx, cy), `size` points
    tall. `core`, if given, adds the brighter inner flame the way the web
    glyph does (color covers only -- interior use stays single-tone)."""

    def emit(scale, ox, oy):
        # SVG viewBox is 24x24 with y down; flip y for PDF.
        s = size / 24.0 * scale
        x0 = cx - size / 2 + ox * (size / 24.0)
        y0 = cy + size / 2 - oy * (size / 24.0)
        p = c.beginPath()
        for op in _FLAME_OPS:
            if op[0] == "move":
                p.moveTo(x0 + op[1][0] * s, y0 - op[1][1] * s)
            elif op[0] == "curve":
                (x1, y1), (x2, y2), (x3, y3) = op[1], op[2], op[3]
                p.curveTo(x0 + x1 * s, y0 - y1 * s,
                          x0 + x2 * s, y0 - y2 * s,
                          x0 + x3 * s, y0 - y3 * s)
            else:
                p.close()
        c.drawPath(p, fill=1, stroke=0)

    c.setFillColorRGB(*fill)
    emit(1.0, 0, 0)
    if core is not None:
        c.setFillColorRGB(*core)
        emit(0.55, 5.4, 8.2)


# ---------------------------------------------------------------------------
# Grid rendering
# ---------------------------------------------------------------------------

OUTER_LINE_WIDTH = 1.75
INNER_LINE_WIDTH = 0.6
STAR_OUTER_RATIO = 0.40   # relative to cell size
STAR_INNER_RATIO = 0.16


def _star_points(cx, cy, r_out, r_in):
    pts = []
    for i in range(10):
        angle = math.pi / 2 + i * math.pi / 5
        r = r_out if i % 2 == 0 else r_in
        pts.append((cx + r * math.cos(angle), cy + r * math.sin(angle)))
    return pts


def draw_star(c, cx, cy, cell_size, fill=BLACK):
    r_out = cell_size * STAR_OUTER_RATIO
    r_in = cell_size * STAR_INNER_RATIO
    pts = _star_points(cx, cy, r_out, r_in)
    p = c.beginPath()
    p.moveTo(*pts[0])
    for pt in pts[1:]:
        p.lineTo(*pt)
    p.close()
    c.setFillColorRGB(*fill)
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


def draw_paragraph(c, text, x, top_y, width, font=SANS, size=10, leading=None,
                   align="left", color=BLACK):
    """Draws a word-wrapped paragraph starting with its top line's baseline
    at top_y. Returns the y coordinate just below the last line."""
    leading = leading or size * 1.35
    lines = wrap_text(text, font, size, width)
    c.setFont(font, size)
    c.setFillColorRGB(*color)
    y = top_y
    for line in lines:
        if align == "center":
            c.drawCentredString(x + width / 2, y, line)
        else:
            c.drawString(x, y, line)
        y -= leading
    return y


def draw_grid(c, box, R, C, spark, clues, solution=None, times=None,
              anchor="center", max_cell=None, show_numbers=True,
              highlight=None, open_marks=None):
    """Draw one Firebreak grid inside `box` (a dict with x0/y0/width/height
    from PageGeometry.content_box, or an equivalent manually built dict).

    R, C: rows, cols. spark: (r, c). clues: {(r, c): minute}.
    solution: optional {(r, c): SHADED/OPEN} assignment (firebreak.py state
    values); UNKNOWN cells are simply left blank, so a partial state from a
    walkthrough stage renders too. times: optional {(r, c): minute} burn
    times for the solution's open cells. highlight: optional set of cells
    ringed with an inset square -- "these are the cells this step deduced".
    open_marks: optional set of cells to mark with a small centered dot --
    "deduced open, burn time not yet known".

    Returns the pixel bounding box actually used: (gx0, gy0, gx1, gy1).
    """
    from firebreak import SHADED

    highlight = highlight or set()
    open_marks = open_marks or set()
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
            if (r, col) in open_marks:
                c.setFillColorRGB(*BLACK)
                c.circle(cx, cy, cell * 0.07, fill=1, stroke=0)
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

    # 5. Highlight rings: an inset square outline. On a shaded (black)
    # cell the ring is white so it stays visible; both are pure B/W.
    if highlight:
        c.setLineWidth(1.1)
        for (r, col) in highlight:
            x, y = cell_xy(r, col)
            inset = cell * 0.14
            on_black = solution is not None and solution.get((r, col)) == SHADED
            c.setStrokeColorRGB(*(WHITE if on_black else BLACK))
            c.rect(x + inset, y + inset, cell - 2 * inset, cell - 2 * inset,
                   fill=0, stroke=1)
        c.setStrokeColorRGB(*BLACK)

    return gx0, gy0, gx0 + gw, gy0 + gh
