"""Draws the art the README keeps.

    python art/make_readme_art.py

Design note, because the pictures are part of the argument rather than
decoration around it.

Pindle's world is document review: printed contracts, marginalia, highlighter
ink, and -- the thing only this package adds -- a hash that fixes exactly which
bytes a mark was made on. So the whole set is built on one confrontation:

    the paper    warm, bright, set in a serif, genuinely readable
    the evidence cold, monospaced, exact -- coordinates and a seal

The paper is the loudest thing in every frame. That is the risk in this set and
it is deliberate: most developer-tool art keeps everything uniformly dark and
low-contrast, and a document viewer whose document is dim has its priorities the
wrong way round.

The recurring artifact is the **seal** -- the sha256, drawn like wax. Mint and
whole while the document is the one a mark was made on, burnt orange and cracked
once it is not. It appears in three of the four pictures and is absent from the
fourth, which is about coordinates rather than bytes.

Every anchor drawn anywhere here goes through `to_viewport`, a transcription of
`js/src/coordinates.js`. The pictures cannot flatter the code, because they are
drawn by it -- and `check_rotation` fails the build if the two ever part.

Writes:

    art/hero.png       the viewer: marks on a real page, a thread beside them
    art/demo.gif       a mark becomes a row you can query, then the contract is
                       re-issued and the row says so
    art/anchoring.png  one stored anchor, three renderings, all correct
    art/orphan.png     the seal breaking, and what happens to the marks
"""

from __future__ import annotations

from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ART = Path(__file__).parent

# --------------------------------------------------------------------------
# Tokens
# --------------------------------------------------------------------------

# A review desk under a lamp. The ground is graphite with a green cast rather
# than a blue-slate or a near-black: it reads as a surface something is lying
# on, which is what the paper needs to sit against.
INK = (19, 21, 15)
DESK = (29, 32, 26)
RULE = (52, 58, 46)
TEXT = (237, 239, 231)
MUTED = (138, 145, 128)
FAINT = (95, 102, 87)

PAPER = (251, 250, 245)  # printed paper is warm; pure white is a screen
PAPER_INK = (28, 30, 26)
PAPER_FAINT = (214, 216, 205)

MARKER = (242, 213, 68)  # highlighter ink, which leans green
SEAL = (127, 212, 168)  # the hash matches
BROKEN = (232, 133, 63)  # the hash does not

# PDF pages are measured in points, and A4 is the shape everyone pictures.
PAGE = (595.0, 842.0)

# The PNGs are drawn at twice their display size so they stay crisp on the
# screens developers actually read READMEs on. The GIF is not -- doubling every
# frame would quadruple a file that has to load in a scroll.
S = 2


def u(value):
    """A design-unit measurement in output pixels."""
    return int(round(value * S))


# --------------------------------------------------------------------------
# Type
#
#   Georgia   the document. A contract is set in a serif, and the claim being
#             made is that a mark stays on *these words* -- which needs words.
#   Consolas  the evidence. Tabular figures are what let a reader see at a
#             glance that one column of numbers never moves.
#   Segoe UI  labels only, at ten points, uppercase, letterspaced. Deliberately
#             the quietest voice in the set.
# --------------------------------------------------------------------------

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
            return ImageFont.truetype(name, max(1, int(size)))
        except OSError:
            continue

    return ImageFont.load_default(max(1, int(size)))


def tracked(draw, xy, text, f, fill, track):
    """Letter-spaced text. Pillow has no tracking, and the labels need it."""
    x, y = xy

    for char in text:
        draw.text((x, y), char, font=f, fill=fill)
        x += f.getlength(char) + track

    return x - track


def label(draw, xy, text, fill=MUTED, size=10, scale=S):
    """The one label style in the set: small, capitals, generously spaced."""
    return tracked(draw, xy, text.upper(), face("ui-bold", size * scale), fill, 2.1 * scale)


# --------------------------------------------------------------------------
# The seal -- the signature artifact
# --------------------------------------------------------------------------

def seal(draw, xy, digest, intact=True, scale=S):
    """The document hash, drawn as the wax seal it functionally is.

    A sha256 of the bytes is what tells a mark whether the page underneath it is
    still the page it was made on. Whole and mint while it matches; cracked and
    burnt orange once it does not. Nothing else in the set uses these two
    colours, so the state is legible before a single character is read.
    """
    x, y = xy
    r = 6 * scale
    colour = SEAL if intact else BROKEN
    cx, cy = x + r + 3 * scale, y + r + 3 * scale

    hair = max(1, int(1.4 * scale))
    ring = r + 3 * scale

    if intact:
        # Pressed: a solid disc inside a hairline ring.
        draw.ellipse([cx - ring, cy - ring, cx + ring, cy + ring], outline=colour, width=hair)
        draw.ellipse([cx - r * 0.62, cy - r * 0.62, cx + r * 0.62, cy + r * 0.62], fill=colour)
    else:
        # Broken: the ring is still there, and the disc has cracked across it.
        draw.ellipse([cx - ring, cy - ring, cx + ring, cy + ring], outline=colour, width=hair)
        draw.line([(cx - ring * 0.78, cy + ring * 0.78), (cx + ring * 0.78, cy - ring * 0.78)], fill=colour, width=hair)

    f = face("data", 13 * scale)
    tx = cx + ring + 9 * scale

    draw.text((tx, cy), digest, font=f, fill=colour if not intact else MUTED, anchor="lm")

    if not intact:
        width = f.getlength(digest)
        draw.line([(tx, cy), (tx + width, cy)], fill=colour, width=max(1, int(scale)))

    return tx + f.getlength(digest)


# --------------------------------------------------------------------------
# The coordinate maths, transcribed from js/src/coordinates.js
# --------------------------------------------------------------------------

def to_viewport(anchor, page, turns, scale):
    """A stored anchor to the box to draw, at this rotation and zoom.

    Bottom-left origin in points to top-left origin in pixels: the y flip, then
    the rotation, then the scale. Both corners are converted and re-sorted,
    because a quarter turn swaps which corner is which.
    """
    w, h = page
    x1, y1, x2, y2 = anchor

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
    """The formula against the pixels. Fails the build if they part."""
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


# --------------------------------------------------------------------------
# The document
# --------------------------------------------------------------------------

MARGIN = 64.0
BODY_PT = 16
REF = 3.0  # the page is typeset once at this multiple and then scaled

DOCUMENT = [
    ("Contract of supply", "eyebrow"),
    ("3.  Payment", "heading"),
    ("The Customer shall settle each invoice in full", "body"),
    ("within thirty (30) days of the invoice date.", "body"),
    ("Amounts outstanding after that period carry", "body"),
    ("interest at four per cent above base rate.", "body"),
    ("4.  Delivery", "heading"),
    ("Goods are delivered to the address stated on the", "body"),
    ("order. Risk passes on delivery; title passes on", "body"),
    ("payment in full.", "body"),
    ("5.  Termination", "heading"),
    ("Either party may terminate on ninety (90) days'", "body"),
    ("written notice, or immediately on a material", "body"),
    ("breach left unremedied for fourteen (14) days.", "body"),
]

# Revision B. One word changes, and it is a word a mark was made on -- which is
# the entire argument for hashing the bytes.
REVISED = dict(DOCUMENT)
REVISION_B = [
    (t.replace("thirty (30)", "sixty (60)"), k) for t, k in DOCUMENT
]


def layout():
    """Each line with the baseline it sits on, in points up from the page foot."""
    y = 782.0
    out = []

    for text, kind in DOCUMENT:
        if kind == "eyebrow":
            out.append((text, kind, y))
            y -= 46
        elif kind == "heading":
            y -= 8
            out.append((text, kind, y))
            y -= 28
        else:
            out.append((text, kind, y))
            y -= 26

    return out


LINES = layout()


def phrase_anchor(index, phrase):
    """The anchor covering a phrase on one line, measured once, in points.

    Measured at the reference resolution and divided down, which is what the
    package does too: the anchor is worked out once and everything afterwards is
    derived from those four numbers.
    """
    text, _, baseline = LINES[index]
    body = face("doc", round(BODY_PT * REF))

    start = text.index(phrase)
    left = MARGIN + body.getlength(text[:start]) / REF
    right = left + body.getlength(phrase) / REF

    return (left - 2.0, baseline - 4.0, right + 2.0, baseline + BODY_PT - 1.0)


# A highlight over two lines is two rectangles, which is how it is stored.
HIGHLIGHT = [phrase_anchor(2, "in full"), phrase_anchor(3, "within thirty (30) days")]
SETTLED = [phrase_anchor(8, "Risk passes on delivery")]

_PAGES = {}


def document(scale, revision="a"):
    """The page as pixels: typeset once per revision, then scaled like a bitmap.

    Re-typesetting at each zoom would put the glyphs wherever the hinter felt
    like at that pixel size, and a highlight placed by arithmetic on the point
    positions would drift off the words. Real PDF rendering has no such problem
    -- glyph positions come from the text matrix and are exactly linear in scale
    -- so scaling one raster is the faithful thing here, not the shortcut.
    """
    if revision not in _PAGES:
        page = Image.new("RGB", (round(PAGE[0] * REF), round(PAGE[1] * REF)), PAPER)
        draw = ImageDraw.Draw(page)

        body = face("doc", round(BODY_PT * REF))
        heavy = face("doc-bold", round(BODY_PT * REF))
        lines = REVISION_B if revision == "b" else DOCUMENT

        for (original, kind, baseline), (text, _) in zip(LINES, lines):
            top = (PAGE[1] - baseline - BODY_PT) * REF

            if kind == "eyebrow":
                tracked(
                    draw,
                    (MARGIN * REF, top + 3 * REF),
                    text.upper(),
                    face("ui-bold", round(9 * REF)),
                    (150, 152, 142),
                    2.0 * REF,
                )
            else:
                draw.text((MARGIN * REF, top), text, font=heavy if kind == "heading" else body, fill=PAPER_INK)

        # Ruled lines below, so the page reads as a page and not as fourteen
        # lines floating on white.
        y = (PAGE[1] - 342.0) * REF
        for row in range(11):
            width = 467.0 if row % 4 else 320.0
            draw.rectangle(
                [MARGIN * REF, y, (MARGIN + width) * REF, y + 3.5 * REF],
                fill=PAPER_FAINT,
            )
            y += 26 * REF

        _PAGES[revision] = page

    width, height = round(PAGE[0] * scale), round(PAGE[1] * scale)

    return _PAGES[revision].resize((width, height), Image.LANCZOS)


def hatch(overlay, box, colour, step):
    """Diagonal hatching inside one rectangle -- how an orphan is drawn."""
    left, top, right, bottom = (int(v) for v in box)
    layer = Image.new("RGBA", overlay.size, (0, 0, 0, 0))
    pen = ImageDraw.Draw(layer)

    for offset in range(0, (right - left) + (bottom - top), step):
        pen.line([(left + offset, top), (left + offset - (bottom - top), bottom)], fill=colour + (170,), width=max(1, step // 4))

    mask = Image.new("L", overlay.size, 0)
    ImageDraw.Draw(mask).rectangle([left, top, right, bottom], fill=255)

    overlay.paste(layer, (0, 0), mask)


def marked(scale, turns, anchors, state, revision="a", others=()):
    """A rendered page with its marks laid on after.

    The order is the point: the words are scaled and rotated pixels, and the
    marks are placed by the formula. They line up only if the formula is right.
    """
    page = document(scale, revision)

    if turns:
        page = page.rotate(-90 * turns, expand=True)

    overlay = Image.new("RGBA", page.size, (0, 0, 0, 0))
    pen = ImageDraw.Draw(overlay)

    for group, kind in others:
        for anchor in group:
            box = to_viewport(anchor, PAGE, turns, scale)

            if kind == "settled":
                pen.rectangle(box, fill=MARKER + (52,))
                pen.line([(box[0], (box[1] + box[3]) / 2), (box[2], (box[1] + box[3]) / 2)], fill=(120, 128, 112, 190), width=max(1, int(scale * 1.2)))

    for index, anchor in enumerate(anchors):
        box = to_viewport(anchor, PAGE, turns, scale)

        if state == "orphaned":
            pen.rectangle(box, fill=BROKEN + (34,))
            hatch(overlay, box, BROKEN, max(4, int(7 * scale)))
            pen.rectangle(box, outline=BROKEN + (255,), width=max(1, int(1.4 * scale)))
        else:
            pen.rectangle(box, fill=MARKER + (150,))

            if state == "selected" and index == 0:
                pen.rectangle(box, outline=(40, 44, 34, 210), width=max(1, int(scale)))

    return Image.alpha_composite(page.convert("RGBA"), overlay).convert("RGB")


def window(size, scale, turns, anchors, state, revision="a", others=(), align="mark"):
    """A fixed viewport onto the page, framed on the marks.

    A viewer shows part of a page, not a whole one shrunk to fit. Which part
    depends on what the picture is arguing:

    `mark` centres on the rectangles, which is what lets three windows at three
    zooms and rotations show the same clause. `left` keeps the page's own left
    margin in view and only centres vertically -- so a reader sees lines
    beginning where lines begin, rather than a column of words sliced mid-
    syllable, which is what two pages being compared need.
    """
    page = marked(scale, turns, anchors, state, revision, others)
    boxes = [to_viewport(a, PAGE, turns, scale) for a in anchors]

    cx = (min(b[0] for b in boxes) + max(b[2] for b in boxes)) / 2
    cy = (min(b[1] for b in boxes) + max(b[3] for b in boxes)) / 2

    offset_x = -MARGIN * scale + 14 if align == "left" else size[0] / 2 - cx

    view = Image.new("RGB", size, PAPER)
    view.paste(page, (int(offset_x), int(size[1] / 2 - cy)))

    return view


def frame(canvas, box, radius=0):
    """A desk-coloured panel with a hairline. The only container in the set."""
    ImageDraw.Draw(canvas).rounded_rectangle(box, radius=radius, fill=DESK, outline=RULE)


def caption(draw, y, text, width):
    """The one plain sentence under each picture."""
    draw.line([(u(28), y), (width - u(28), y)], fill=RULE)
    draw.text((u(28), y + u(14)), text, font=face("ui", 14 * S), fill=MUTED)


def eyebrow(draw, title, note, width):
    label(draw, (u(28), u(26)), "Pindle", TEXT, 11)
    draw.text((u(28), u(48)), title, font=face("ui-bold", 21 * S), fill=TEXT)

    if note:
        draw.text((width - u(28), u(30)), note, font=face("ui", 14 * S), fill=FAINT, anchor="ra")


# --------------------------------------------------------------------------
# hero.png -- the viewer
# --------------------------------------------------------------------------

def rotate_icon(draw, cx, cy, r, colour):
    draw.arc([cx - r, cy - r, cx + r, cy + r], start=300, end=210, fill=colour, width=max(1, int(1.6 * S)))
    draw.polygon(
        [(cx + r - 2 * S, cy - r), (cx + r + 2 * S, cy - r + S), (cx + r - S, cy - r + 4 * S)],
        fill=colour,
    )


def hero():
    W, H = u(890), u(566)
    canvas = Image.new("RGB", (W, H), INK)
    draw = ImageDraw.Draw(canvas)

    eyebrow(draw, "A document review, inside your admin panel", "heyosseus/pindle", W)

    win = [u(28), u(86), W - u(28), u(500)]
    frame(canvas, win, radius=u(8))

    # -- toolbar -------------------------------------------------------
    bar_h = u(40)
    draw.rectangle([win[0] + 1, win[1] + 1, win[2] - 1, win[1] + bar_h], fill=(35, 39, 31))
    draw.line([(win[0], win[1] + bar_h), (win[2], win[1] + bar_h)], fill=RULE)

    cx = win[0] + u(14)
    mid = win[1] + bar_h / 2

    for text, w in (("−", u(26)), ("100%", u(42)), ("+", u(26))):
        draw.rounded_rectangle([cx, mid - u(11), cx + w, mid + u(11)], radius=u(3), outline=RULE)
        draw.text((cx + w / 2, mid), text, font=face("ui", 13 * S), fill=MUTED, anchor="mm")
        cx += w + u(6)

    draw.rounded_rectangle([cx, mid - u(11), cx + u(26), mid + u(11)], radius=u(3), outline=RULE)
    rotate_icon(draw, cx + u(13), mid, u(6), MUTED)
    cx += u(38)

    draw.rounded_rectangle([cx, mid - u(11), cx + u(112), mid + u(11)], radius=u(3), fill=(45, 51, 39), outline=RULE)
    draw.text((cx + u(56), mid), "Keep selection", font=face("ui", 13 * S), fill=TEXT, anchor="mm")

    # The seal sits in the toolbar, where a reviewer can see at a glance that
    # the page in front of them is the page the marks were made on.
    seal(draw, (win[2] - u(190), mid - u(7)), "sha256 4f3a9c21", intact=True)

    # -- the page ------------------------------------------------------
    body_top = win[1] + bar_h + 1
    body_bottom = win[3] - 1
    thread_w = u(258)
    page_right = win[2] - thread_w

    draw.rectangle([win[0] + 1, body_top, page_right, body_bottom], fill=(24, 27, 21))

    scale = 0.74 * S
    page = marked(scale, 0, HIGHLIGHT, "selected", others=[(SETTLED, "settled")])

    px = int(win[0] + 1 + ((page_right - win[0]) - page.width) / 2)
    py = int(body_top + u(16))
    visible = page.crop((0, 0, page.width, min(page.height, body_bottom - py)))

    canvas.paste(visible, (px, py))
    draw.rectangle([px, py, px + visible.width - 1, py + visible.height - 1], outline=(60, 66, 54))

    # -- the thread ----------------------------------------------------
    tx = page_right + 1
    draw.rectangle([tx, body_top, win[2] - 1, body_bottom], fill=DESK)
    draw.line([(tx, body_top), (tx, body_bottom)], fill=RULE)

    inner = tx + u(18)
    y = body_top + u(18)

    draw.text((inner, y), "Page 1", font=face("ui-bold", 14 * S), fill=TEXT)
    draw.text((win[2] - u(18), y), "Resolve", font=face("ui", 13 * S), fill=TEXT, anchor="ra")

    y += u(30)
    label(draw, (inner, y), "Highlight · open")

    y += u(26)

    thread = [
        ("Reviewer", ["The payment terms say thirty days,", "but the purchase order says sixty."], False),
        ("Finance", ["Confirmed — the order is right.", "Revision B is on its way."], True),
    ]

    for who, body, reply in thread:
        left = inner + (u(14) if reply else 0)

        if reply:
            draw.line([(inner + u(4), y - u(2)), (inner + u(4), y + u(46))], fill=RULE, width=max(1, int(1.5 * S)))

        draw.text((left, y), who, font=face("ui-bold", 13 * S), fill=TEXT)
        y += u(19)

        for line in body:
            draw.text((left, y), line, font=face("ui", 13 * S), fill=MUTED)
            y += u(18)

        y += u(12)

    # An empty box with a caret rather than placeholder text: the button below
    # already says what the box is for, and a hero image is not the place to
    # print the same four words twice.
    box_top = body_bottom - u(74)
    draw.rounded_rectangle([inner, box_top, win[2] - u(18), box_top + u(40)], radius=u(4), outline=RULE)
    draw.line([(inner + u(11), box_top + u(11)), (inner + u(11), box_top + u(27))], fill=MUTED, width=max(1, int(1.4 * S)))

    # Paper-coloured, not mint. Mint means one thing in this set -- the seal is
    # intact -- and a button borrowing it would make the signature ambiguous.
    draw.rounded_rectangle([inner, box_top + u(48), inner + u(94), box_top + u(70)], radius=u(4), fill=PAPER)
    draw.text((inner + u(47), box_top + u(59)), "Add comment", font=face("ui-bold", 11 * S), fill=(24, 27, 21), anchor="mm")

    caption(
        draw,
        u(524),
        "Highlight, resolve, discuss. Nothing is ever written back into the PDF.",
        W,
    )

    canvas.save(ART / "hero.png")
    print("wrote hero.png")


# --------------------------------------------------------------------------
# anchoring.png -- one anchor, three renderings
# --------------------------------------------------------------------------

def anchoring():
    W, H = u(890), u(470)
    canvas = Image.new("RGB", (W, H), INK)
    draw = ImageDraw.Draw(canvas)

    eyebrow(draw, "One anchor, stored once, redrawn every time", "js/test/coordinates.test.js", W)

    # One anchor, not the pair. The card prints its four numbers and the
    # windows show that same rectangle three ways -- if the card described one
    # mark and the eye followed another, the picture would prove nothing.
    only = [HIGHLIGHT[1]]

    card = [u(28), u(100), u(286), u(400)]
    frame(canvas, card, radius=u(8))

    label(draw, (card[0] + u(20), card[1] + u(20)), "Stored", SEAL)
    draw.text(
        (card[0] + u(20), card[1] + u(40)),
        "PDF points · bottom-left origin",
        font=face("ui", 13 * S),
        fill=FAINT,
    )

    anchor = only[0]
    for row, (name, value) in enumerate(zip(("x1", "y1", "x2", "y2"), anchor)):
        y = card[1] + u(74) + row * u(34)
        draw.text((card[0] + u(20), y + u(8)), name, font=face("data", 13 * S), fill=FAINT)
        draw.text((card[0] + u(52), y), f"{value:7.2f}", font=face("data-bold", 25 * S), fill=TEXT)

    draw.text(
        (card[0] + u(20), card[3] - u(38)),
        "Nothing about a screen is kept here.",
        font=face("ui", 13 * S),
        fill=MUTED,
    )

    # Three windows, one per rendering. Same four numbers into all of them.
    views = [(1.00, 0), (1.50, 1), (0.60, 2)]
    size = (u(172), u(250))
    gap = u(20)
    start = u(318)
    y = u(112)

    for scale, _ in views:
        check_rotation(only, scale * S)

    # A single hairline running out of the card and straight through all three
    # windows at the height of the mark. Drawn first, so it shows only in the
    # gaps: the same anchor passing through three renderings, rather than three
    # separate arrows implying three separate things.
    through = y + size[1] // 2
    draw.line([(card[2], through), (W - u(28), through)], fill=RULE, width=max(1, int(1.5 * S)))

    for index, (scale, turns) in enumerate(views):
        x = start + index * (size[0] + gap)

        canvas.paste(window(size, scale * S, turns, only, "open"), (x, y))
        draw.rectangle([x, y, x + size[0] - 1, y + size[1] - 1], outline=RULE)

        draw.text(
            (x, y + size[1] + u(14)),
            f"{scale * 100:.0f}%  ·  {turns * 90}°",
            font=face("data", 13 * S),
            fill=MUTED,
        )

    caption(
        draw,
        u(424),
        "Same clause, same four numbers. Store viewport pixels instead and the mark moves the "
        "first time anybody zooms.",
        W,
    )

    canvas.save(ART / "anchoring.png")
    print("wrote anchoring.png")


# --------------------------------------------------------------------------
# orphan.png -- the seal breaking
# --------------------------------------------------------------------------

def record_panel(canvas, box, orphaned):
    """The annotation as a row, in the same language the GIF uses."""
    draw = ImageDraw.Draw(canvas)
    frame(canvas, box, radius=u(8))

    x = box[0] + u(20)
    value_x = box[0] + u(136)

    label(draw, (x, box[1] + u(18)), "Annotation", SEAL)
    draw.text((box[2] - u(20), box[1] + u(18)), "01JQ8F7K2M…", font=face("data", 12 * S), fill=FAINT, anchor="ra")
    draw.line([(box[0] + 1, box[1] + u(44)), (box[2] - 1, box[1] + u(44))], fill=RULE)

    rows = [
        ("annotatable", "Invoice #4471", TEXT),
        ("page", "1", TEXT),
        ("type", "highlight", TEXT),
        ("rects", "2 rectangles", TEXT),
        ("document_hash", "9c17be04…" if orphaned else "4f3a9c21…", BROKEN if orphaned else TEXT),
        ("orphaned", "true" if orphaned else "false", BROKEN if orphaned else MUTED),
    ]

    y = box[1] + u(60)

    for name, value, colour in rows:
        draw.text((x, y), name, font=face("data", 13 * S), fill=FAINT)
        draw.text((value_x, y), value, font=face("data-bold" if colour is BROKEN else "data", 13 * S), fill=colour)
        y += u(24)


def orphan():
    W, H = u(890), u(440)
    canvas = Image.new("RGB", (W, H), INK)
    draw = ImageDraw.Draw(canvas)

    eyebrow(draw, "When the contract is re-issued", "one word changed", W)

    size = (u(230), u(196))
    y = u(104)

    panels = [
        (u(28), "a", "open", "sha256 4f3a9c21", True, "Revision A", "The mark was made here."),
        (u(288), "b", "orphaned", "sha256 9c17be04", False, "Revision B", "Thirty days became sixty."),
    ]

    for x, revision, state, digest, intact, name, note in panels:
        canvas.paste(window(size, 0.78 * S, 0, HIGHLIGHT, state, revision, align="left"), (x, y))
        draw.rectangle([x, y, x + size[0] - 1, y + size[1] - 1], outline=RULE)

        label(draw, (x, y + size[1] + u(16)), name, TEXT)
        seal(draw, (x, y + size[1] + u(36)), digest, intact)
        draw.text((x, y + size[1] + u(64)), note, font=face("ui", 13 * S), fill=MUTED)

    record_panel(canvas, [u(548), y, W - u(28), y + u(196)], orphaned=True)

    caption(
        draw,
        u(396),
        "The seal is a sha256 of the bytes. When it breaks, every mark on the page is flagged — "
        "and Pindle offers to go and find the words again.",
        W,
    )

    canvas.save(ART / "orphan.png")
    print("wrote orphan.png")


# --------------------------------------------------------------------------
# demo.gif -- a mark becomes a row, and then the contract is re-issued
#
# Zoom and rotation are deliberately not in this picture. Every PDF viewer
# zooms; nobody installs a package for it. What Pindle does that a viewer does
# not is put the mark in your database, under your policies, where you can ask
# it questions -- and tell you when the page underneath it has been replaced.
# --------------------------------------------------------------------------

def ease(t):
    return t * t * (3 - 2 * t)


def demo():
    W, H = 900, 470
    PAD = 28
    PAGE_W = 372
    REC_X = PAD + PAGE_W + 24
    REC_W = W - PAD - REC_X
    TOP = 62
    BOTTOM = H - PAD - 26

    mono = face("data", 12)
    mono_bold = face("data-bold", 12)
    ui = face("ui", 12)

    rows = [
        ("annotatable", "Invoice #4471"),
        ("document_key", "default"),
        ("page", "1"),
        ("type", "highlight"),
        ("rects", "2 rectangles, PDF points"),
    ] + [("", "x1 {:6.2f}  y1 {:6.2f}  x2 {:6.2f}  y2 {:6.2f}".format(*a)) for a in HIGHLIGHT]

    def render(state):
        canvas = Image.new("RGB", (W, H), INK)
        draw = ImageDraw.Draw(canvas)

        label(draw, (PAD, 22), "Pindle", TEXT, 11, scale=1)
        draw.text((W - PAD, 22), "annotations that live in your database", font=ui, fill=FAINT, anchor="ra")

        # -- the page --------------------------------------------------
        draw.rectangle([PAD, TOP, PAD + PAGE_W, BOTTOM], fill=DESK, outline=RULE)

        zoom = 0.72
        offset = (-MARGIN * zoom + 14, -26)

        page = marked(zoom, 0, HIGHLIGHT if state["mark"] > 0 else [],
                      "orphaned" if state["orphaned"] else "open",
                      "b" if state["revised"] else "a")

        view = Image.new("RGB", (PAGE_W - 2, BOTTOM - TOP - 2), PAPER)
        view.paste(page, (int(offset[0]), int(offset[1])))

        # The mark sweeps on rather than appearing, so a reader watches it being
        # drawn instead of wondering what changed. Painting paper back over the
        # unswept remainder is cheaper than re-rendering the page per frame.
        if 0 < state["mark"] < 1:
            cover = ImageDraw.Draw(view)

            for anchor in HIGHLIGHT:
                box = to_viewport(anchor, PAGE, 0, zoom)
                left = box[0] + offset[0]

                cover.rectangle(
                    [left + (box[2] - box[0]) * state["mark"], box[1] + offset[1],
                     left + (box[2] - box[0]), box[3] + offset[1]],
                    fill=PAPER,
                )

        canvas.paste(view, (PAD + 1, TOP + 1))

        if state["revised"]:
            text = "Revision B uploaded"
            width = ui.getlength(text) + 18
            draw.rounded_rectangle([PAD + PAGE_W - width - 10, TOP + 10, PAD + PAGE_W - 10, TOP + 30], radius=10, fill=(74, 42, 18))
            draw.text((PAD + PAGE_W - width / 2 - 10, TOP + 20), text, font=ui, fill=BROKEN, anchor="mm")

        # -- the record ------------------------------------------------
        draw.rectangle([REC_X, TOP, REC_X + REC_W, BOTTOM], fill=DESK, outline=RULE)

        x = REC_X + 16
        value_x = REC_X + 120

        label(draw, (x, TOP + 14), "Annotation", SEAL, 10, scale=1)
        draw.text((REC_X + REC_W - 16, TOP + 14), "01JQ8F7K2M…", font=mono, fill=FAINT, anchor="ra")
        draw.line([(REC_X + 1, TOP + 38), (REC_X + REC_W - 1, TOP + 38)], fill=RULE)

        y = TOP + 50

        for index, (name, value) in enumerate(rows):
            if index >= state["rows"]:
                break

            if name:
                draw.text((x, y), name, font=mono, fill=FAINT)

            draw.text((value_x if name else x + 16, y), value, font=mono, fill=TEXT)
            y += 17

        if state["rows"] > len(rows):
            seal(draw, (x, y - 3), "9c17be04…" if state["orphaned"] else "4f3a9c21…", not state["orphaned"], scale=1)
            y += 22

        if state["orphaned"]:
            draw.text((x, y), "orphaned", font=mono, fill=FAINT)
            draw.text((value_x, y), "true", font=mono_bold, fill=BROKEN)
            y += 20

        if state["comments"]:
            draw.line([(REC_X + 1, y + 6), (REC_X + REC_W - 1, y + 6)], fill=RULE)
            label(draw, (x, y + 18), "Comments", MUTED, 10, scale=1)
            draw.text((x, y + 38), "Reviewer", font=mono_bold, fill=TEXT)
            draw.text((x + 68, y + 38), "The order says sixty days —", font=mono, fill=MUTED)
            draw.text((x + 68, y + 54), "which is right?", font=mono, fill=MUTED)
            y += 76

        if state["query"]:
            qy = BOTTOM - 50
            draw.line([(REC_X + 1, qy - 12), (REC_X + REC_W - 1, qy - 12)], fill=RULE)
            draw.text((x, qy), "$invoice->annotations()", font=mono, fill=SEAL)
            draw.text((x, qy + 16), "        ->unresolved()->count()", font=mono, fill=SEAL)
            draw.text((REC_X + REC_W - 16, qy + 10), "1", font=face("data-bold", 20), fill=TEXT, anchor="rm")

        draw.line([(PAD, H - PAD - 14), (W - PAD, H - PAD - 14)], fill=RULE)
        draw.text((PAD, H - PAD - 6), state["caption"], font=ui, fill=MUTED)

        return canvas.convert("P", palette=Image.ADAPTIVE, colors=128)

    def state(mark=0.0, rows=0, comments=False, query=False, revised=False, orphaned=False, caption=""):
        return {
            "mark": mark, "rows": rows, "comments": comments, "query": query,
            "revised": revised, "orphaned": orphaned, "caption": caption,
        }

    frames = []
    hold = lambda s, n: frames.extend([render(s)] * n)  # noqa: E731

    hold(state(caption="A clause somebody needs to object to."), 7)

    for i in range(1, 7):
        frames.append(render(state(mark=ease(i / 6), caption="Highlight it.")))

    for index in range(1, len(rows) + 2):
        frames.append(render(state(mark=1.0, rows=index, caption="It is a row in your database.")))

    full = dict(mark=1.0, rows=len(rows) + 1)

    hold(state(**full, caption="It is a row in your database."), 4)
    hold(state(**full, query=True, caption="So you can ask questions of it."), 9)
    hold(state(**full, query=True, comments=True, caption="And discuss it, in your app."), 9)
    hold(state(**full, query=True, comments=True, revised=True, caption="Then the contract is re-issued."), 4)
    hold(
        state(**full, query=True, comments=True, revised=True, orphaned=True,
              caption="The seal breaks, and the mark says so instead of guessing."),
        15,
    )

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


if __name__ == "__main__":
    check_rotation(HIGHLIGHT, 1.0)

    hero()
    anchoring()
    orphan()
    demo()
