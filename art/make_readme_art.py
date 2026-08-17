"""Draws the art the README keeps.

    python art/make_readme_art.py

Every picture here is drawn through the *same* coordinate maths the package
uses -- anchors are PDF user-space points with a bottom-left origin, converted
to pixels at draw time and never persisted. That is deliberate: the README's
central claim is that a highlight stays on its words through any zoom and any
rotation, and art drawn by eye could show that claim being true while the code
made it false. Here the pictures cannot disagree with the package, because they
are produced by a transcription of it (see `to_viewport`, which is
`js/src/coordinates.js` line for line).

Writes:

    art/hero.png       the viewer, with marks, an orphan and a thread
    art/demo.gif       a mark becomes a row you can query, and then the
                       document is re-issued and the row says so
    art/anchoring.png  why user-space points and not viewport pixels
    art/orphan.png     what a replaced document does to the marks on it
"""

from __future__ import annotations

from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ART = Path(__file__).parent

# The same palette as the viewer's own stylesheet, so the README and the product
# are recognisably one thing.
PLATE_TOP = (14, 15, 18)
PLATE_BOT = (21, 23, 28)
CHROME = (24, 24, 27)
SUNKEN = (39, 39, 42)
LINE = (63, 63, 70)
TEXT = (250, 250, 250)
MUTED = (161, 161, 170)
ACCENT = (37, 99, 235)
ACCENT_SOFT = (59, 130, 246)
MARK = (253, 224, 71)
WARNING = (180, 83, 9)
WARNING_TEXT = (245, 158, 11)
PAPER = (255, 255, 255)
INK = (203, 213, 225)
INK_DARK = (148, 163, 184)

PAGE = (595.0, 842.0)  # A4, in points


# --------------------------------------------------------------------------
# The coordinate maths, transcribed from js/src/coordinates.js
# --------------------------------------------------------------------------

def to_viewport(anchor, page, turns, scale):
    """A stored anchor to the box to draw, at this rotation and zoom.

    Bottom-left origin in points to top-left origin in pixels: the y flip
    first, then the rotation, then the scale. Both corners are converted and
    re-sorted, because a quarter turn swaps which corner is which.
    """
    w, h = page
    x1, y1, x2, y2 = anchor

    # User space to page space: the flip.
    box = (min(x1, x2), h - max(y1, y2), max(x1, x2), h - min(y1, y2))

    def point(x, y):
        if turns == 1:
            return ((h - y) * scale, x * scale)
        if turns == 2:
            return ((w - x) * scale, (h - y) * scale)
        if turns == 3:
            return (y * scale, (w - x) * scale)
        return (x * scale, y * scale)

    ax, ay = point(box[0], box[1])
    bx, by = point(box[2], box[3])

    return (min(ax, bx), min(ay, by), max(ax, bx), max(ay, by))


def viewport_size(page, turns, scale):
    w, h = page[0] * scale, page[1] * scale

    return (h, w) if turns % 2 else (w, h)


# --------------------------------------------------------------------------
# Canvas helpers
# --------------------------------------------------------------------------

# Three faces, each with a job.
#
#   Georgia   the document. A contract is set in a serif, and a page of grey
#             bars proves nothing -- the whole claim is that the highlight stays
#             on *these words*, which needs words to stay on.
#   Consolas  the numbers. Tabular figures are what make "this column never
#             moves" and "this one changes every frame" readable at a glance.
#   Segoe UI  the chrome. Deliberately the quietest of the three.
FACES = {
    "doc": ("georgia.ttf", "constantia.ttf", "times.ttf"),
    "doc-bold": ("georgiab.ttf", "constantiab.ttf", "timesbd.ttf"),
    "data": ("consola.ttf", "cour.ttf"),
    "data-bold": ("consolab.ttf", "courbd.ttf"),
    "ui": ("segoeui.ttf", "arial.ttf"),
    "ui-bold": ("segoeuisb.ttf", "segoeuib.ttf", "arialbd.ttf"),
}


def face(kind, size):
    for name in FACES[kind]:
        try:
            return ImageFont.truetype(name, size)
        except OSError:
            continue

    return ImageFont.load_default(size)


def font(size, bold=False):
    return face("ui-bold" if bold else "ui", size)


def tracked(draw, xy, text, f, fill, track=2.4):
    """Letter-spaced text, for the small labels. Pillow has no tracking of its own."""
    x, y = xy

    for char in text:
        draw.text((x, y), char, font=f, fill=fill)
        x += f.getlength(char) + track

    return x - track


def plate(w, h):
    """The dark gradient every picture sits on."""
    column = Image.new("RGB", (1, h))
    px = column.load()

    for y in range(h):
        t = y / max(1, h - 1)
        px[0, y] = tuple(int(PLATE_TOP[i] + (PLATE_BOT[i] - PLATE_TOP[i]) * t) for i in range(3))

    return column.resize((w, h))


def hatched(draw, box, colour, step=7):
    """Diagonal hatching -- how an orphaned mark is drawn in the viewer."""
    left, top, right, bottom = (int(v) for v in box)
    span = (right - left) + (bottom - top)

    for offset in range(0, span, step):
        draw.line(
            [(left + offset, top), (left + offset - (bottom - top), bottom)],
            fill=colour,
            width=2,
        )


def clipped_hatch(size, box, colour):
    """Hatching confined to one rectangle, as a pasteable layer."""
    layer = Image.new("RGBA", size, (0, 0, 0, 0))
    hatched(ImageDraw.Draw(layer), box, colour + (110,))

    mask = Image.new("L", size, 0)
    ImageDraw.Draw(mask).rectangle(box, fill=255)

    out = Image.new("RGBA", size, (0, 0, 0, 0))
    out.paste(layer, (0, 0), mask)

    return out


# --------------------------------------------------------------------------
# The page: text lines and the anchors over them, all in user space
# --------------------------------------------------------------------------

# Lines of "text", as user-space rectangles. Bottom-left origin, so a larger y
# is higher up the page.
def text_lines():
    lines = []
    top = 770.0

    for i in range(26):
        y = top - i * 26.0

        if i in (0,):
            lines.append((72.0, y - 16, 300.0, y, True))
            continue

        if i in (6, 14, 21):
            lines.append((72.0, y - 12, 250.0, y, True))
            continue

        width = 451.0 if i % 5 != 4 else 300.0
        lines.append((72.0, y - 10, 72.0 + width, y, False))

    return lines


# The marks. A highlight spanning three lines is three anchors, exactly as the
# package stores it.
def line_anchor(index, right=523.0, pad=3.0):
    """The anchor covering one line of the drawn text, with a little padding.

    Derived from the same arithmetic that lays the lines out, so a mark cannot
    drift off its words when the page changes.
    """
    y = 770.0 - index * 26.0

    return (72.0, y - 10.0 - pad, right, y + pad)


HIGHLIGHT = [line_anchor(3), line_anchor(4), line_anchor(5, right=331.0)]

NOTE = [line_anchor(11)]

ORPHAN = [line_anchor(18, right=420.0)]


def draw_page(canvas, origin, turns, scale, marks=True, orphan=True, selected=0):
    """One rendered page with its overlay, at the given rotation and zoom."""
    width, height = viewport_size(PAGE, turns, scale)
    ox, oy = origin

    page = Image.new("RGB", (max(1, int(width)), max(1, int(height))), PAPER)
    draw = ImageDraw.Draw(page)

    for x1, y1, x2, y2, heavy in text_lines():
        box = to_viewport((x1, y1, x2, y2), PAGE, turns, scale)
        draw.rectangle(box, fill=INK_DARK if heavy else INK)

    overlay = Image.new("RGBA", page.size, (0, 0, 0, 0))
    over = ImageDraw.Draw(overlay)

    if marks:
        for index, anchor in enumerate(HIGHLIGHT):
            box = to_viewport(anchor, PAGE, turns, scale)
            over.rectangle(box, fill=MARK + (150,))

            if selected == 0 and index == 0:
                over.rectangle(box, outline=ACCENT + (255,), width=2)

        for anchor in NOTE:
            box = to_viewport(anchor, PAGE, turns, scale)
            over.rectangle(box, fill=(134, 239, 172, 140))

    if orphan:
        for anchor in ORPHAN:
            box = to_viewport(anchor, PAGE, turns, scale)
            overlay.alpha_composite(clipped_hatch(page.size, box, WARNING))
            over.rectangle(box, outline=WARNING + (255,), width=2)

            # The badge rides the first rectangle only.
            bx, by = box[2] - 9, box[1] - 9
            over.ellipse([bx - 9, by - 9, bx + 9, by + 9], fill=WARNING + (255,))
            over.text((bx, by), "!", fill=(255, 255, 255), font=font(13, True), anchor="mm")

    page = Image.alpha_composite(page.convert("RGBA"), overlay).convert("RGB")

    canvas.paste(page, (int(ox), int(oy)))
    ImageDraw.Draw(canvas).rectangle(
        [int(ox), int(oy), int(ox) + page.width - 1, int(oy) + page.height - 1],
        outline=(63, 63, 70),
    )

    return page.size


# --------------------------------------------------------------------------
# hero.png
# --------------------------------------------------------------------------

def rotate_icon(draw, cx, cy, r=7, colour=MUTED):
    """A clockwise arc with a head on it -- the rotate control."""
    draw.arc([cx - r, cy - r, cx + r, cy + r], start=300, end=210, fill=colour, width=2)
    draw.polygon(
        [(cx + r - 4, cy - r + 1), (cx + r + 3, cy - r + 2), (cx + r - 1, cy - r + 7)],
        fill=colour,
    )


def toolbar(draw, x, y, w, zoom="100%"):
    draw.rectangle([x, y, x + w, y + 44], fill=SUNKEN)
    draw.line([(x, y + 44), (x + w, y + 44)], fill=LINE)

    cx = x + 14

    for label, width in (("−", 30), (zoom, 46), ("+", 30)):
        draw.rounded_rectangle([cx, y + 9, cx + width, y + 35], radius=4, outline=LINE)
        draw.text((cx + width / 2, y + 22), label, fill=MUTED, font=font(14), anchor="mm")
        cx += width + 8

    # Drawn rather than typed: the rotate glyph is missing from the fonts this
    # runs against, and a tofu box in the hero image is worse than an arc.
    draw.rounded_rectangle([cx, y + 9, cx + 30, y + 35], radius=4, outline=LINE)
    rotate_icon(draw, cx + 15, y + 22)
    cx += 38

    draw.rounded_rectangle([cx, y + 9, cx + 132, y + 35], radius=4, outline=ACCENT)
    draw.text((cx + 66, y + 22), "Keep selection", fill=ACCENT_SOFT, font=font(13), anchor="mm")

    draw.text(
        (x + w - 16, y + 22),
        "1 annotation may no longer point at the right place.",
        fill=WARNING_TEXT,
        font=font(13),
        anchor="rm",
    )


def thread(draw, x, y, w, h):
    draw.rectangle([x, y, x + w, y + h], fill=CHROME)
    draw.line([(x, y), (x, y + h)], fill=LINE)

    draw.text((x + 18, y + 24), "Page 1", fill=TEXT, font=font(14, True), anchor="lm")
    draw.text((x + w - 18, y + 24), "Resolve", fill=ACCENT_SOFT, font=font(13), anchor="rm")

    entries = [
        ("Reviewer", "The payment terms here say thirty days, but the", "purchase order says sixty.", False),
        ("Finance", "Confirmed — the PO is right. Revision B is on", "its way.", True),
        ("Reviewer", "Thanks. Holding this open until it lands.", "", False),
    ]

    cy = y + 56

    for who, first, second, reply in entries:
        left = x + (34 if reply else 18)

        if reply:
            draw.line([(x + 24, cy - 4), (x + 24, cy + 46)], fill=LINE, width=2)

        draw.text((left, cy), who, fill=TEXT, font=font(13, True))
        draw.text((left, cy + 20), first, fill=MUTED, font=font(13))

        if second:
            draw.text((left, cy + 38), second, fill=MUTED, font=font(13))

        cy += 78 if second else 60

    box_top = y + h - 96
    draw.rounded_rectangle([x + 18, box_top, x + w - 18, box_top + 52], radius=4, outline=LINE)
    draw.text((x + 30, box_top + 16), "Add a comment", fill=(113, 113, 122), font=font(13))

    draw.rounded_rectangle([x + 18, box_top + 62, x + 112, box_top + 88], radius=4, fill=ACCENT)
    draw.text((x + 65, box_top + 75), "Add a comment", fill=(255, 255, 255), font=font(11), anchor="mm")


def hero():
    W, H = 1600, 900
    canvas = plate(W, H)
    draw = ImageDraw.Draw(canvas)

    pad = 40
    win = [pad, pad, W - pad, H - pad]

    draw.rounded_rectangle(win, radius=10, fill=CHROME, outline=LINE)

    inner_x, inner_y = pad + 1, pad + 1
    inner_w = (W - pad) - inner_x

    toolbar(draw, inner_x, inner_y, inner_w)

    body_top = inner_y + 45
    body_bottom = H - pad - 1
    thread_w = 340

    draw.rectangle([inner_x, body_top, W - pad - thread_w, body_bottom], fill=SUNKEN)

    scale = 0.86
    size = viewport_size(PAGE, 0, scale)
    px = inner_x + ((W - pad - thread_w) - inner_x - size[0]) / 2
    draw_page(canvas, (px, body_top + 18), 0, scale)

    thread(draw, W - pad - thread_w, body_top, thread_w - 1, body_bottom - body_top)

    canvas.save(ART / "hero.png")
    print("wrote hero.png")


# --------------------------------------------------------------------------
# demo.gif -- the claim, moving
# --------------------------------------------------------------------------

# --------------------------------------------------------------------------
# demo.gif -- the claim, moving
#
# The design brief for this one picture: a README reader gives it about four
# seconds, and in those four seconds it has to make one argument -- that the
# thing Pindle stores does not change, and everything else does.
#
# So the frame is split into two materials. On the left, the stored anchor, set
# large in tabular figures against a solid rule: permanent. On the right, the
# page it describes, put through every zoom and every quarter turn, with the
# highlight welded to the same clause throughout. Underneath the stored numbers,
# in the same face at half the size against a dashed rule, the pixels being
# recomputed -- churning every frame, kept by nobody.
#
# Type scale carries the argument: what survives is set big, what is discarded
# is set small. Nothing else in the frame is allowed to be loud.
#
# And the demonstration is not staged. The highlight in every frame is placed by
# `to_viewport` -- the transcription of the package's own maths at the top of
# this file -- over page pixels that were rotated by Pillow. The two agree only
# if the maths is right, and `check_rotation` fails the build if they ever stop
# agreeing. A README picture that would break when the code breaks is worth more
# than one drawn to look correct.
# --------------------------------------------------------------------------

GROUND = (19, 23, 33)
PANEL = (27, 33, 48)
RULE = (44, 52, 70)
STEEL = (127, 179, 200)
PAPER_INK = (31, 39, 51)
PAPER_FAINT = (110, 122, 140)
HIGHLIGHTER = (255, 216, 77)

CLAUSE = [
    ("3.  Payment", True),
    ("The Customer shall settle each invoice in full", False),
    ("within thirty (30) days of the invoice date.", False),
    ("Amounts outstanding after that period carry", False),
    ("interest at four per cent above base rate.", False),
]

# Revision B of the same contract. One word changed, and it is the word the
# highlight was drawn on -- which is the entire argument for storing a hash of
# the bytes alongside every annotation.
CLAUSE_REVISED = [
    ("3.  Payment", True),
    ("The Customer shall settle each invoice in full", False),
    ("within sixty (60) days of the invoice date.", False),
    ("Amounts outstanding after that period carry", False),
    ("interest at four per cent above base rate.", False),
]

# The phrase the highlight covers, given as (line index, phrase). It runs across
# two lines on purpose: a highlight over two lines is two rectangles, which is
# how the package stores one.
PHRASE = [(1, "in full", None), (2, "within thirty (30) days", None)]

MARGIN = 64.0  # points
FIRST_BASELINE = 742.0
LEADING = 30.0
BODY_PT = 17

# The page is rasterised once at this multiple of its point size and then scaled
# like the bitmap it is. Re-typesetting the text at each zoom instead would put
# the glyphs wherever the hinter felt like at that pixel size, and the highlight
# -- placed by arithmetic on the point positions -- would drift off the words by
# a letter or two. Real PDF rendering has no such problem: glyph positions come
# from the text matrix and are exactly linear in scale. Scaling one raster is
# the faithful thing here, not the shortcut.
REF = 2.0


def clause_anchors():
    """The phrase's anchors, in PDF points, measured once.

    Measured once and then never again -- which is exactly what the package
    does. Everything the animation draws afterwards is derived from these four
    numbers per rectangle.
    """
    body = face("doc", round(BODY_PT * REF))
    anchors = []

    for index, prefix, _ in PHRASE:
        text = CLAUSE[index][0]
        start = text.index(prefix)

        left = MARGIN + body.getlength(text[:start]) / REF
        right = left + body.getlength(prefix) / REF
        baseline = FIRST_BASELINE - index * LEADING

        # A little padding above and below, the way a highlighter overshoots.
        anchors.append((left - 2.0, baseline - 5.0, right + 2.0, baseline + BODY_PT - 1.0))

    return anchors


def rotate_box(box, w, h, turns):
    """Where a box lands when the image under it is turned a quarter at a time."""
    left, top, right, bottom = box

    if turns == 1:
        return (h - bottom, left, h - top, right)
    if turns == 2:
        return (w - right, h - bottom, w - left, h - top)
    if turns == 3:
        return (top, w - right, bottom, w - left)

    return box


def check_rotation(anchors, scale):
    """The formula against the pixels, at every rotation. Fails the build if they part."""
    w, h = PAGE[0] * scale, PAGE[1] * scale

    for anchor in anchors:
        upright = to_viewport(anchor, PAGE, 0, scale)

        for turns in (1, 2, 3):
            formula = to_viewport(anchor, PAGE, turns, scale)
            rotated = rotate_box(upright, w, h, turns)

            for a, b in zip(formula, rotated):
                if abs(a - b) > 1e-6:
                    raise AssertionError(
                        f"to_viewport disagrees with the rendered page at {turns * 90}°: "
                        f"{formula} vs {rotated}"
                    )


_BASE = {}


def base_page(revised=False):
    """The upright page, typeset once per revision at the reference resolution."""
    if revised in _BASE:
        return _BASE[revised]

    page = Image.new("RGB", (round(PAGE[0] * REF), round(PAGE[1] * REF)), PAPER)
    draw = ImageDraw.Draw(page)

    body = face("doc", round(BODY_PT * REF))
    heavy = face("doc-bold", round(BODY_PT * REF))

    for index, (text, is_heading) in enumerate(CLAUSE_REVISED if revised else CLAUSE):
        baseline = FIRST_BASELINE - index * LEADING
        draw.text(
            (MARGIN * REF, (PAGE[1] - baseline - BODY_PT) * REF),
            text,
            font=heavy if is_heading else body,
            fill=PAPER_INK,
        )

    # A few faint lines below, so the clause reads as part of a document rather
    # than as five lines floating on white.
    for index in range(len(CLAUSE) + 1, len(CLAUSE) + 14):
        y = (PAGE[1] - (FIRST_BASELINE - index * LEADING)) * REF
        right = MARGIN + (438.0 if index % 4 else 300.0)
        draw.rectangle([MARGIN * REF, y, right * REF, y + 4.0 * REF], fill=(226, 232, 240))

    _BASE[revised] = page

    return page


def render_clause(scale, turns, anchors):
    """The page, scaled and then turned, with the marks laid on after.

    The order matters and is the point: the words are rotated pixels, the
    highlight is placed by the formula. They can only line up if the formula is
    right.
    """
    width, height = round(PAGE[0] * scale), round(PAGE[1] * scale)

    page = base_page().resize((width, height), Image.LANCZOS)

    if turns:
        page = page.rotate(-90 * turns, expand=True)

    overlay = Image.new("RGBA", page.size, (0, 0, 0, 0))
    over = ImageDraw.Draw(overlay)

    boxes = [to_viewport(anchor, PAGE, turns, scale) for anchor in anchors]

    for box in boxes:
        over.rectangle(box, fill=HIGHLIGHTER + (132,))

    page = Image.alpha_composite(page.convert("RGBA"), overlay).convert("RGB")

    return page, boxes


def ease(t):
    """Ease in and out, so a movement reads as a gesture rather than a slider."""
    return t * t * (3 - 2 * t)


AMBER = (245, 158, 11)
AMBER_DIM = (146, 96, 16)
VALUE = (222, 229, 240)
LABEL = (110, 122, 142)


def demo():
    """The round trip: a mark on a page becomes a row you can query.

    Zoom and rotation are not in this picture, and that is the point. Every PDF
    viewer zooms; nobody installs a package for it. What Pindle does that a
    viewer does not is put the mark in *your* database, under *your* policies,
    where you can ask it questions -- and tell you when the document underneath
    it has been replaced.

    So the frame is a page on the left and the row it produced on the right, and
    the story runs: draw it, it is a record, query it, discuss it, and then
    somebody re-issues the contract and the record says so. The last beat is the
    one nothing else does: the highlight is still exactly where it was put, and
    the words underneath it now say sixty days instead of thirty.
    """
    W, H = 900, 480
    PAD = 30
    PAGE_W = 400
    REC_X = PAD + PAGE_W + 26
    REC_W = W - PAD - REC_X
    TOP = 66
    BOTTOM = H - PAD

    anchors = clause_anchors()

    for scale in (0.62, 1.0, 1.4):
        check_rotation(anchors, scale)

    small = face("data", 12)
    mono = face("data", 12)
    mono_bold = face("data-bold", 12)
    tag = face("ui-bold", 10)
    caption = face("ui", 12)

    rows = [
        ("annotatable", "Invoice #4471"),
        ("document_key", "default"),
        ("page", "1"),
        ("type", "highlight"),
    ]

    def rect_lines():
        out = [("rects", f"{len(anchors)} rectangles, PDF points")]

        for anchor in anchors:
            out.append(("", "x1 {:6.2f}  y1 {:6.2f}  x2 {:6.2f}  y2 {:6.2f}".format(*anchor)))

        return out

    body_rows = rows + rect_lines()

    def render(state):
        canvas = Image.new("RGB", (W, H), GROUND)
        draw = ImageDraw.Draw(canvas)

        # -- eyebrow -------------------------------------------------------
        tracked(draw, (PAD, 24), "PINDLE", face("ui-bold", 13), (233, 237, 245), 3.4)
        draw.text(
            (W - PAD, 26),
            "annotations that live in your database",
            font=caption,
            fill=LABEL,
            anchor="ra",
        )

        # -- the page ------------------------------------------------------
        draw.rectangle([PAD, TOP, PAD + PAGE_W, BOTTOM], fill=PANEL, outline=RULE)

        page = base_page(state["revised"]).resize(
            (round(PAGE[0]), round(PAGE[1])), Image.LANCZOS
        )

        window = Image.new("RGB", (PAGE_W - 2, BOTTOM - TOP - 2), (238, 241, 246))
        window.paste(page, (int((PAGE_W - 2) / 2 - 254), -46))

        over = ImageDraw.Draw(window, "RGBA")

        for anchor in anchors:
            left, top, right, bottom = to_viewport(anchor, PAGE, 0, 1.0)
            left += (PAGE_W - 2) / 2 - 254
            right += (PAGE_W - 2) / 2 - 254
            top -= 46
            bottom -= 46

            # The mark sweeps on rather than appearing, so a reader sees it being
            # drawn rather than wondering what changed.
            right = left + (right - left) * state["mark"]

            if state["mark"] <= 0:
                continue

            if state["orphaned"]:
                over.rectangle([left, top, right, bottom], fill=WARNING + (60,))
                hatched(over, (left, top, right, bottom), WARNING + (150,), step=6)
                over.rectangle([left, top, right, bottom], outline=AMBER + (255,))
            else:
                over.rectangle([left, top, right, bottom], fill=HIGHLIGHTER + (140,))

        canvas.paste(window, (PAD + 1, TOP + 1))

        if state["revised"]:
            pill = "revision B uploaded"
            width = caption.getlength(pill) + 20
            px, py = PAD + PAGE_W - width - 12, TOP + 12

            draw.rounded_rectangle([px, py, px + width, py + 22], radius=11, fill=AMBER_DIM)
            draw.text((px + width / 2, py + 11), pill, font=caption, fill=(255, 244, 214), anchor="mm")

        # -- the record ----------------------------------------------------
        draw.rectangle([REC_X, TOP, REC_X + REC_W, BOTTOM], fill=PANEL, outline=RULE)

        x = REC_X + 18
        value_x = REC_X + 128

        tracked(draw, (x, TOP + 16), "ANNOTATION", tag, STEEL, 2.2)
        draw.text((REC_X + REC_W - 18, TOP + 16), "01JQ8F7K2M…", font=small, fill=LABEL, anchor="ra")
        draw.line([(REC_X + 1, TOP + 42), (REC_X + REC_W - 1, TOP + 42)], fill=RULE)

        y = TOP + 56

        for index, (label, value) in enumerate(body_rows):
            if index >= state["rows"]:
                break

            if label:
                draw.text((x, y), label, font=mono, fill=LABEL)

            draw.text((value_x if label else x + 20, y), value, font=mono, fill=VALUE)
            y += 19

        if state["rows"] >= len(body_rows):
            colour = AMBER if state["orphaned"] else VALUE
            draw.text((x, y), "document_hash", font=mono, fill=LABEL)
            draw.text((value_x, y), "9c17be04…" if state["orphaned"] else "4f3a9c21…", font=mono_bold, fill=colour)
            y += 19

        if state["orphaned"]:
            draw.text((x, y), "orphaned", font=mono, fill=LABEL)
            draw.text((value_x, y), "true", font=mono_bold, fill=AMBER)
            y += 19

        # -- the thread ----------------------------------------------------
        if state["comments"]:
            draw.line([(REC_X + 1, y + 8), (REC_X + REC_W - 1, y + 8)], fill=RULE)
            tracked(draw, (x, y + 22), "COMMENTS", tag, LABEL, 2.2)

            draw.text((x, y + 44), "Reviewer", font=mono_bold, fill=VALUE)
            draw.text((x + 74, y + 44), "The PO says sixty days —", font=mono, fill=(168, 180, 198))
            draw.text((x + 74, y + 62), "which is right?", font=mono, fill=(168, 180, 198))

            y += 84

        # -- the query -----------------------------------------------------
        if state["query"]:
            qy = BOTTOM - 58

            draw.line([(REC_X + 1, qy - 14), (REC_X + REC_W - 1, qy - 14)], fill=RULE)
            draw.text((x, qy), "$invoice->annotations()", font=mono, fill=STEEL)
            draw.text((x, qy + 18), "        ->unresolved()->count()", font=mono, fill=STEEL)
            draw.text((REC_X + REC_W - 18, qy + 9), "1", font=face("data-bold", 22), fill=VALUE, anchor="rm")

        return canvas.convert("P", palette=Image.ADAPTIVE, colors=96)

    def state(mark=0.0, rows=0, comments=False, query=False, revised=False, orphaned=False):
        return {
            "mark": mark,
            "rows": rows,
            "comments": comments,
            "query": query,
            "revised": revised,
            "orphaned": orphaned,
        }

    frames = []
    hold = lambda s, n: frames.extend([render(s)] * n)  # noqa: E731

    # 1. a page, and nothing recorded about it yet
    hold(state(), 7)

    # 2. the mark is drawn
    for i in range(1, 7):
        frames.append(render(state(mark=ease(i / 6))))

    # 3. it becomes a row, a field at a time
    for index in range(1, len(body_rows) + 2):
        frames.append(render(state(mark=1.0, rows=index)))

    full = dict(mark=1.0, rows=len(body_rows) + 1)

    hold(state(**full), 4)

    # 4. which you can ask questions of
    hold(state(**full, query=True), 8)

    # 5. and discuss
    hold(state(**full, query=True, comments=True), 9)

    # 6. then the contract is re-issued, and the mark says so
    hold(state(**full, query=True, comments=True, revised=True), 3)
    hold(state(**full, query=True, comments=True, revised=True, orphaned=True), 14)

    frames[0].save(
        ART / "demo.gif",
        save_all=True,
        append_images=frames[1:],
        duration=110,
        loop=0,
        optimize=True,
    )

    size = (ART / "demo.gif").stat().st_size

    print(f"wrote demo.gif  {len(frames)} frames  {size / 1024:.0f}kb")


# --------------------------------------------------------------------------
# anchoring.png -- why user space and not viewport pixels
# --------------------------------------------------------------------------

def anchoring():
    W, H = 1600, 700
    canvas = plate(W, H)
    draw = ImageDraw.Draw(canvas)

    draw.text((44, 40), "Why the anchors are points and not pixels", fill=TEXT, font=font(26, True))
    draw.text(
        (44, 80),
        "A viewport rectangle is only meaningful alongside the zoom, the rotation and the screen that produced it.",
        fill=MUTED,
        font=font(15),
    )

    anchor = HIGHLIGHT[0]

    def drawn(turns, scale):
        """The pixels the package would actually compute -- not a guess at them."""
        left, top, right, bottom = to_viewport(anchor, PAGE, turns, scale)

        return (
            f"left {left:.0f}   top {top:.0f}\n"
            f"width {right - left:.0f}   height {bottom - top:.0f}"
        )

    panels = [
        (
            "Stored",
            f"x1 {anchor[0]:.1f}   y1 {anchor[1]:.1f}\nx2 {anchor[2]:.1f}   y2 {anchor[3]:.1f}",
            "PDF points, bottom-left origin",
        ),
        ("Drawn at 100%", drawn(0, 1.0), "recomputed, then thrown away"),
        ("Drawn at 175%, turned 90°", drawn(1, 1.75), "recomputed, then thrown away"),
    ]

    x = 44
    width = (W - 88 - 40 * 2) // 3

    for index, (title, body, note) in enumerate(panels):
        box = [x, 140, x + width, 400]

        draw.rounded_rectangle(box, radius=10, fill=CHROME, outline=ACCENT if index == 0 else LINE)
        draw.text((x + 24, 166), title, fill=TEXT if index == 0 else MUTED, font=font(17, True))

        cy = 212
        for line in body.split("\n"):
            draw.text((x + 24, cy), line, fill=(226, 232, 240) if index == 0 else MUTED, font=font(16))
            cy += 26

        draw.text((x + 24, 350), note, fill=MUTED if index == 0 else (113, 113, 122), font=font(13))

        if index == 0:
            draw.text((x + 24, 300), "persisted", fill=ACCENT_SOFT, font=font(13, True))

        if index < 2:
            draw.text((x + width + 20, 270), "→", fill=LINE, font=font(30), anchor="mm")

        x += width + 40

    draw.text(
        (44, 450),
        "Store the middle or right-hand column and the highlight moves the first time anything changes:",
        fill=MUTED,
        font=font(15),
    )

    for i, (bad, why) in enumerate([
        ("a different zoom", "451 pixels wide is 451 points only at 100%"),
        ("a retina screen", "device pixel ratio doubles every number"),
        ("a narrower container", "the page is laid out to fit, and everything shifts"),
        ("the page turned", "width and height swap, and the corners change places"),
    ]):
        y = 490 + i * 42
        draw.ellipse([46, y + 6, 56, y + 16], fill=WARNING)
        draw.text((72, y), bad, fill=(226, 232, 240), font=font(16, True))
        draw.text((320, y + 1), why, fill=MUTED, font=font(15))

    canvas.save(ART / "anchoring.png")
    print("wrote anchoring.png")


# --------------------------------------------------------------------------
# orphan.png
# --------------------------------------------------------------------------

def orphan():
    W, H = 1600, 620
    canvas = plate(W, H)
    draw = ImageDraw.Draw(canvas)

    draw.text((44, 40), "When the document is replaced", fill=TEXT, font=font(26, True))
    draw.text(
        (44, 80),
        "Every annotation records the sha256 of the bytes it was drawn on. When they stop matching, it says so.",
        fill=MUTED,
        font=font(15),
    )

    scale = 0.44
    size = viewport_size(PAGE, 0, scale)

    draw_page(canvas, (110, 150), 0, scale, orphan=False)
    draw.text((110, 150 + size[1] + 16), "invoice.pdf   sha256 4f3a…", fill=MUTED, font=font(14))
    draw.text((110, 150 + size[1] + 40), "3 annotations, all anchored", fill=(134, 239, 172), font=font(14))

    arrow_x = 110 + size[0] + 90
    draw.text((arrow_x, 150 + size[1] / 2), "→", fill=LINE, font=font(40), anchor="mm")
    draw.text((arrow_x, 150 + size[1] / 2 + 40), "re-issued", fill=MUTED, font=font(14), anchor="mm")

    right = arrow_x + 90
    draw_page(canvas, (right, 150), 0, scale, orphan=True)
    draw.text((right, 150 + size[1] + 16), "invoice.pdf   sha256 9c17…", fill=MUTED, font=font(14))
    draw.text((right, 150 + size[1] + 40), "1 flagged, drawn with a warning", fill=WARNING_TEXT, font=font(14))

    x = right + size[0] + 80
    draw.rounded_rectangle([x, 150, W - 60, 150 + size[1]], radius=10, fill=CHROME, outline=LINE)

    draw.text((x + 28, 182), "What Pindle does not do", fill=TEXT, font=font(18, True))

    for i, line in enumerate([
        "It does not hide the annotation.",
        "Somebody objected to something, and",
        "hiding it would lose the objection.",
        "",
        "It does not draw it where it says.",
        "Those coordinates now point at a",
        "different sentence.",
        "",
        "It flags it, and leaves the decision",
        "to the people reading the document.",
    ]):
        draw.text((x + 28, 226 + i * 24), line, fill=MUTED, font=font(15))

    canvas.save(ART / "orphan.png")
    print("wrote orphan.png")


if __name__ == "__main__":
    hero()
    demo()
    anchoring()
    orphan()
