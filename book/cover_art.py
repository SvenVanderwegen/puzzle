"""
Full-color cover art for the Burnfront book, in the game's Case File
visual system (char-black desk, paper stock, ember ramp, Staatliches).

Covers are the one place color is allowed -- they print on separate stock
from the strictly-B/W interior. Everything here is still pure vector:
flat fills and strokes only, no raster images, no gradients (the ember
"glow" is stepped per burn minute, which is truer to the mechanic
anyway), no transparency.

The front art is not decoration: it is a real burn-time field. A BFS runs
from the spark across the grid, firebreak cells block it, and every cell
is tinted by the minute it caught fire -- so the wavefront visibly bends
around the containment lines exactly the way it does on a board.
"""

import math

from pdf_common import (
    WHITE, DISPLAY, SANS, SANS_BOLD, SANS_ITALIC,
    COVER_ASH, COVER_ASH_DIM, COVER_DESK, COVER_EMBER, COVER_EMBER_DEEP,
    COVER_EMBER_HI, COVER_FLAME, COVER_RULE, COVER_STOCK,
    draw_flame, draw_grid, draw_star,
)
from reportlab.pdfbase import pdfmetrics

TITLE = "BURNFRONT"
TAGLINE = "a logic puzzle of fire, distance, and deduction"
UNIT_LINE = "LINE VERIFICATION UNIT · CASE FILES"
TIER_LINE = "LOOKOUT 5×5   ·   CREW 6×6   ·   HOTSHOT 7×7"

# Ember ramp, hottest (minute 0) first; cells past the ramp fade into the
# desk. Stepped, not blended -- each burn minute is one flat tone.
_RAMP = [
    COVER_FLAME,
    (1.0, 0.72, 0.35),
    COVER_EMBER_HI,
    COVER_EMBER,
    (0.91, 0.42, 0.14),
    COVER_EMBER_DEEP,
    (0.62, 0.27, 0.09),
    (0.45, 0.20, 0.08),
    (0.33, 0.15, 0.07),
    (0.24, 0.12, 0.07),
    (0.18, 0.10, 0.07),
]
_COLD = (0.13, 0.09, 0.07)   # burned long ago / not yet reached


def _tracked_width(text, font, size, track):
    return pdfmetrics.stringWidth(text, font, size) + track * max(0, len(text) - 1)


def draw_tracked(c, x, y, text, font, size, track, color, align="left"):
    """Letterspaced string; `track` in points between glyphs."""
    total = _tracked_width(text, font, size, track)
    if align == "center":
        x -= total / 2
    elif align == "right":
        x -= total
    c.setFont(font, size)
    c.setFillColorRGB(*color)
    cx = x
    for ch in text:
        c.drawString(cx, y, ch)
        cx += pdfmetrics.stringWidth(ch, font, size) + track


def _fit_display(text, max_width, start=72.0):
    size = start
    while size > 8 and pdfmetrics.stringWidth(text, DISPLAY, size) > max_width:
        size -= 1
    return size


def _rule_grid(c, rect, cell):
    """Faint graph-paper rules across a dark panel."""
    x0, y0, x1, y1 = rect
    c.setStrokeColorRGB(*COVER_RULE)
    c.setLineWidth(0.4)
    x = x0
    while x <= x1 + 0.1:
        c.line(x, y0, x, y1)
        x += cell
    y = y0
    while y <= y1 + 0.1:
        c.line(x0, y, x1, y)
        y += cell


def _burn_field(cols, rows, spark, breaks):
    """BFS burn times over a cols x rows field; breaks block."""
    times = {spark: 0}
    frontier = [spark]
    t = 0
    while frontier:
        t += 1
        nxt = []
        for (r, col) in frontier:
            for dr, dc in ((1, 0), (-1, 0), (0, 1), (0, -1)):
                n = (r + dr, col + dc)
                if (0 <= n[0] < rows and 0 <= n[1] < cols
                        and n not in times and n not in breaks):
                    times[n] = t
                    nxt.append(n)
        frontier = nxt
    return times


# ---------------------------------------------------------------------------
# Front cover
# ---------------------------------------------------------------------------

def draw_front(c, rect, trim, meta):
    """`rect`: full panel including any bleed. `trim`: the visible trim
    box; all composition is relative to it. meta: {"count": int,
    "edition": str}."""
    rx0, ry0, rx1, ry1 = rect
    tx0, ty0, tx1, ty1 = trim
    tw, th = tx1 - tx0, ty1 - ty0

    c.setFillColorRGB(*COVER_DESK)
    c.rect(rx0, ry0, rx1 - rx0, ry1 - ry0, fill=1, stroke=0)

    # The fire field: a grid whose cells are tinted by BFS burn minute,
    # bent around two hand-placed containment lines. It fills the lower
    # part of the panel edge-to-edge (into the bleed).
    cell = tw / 9.0
    field_top = ty0 + th * 0.52
    cols = int(math.ceil((rx1 - rx0) / cell)) + 1
    rows = int(math.ceil((field_top - ry0) / cell)) + 1
    fx0 = rx0
    fy0 = ry0

    _rule_grid(c, rect, cell)

    # Grid coordinates: row 0 at the bottom of the field. Spark sits just
    # right of center, a third of the way up the field.
    spark = (max(1, int(rows * 0.38)), int(cols * 0.56))
    breaks = {
        # Line 1: a staggered cut shielding the upper-left quarter.
        (spark[0] + 2, spark[1] - 3), (spark[0] + 3, spark[1] - 3),
        (spark[0] + 4, spark[1] - 2), (spark[0] + 5, spark[1] - 2),
        (spark[0] + 5, spark[1] - 1), (spark[0] + 6, spark[1] - 1),
        # Line 2: a short hook to the right, below the title block.
        (spark[0] + 3, spark[1] + 2), (spark[0] + 3, spark[1] + 3),
        (spark[0] + 2, spark[1] + 3),
        # Line 3: a stub protecting the bottom-left corner.
        (spark[0] - 2, spark[1] - 4), (spark[0] - 3, spark[1] - 4),
        (spark[0] - 3, spark[1] - 5),
    }
    breaks = {b for b in breaks if 0 <= b[0] < rows and 0 <= b[1] < cols}

    times = _burn_field(cols, rows, spark, breaks)
    gap = cell * 0.10

    def cell_rect(r, col):
        x = fx0 + col * cell
        y = fy0 + r * cell
        return x + gap / 2, y + gap / 2, cell - gap, cell - gap

    for r in range(rows):
        for col in range(cols):
            x, y, w, h = cell_rect(r, col)
            if y > field_top:
                continue
            if (r, col) in breaks:
                c.setFillColorRGB(*COVER_STOCK)
            elif (r, col) in times:
                t = times[(r, col)]
                c.setFillColorRGB(*(_RAMP[t] if t < len(_RAMP) else _COLD))
            else:
                # walled off, never burns: bare desk with a faint outline
                c.setStrokeColorRGB(*COVER_RULE)
                c.setLineWidth(0.6)
                c.roundRect(x, y, w, h, cell * 0.08, fill=0, stroke=1)
                continue
            c.roundRect(x, y, w, h, cell * 0.08, fill=1, stroke=0)

    # A few "sensor ping" cells: the timestamps the analyst works from.
    candidates = sorted(
        ((t, rc) for rc, t in times.items()
         if rc not in breaks
         and 1 <= rc[0] < rows - 1 and 1 <= rc[1] < cols - 1
         and fy0 + rc[0] * cell + cell <= field_top),
        key=lambda it: (it[0], it[1]))
    shown = []
    for want in (3, 5, 7):   # three distinct timestamps, spread apart
        for t, rc in candidates:
            if t != want or any(abs(rc[0] - s[0]) + abs(rc[1] - s[1]) < 4
                                for s in shown):
                continue
            shown.append(rc)
            x, y, w, h = cell_rect(*rc)
            c.setStrokeColorRGB(*COVER_DESK)
            c.setLineWidth(1.2)
            c.roundRect(x + w * 0.10, y + h * 0.10, w * 0.8, h * 0.8,
                        cell * 0.06, fill=0, stroke=1)
            c.setFont(SANS_BOLD, cell * 0.42)
            c.setFillColorRGB(*COVER_DESK)
            c.drawCentredString(x + w / 2, y + h / 2 - cell * 0.15, str(t))
            break

    # The spark itself: star in near-black on the hottest cell, ringed.
    sx, sy, sw, sh = cell_rect(*spark)
    draw_star(c, sx + sw / 2, sy + sh / 2, cell * 0.95, fill=COVER_DESK)

    # Title block on the desk area above the field.
    cx = (tx0 + tx1) / 2
    top_y = ty1 - th * 0.055

    draw_tracked(c, cx, top_y, UNIT_LINE, SANS, th * 0.0125, 2.2,
                 COVER_ASH_DIM, align="center")
    lw = _tracked_width(UNIT_LINE, SANS, th * 0.0125, 2.2)
    c.setStrokeColorRGB(*COVER_RULE)
    c.setLineWidth(0.8)
    seg = (tw * 0.86 - lw) / 2 - 8
    if seg > 6:
        yl = top_y + th * 0.005
        c.line(cx - lw / 2 - 8 - seg, yl, cx - lw / 2 - 8, yl)
        c.line(cx + lw / 2 + 8, yl, cx + lw / 2 + 8 + seg, yl)

    title_size = _fit_display(TITLE, tw * 0.86, start=th * 0.135)
    title_y = top_y - th * 0.045 - title_size
    c.setFont(DISPLAY, title_size)
    c.setFillColorRGB(*COVER_STOCK)
    c.drawCentredString(cx, title_y, TITLE)

    flame_size = title_size * 0.42
    draw_flame(c, cx, title_y + title_size * 1.18, flame_size,
               fill=COVER_EMBER, core=COVER_FLAME)

    rule_y = title_y - th * 0.030
    c.setStrokeColorRGB(*COVER_EMBER)
    c.setLineWidth(1.6)
    c.line(cx - tw * 0.30, rule_y, cx + tw * 0.30, rule_y)

    count_line = f"{meta['count']} FIREBREAK PUZZLES"
    count_y = rule_y - th * 0.048
    draw_tracked(c, cx, count_y, count_line, DISPLAY, th * 0.030, 3.0,
                 COVER_FLAME, align="center")

    tag_y = count_y - th * 0.033
    c.setFont(SANS_ITALIC, th * 0.0155)
    c.setFillColorRGB(*COVER_ASH)
    c.drawCentredString(cx, tag_y, TAGLINE)

    draw_tracked(c, cx, tag_y - th * 0.036, TIER_LINE, SANS, th * 0.0115,
                 1.6, COVER_ASH_DIM, align="center")


# ---------------------------------------------------------------------------
# Back cover
# ---------------------------------------------------------------------------

BACK_LOGLINE = (
    "A wildfire has already burned out. All you have is a scatter of "
    "timestamps — the minute the fire reached a handful of points. "
    "Reconstruct exactly where the containment lines were dug."
)

BACK_BODY = (
    "You work the incident desk of the Line Verification Unit. Crews "
    "swear their lines held; insurers, planners, and investigators need "
    "proof. A report is only accepted when the evidence forces a single "
    "reconstruction — and every case in this file does exactly that."
)

GUARANTEES = [
    ("ONE SOLUTION",
     "Every board has a verified unique reconstruction — an exact "
     "solution counter proves it before printing."),
    ("NO GUESSING",
     "A no-search deduction solver certifies that every puzzle falls to "
     "step-by-step forced moves alone."),
    ("EVERY LINE WITNESSED",
     "Each firebreak is provable from the timestamps themselves, never "
     "from the shading count alone."),
]


def draw_back(c, rect, trim, meta, demo=None, barcode=False):
    """meta: {"count": int, "edition": str}. demo: optional dict with
    R, C, spark, clues, solution, times for the worked-example card.
    barcode: reserve a white ISBN/barcode box (wraparound covers only)."""
    rx0, ry0, rx1, ry1 = rect
    tx0, ty0, tx1, ty1 = trim
    tw, th = tx1 - tx0, ty1 - ty0

    c.setFillColorRGB(*COVER_DESK)
    c.rect(rx0, ry0, rx1 - rx0, ry1 - ry0, fill=1, stroke=0)
    _rule_grid(c, rect, tw / 9.0)

    margin = tw * 0.10
    x0, x1 = tx0 + margin, tx1 - margin
    width = x1 - x0
    cx = (x0 + x1) / 2

    y = ty1 - th * 0.065
    draw_flame(c, cx - _tracked_width(TITLE, DISPLAY, th * 0.032, 1.5) / 2
               - th * 0.026, y + th * 0.010, th * 0.030,
               fill=COVER_EMBER, core=COVER_FLAME)
    draw_tracked(c, cx + th * 0.016, y, TITLE, DISPLAY, th * 0.032, 1.5,
                 COVER_STOCK, align="center")

    c.setStrokeColorRGB(*COVER_EMBER)
    c.setLineWidth(1.2)
    c.line(x0, y - th * 0.020, x1, y - th * 0.020)

    from pdf_common import draw_paragraph
    y = y - th * 0.055
    y = draw_paragraph(c, BACK_LOGLINE, x0, y, width, font=SANS_BOLD,
                       size=th * 0.0165, leading=th * 0.0235,
                       color=COVER_STOCK)
    y -= th * 0.012
    y = draw_paragraph(c, BACK_BODY, x0, y, width, font=SANS,
                       size=th * 0.0140, leading=th * 0.0205,
                       color=COVER_ASH)

    # Guarantee bullets, each led by a small ember flame.
    y -= th * 0.020
    for head, body in GUARANTEES:
        draw_flame(c, x0 + th * 0.008, y + th * 0.004, th * 0.020,
                   fill=COVER_EMBER, core=COVER_FLAME)
        draw_tracked(c, x0 + th * 0.026, y, head, DISPLAY, th * 0.0185, 1.2,
                     COVER_FLAME)
        y = draw_paragraph(c, body, x0 + th * 0.026, y - th * 0.0195,
                           width - th * 0.026, font=SANS, size=th * 0.0125,
                           leading=th * 0.0180, color=COVER_ASH)
        y -= th * 0.012

    # Worked-example card: a paper-stock report scrap with a real solved
    # board on it (rendered by the same B/W grid renderer the interior
    # uses -- black on stock).
    if demo is not None:
        card_h = th * 0.200
        card_w = width * 0.94
        card_x = cx - card_w / 2
        card_y = y - card_h
        c.setFillColorRGB(*COVER_STOCK)
        c.roundRect(card_x, card_y, card_w, card_h, 5, fill=1, stroke=0)

        grid_side = card_h * 0.78
        gbox = {"x0": card_x + card_w * 0.045,
                "y0": card_y + (card_h - grid_side) / 2,
                "x1": card_x + card_w * 0.045 + grid_side,
                "y1": card_y + (card_h + grid_side) / 2,
                "width": grid_side, "height": grid_side}
        draw_grid(c, gbox, demo["R"], demo["C"], demo["spark"],
                  demo["clues"], solution=demo["solution"],
                  times=demo["times"])

        text_x = gbox["x1"] + card_w * 0.05
        text_w = card_x + card_w - card_w * 0.05 - text_x
        ty = card_y + card_h - card_h * 0.20
        draw_tracked(c, text_x, ty, "CASE 000 — VERIFIED", DISPLAY,
                     card_h * 0.105, 0.8, COVER_EMBER_DEEP)
        draw_paragraph(c, "Shaded cells are the crew's line. Bold numbers "
                       "are the report's timestamps; light numbers are the "
                       "burn minutes the line forces. One account fits.",
                       text_x, ty - card_h * 0.135, text_w, font=SANS,
                       size=card_h * 0.072, leading=card_h * 0.105,
                       color=COVER_DESK)
        y = card_y - th * 0.022

    draw_tracked(c, cx, y - th * 0.004, TIER_LINE, SANS, th * 0.0115, 1.6,
                 COVER_ASH_DIM, align="center")

    # Footer: edition + pointer to the web game, clear of the barcode box.
    foot_y = ty0 + th * 0.030
    c.setFont(SANS, th * 0.0115)
    c.setFillColorRGB(*COVER_ASH_DIM)
    c.drawString(x0, foot_y + th * 0.017,
                 "Also burning online — Burnfront, the daily incident desk.")
    c.drawString(x0, foot_y, meta.get("edition", ""))

    if barcode:
        # KDP ISBN/barcode reserve: 2.0 x 1.2 in, 0.25 in from trim edges.
        from reportlab.lib.units import inch
        bw, bh = 2.0 * inch, 1.2 * inch
        bx = tx1 - 0.25 * inch - bw
        by = ty0 + 0.25 * inch
        c.setFillColorRGB(*WHITE)
        c.rect(bx, by, bw, bh, fill=1, stroke=0)


# ---------------------------------------------------------------------------
# Spine
# ---------------------------------------------------------------------------

def draw_spine(c, rect, trim_h_range, with_text, meta=None):
    """rect: (x0, y0, x1, y1) of the spine strip including vertical bleed.
    trim_h_range: (ty0, ty1) visible height. Text only when the block is
    thick enough for KDP (with_text)."""
    x0, y0, x1, y1 = rect
    ty0, ty1 = trim_h_range
    c.setFillColorRGB(*COVER_DESK)
    c.rect(x0, y0, x1 - x0, y1 - y0, fill=1, stroke=0)
    if not with_text:
        return
    cx = (x0 + x1) / 2
    spine_w = x1 - x0
    size = min(spine_w * 0.52, (ty1 - ty0) * 0.030)
    draw_flame(c, cx, ty1 - (ty1 - ty0) * 0.045, size * 1.1,
               fill=COVER_EMBER, core=COVER_FLAME)
    c.saveState()
    # Reads top-to-bottom when the book lies front-cover up (US practice).
    c.translate(cx, (ty0 + ty1) / 2)
    c.rotate(-90)
    c.setFont(DISPLAY, size)
    c.setFillColorRGB(*COVER_STOCK)
    c.drawCentredString(0, -size * 0.36, TITLE)
    c.restoreState()
