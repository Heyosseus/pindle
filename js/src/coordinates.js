/**
 * The conversion between where a thing is drawn and where it is stored.
 *
 * Pindle persists PDF user space: origin at the *bottom-left* of the page, unit
 * the point, y increasing upwards. The browser works in the opposite convention
 * -- origin top-left, y down, unit the CSS pixel, and everything multiplied by
 * whatever zoom is in effect and turned by whatever rotation the reader chose.
 *
 * Every one of those four differences has to be undone on the way in and redone
 * on the way out, and getting any of them wrong puts a highlight over the wrong
 * sentence. So this module is pure arithmetic with no DOM in it, and it is
 * tested exhaustively at several scales and at all four rotations rather than
 * being checked by eye in a browser.
 *
 * EmbedPDF ships `transformRect` and `restoreRect`, which look like they would
 * do this. They are not inverses of each other under rotation -- restoring a
 * transformed rect at 90 degrees does not give the rect back -- so the maths
 * here is Pindle's own, and the round-trip property is asserted rather than
 * assumed.
 */

/**
 * Normalise a rotation given as either quarter turns (0-3, EmbedPDF's own
 * enum) or degrees (0/90/180/270) to quarter turns.
 */
export function quarterTurns(rotation) {
  const value = Number(rotation) || 0;

  if (value === 90 || value === 180 || value === 270) {
    return value / 90;
  }

  return ((Math.round(value) % 4) + 4) % 4;
}

/**
 * The size of the rendered page in CSS pixels, which swaps under a quarter turn.
 */
export function viewportSize(page, rotation, scale) {
  const turns = quarterTurns(rotation);
  const width = page.width * scale;
  const height = page.height * scale;

  return turns % 2 === 0 ? { width, height } : { width: height, height: width };
}

/**
 * User space (bottom-left origin) to page space (top-left origin), both in points.
 *
 * This is the y flip, and nothing else. Kept separate from rotation and scale
 * because it is the one part that is about PDF's convention rather than about
 * how the page happens to be displayed right now.
 */
export function userToPage(rect, page) {
  return {
    left: Math.min(rect.x1, rect.x2),
    top: page.height - Math.max(rect.y1, rect.y2),
    right: Math.max(rect.x1, rect.x2),
    bottom: page.height - Math.min(rect.y1, rect.y2),
  };
}

/**
 * Page space back to user space. The same flip, applied again.
 */
export function pageToUser(box, page) {
  return {
    x1: Math.min(box.left, box.right),
    y1: page.height - Math.max(box.top, box.bottom),
    x2: Math.max(box.left, box.right),
    y2: page.height - Math.min(box.top, box.bottom),
  };
}

/**
 * One point in page space to its place in the rendered viewport.
 *
 * Rotation is clockwise, which is the direction every PDF reader turns a page
 * when you press the button marked with a clockwise arrow.
 */
export function pointToViewport(point, page, rotation, scale) {
  const turns = quarterTurns(rotation);

  switch (turns) {
    case 1:
      return { x: (page.height - point.y) * scale, y: point.x * scale };
    case 2:
      return { x: (page.width - point.x) * scale, y: (page.height - point.y) * scale };
    case 3:
      return { x: point.y * scale, y: (page.width - point.x) * scale };
    default:
      return { x: point.x * scale, y: point.y * scale };
  }
}

/**
 * The inverse of {@link pointToViewport}.
 */
export function pointToPage(point, page, rotation, scale) {
  const turns = quarterTurns(rotation);
  const x = point.x / scale;
  const y = point.y / scale;

  switch (turns) {
    case 1:
      return { x: y, y: page.height - x };
    case 2:
      return { x: page.width - x, y: page.height - y };
    case 3:
      return { x: page.width - y, y: x };
    default:
      return { x, y };
  }
}

/** A box normalised so that left <= right and top <= bottom. */
function normalize(a, b) {
  return {
    left: Math.min(a.x, b.x),
    top: Math.min(a.y, b.y),
    right: Math.max(a.x, b.x),
    bottom: Math.max(a.y, b.y),
  };
}

/**
 * A page-space box to the CSS pixel box to draw it at.
 *
 * Both corners are converted and then re-normalised, because a quarter turn
 * swaps which corner is which -- the top-left of a rectangle becomes its
 * bottom-left at 90 degrees, and a box whose corners were not re-sorted would
 * come out with a negative width.
 */
export function boxToViewport(box, page, rotation, scale) {
  return normalize(
    pointToViewport({ x: box.left, y: box.top }, page, rotation, scale),
    pointToViewport({ x: box.right, y: box.bottom }, page, rotation, scale),
  );
}

/**
 * A CSS pixel box back to page space.
 */
export function boxToPage(box, page, rotation, scale) {
  return normalize(
    pointToPage({ x: box.left, y: box.top }, page, rotation, scale),
    pointToPage({ x: box.right, y: box.bottom }, page, rotation, scale),
  );
}

/**
 * A stored anchor to the CSS box the overlay draws.
 *
 * This is the whole journey in one call: bottom-left points to top-left pixels,
 * through the rotation and zoom currently in effect. Nothing it returns is ever
 * persisted.
 */
export function anchorToViewport(rect, page, rotation, scale) {
  return boxToViewport(userToPage(rect, page), page, rotation, scale);
}

/**
 * A CSS box the user just dragged, back to the anchor to store.
 */
export function viewportToAnchor(box, page, rotation, scale) {
  return pageToUser(boxToPage(box, page, rotation, scale), page);
}

/**
 * Clamp an anchor to the page it belongs on.
 *
 * A selection dragged past the edge of the page is a real thing users do, and
 * the server would reject the result. Clamping here means the highlight lands
 * on the last word rather than the request failing.
 */
export function clampToPage(rect, page) {
  const clamp = (value, max) => Math.min(Math.max(value, 0), max);

  return {
    x1: clamp(Math.min(rect.x1, rect.x2), page.width),
    y1: clamp(Math.min(rect.y1, rect.y2), page.height),
    x2: clamp(Math.max(rect.x1, rect.x2), page.width),
    y2: clamp(Math.max(rect.y1, rect.y2), page.height),
  };
}

/**
 * Round an anchor to a sane number of decimal places.
 *
 * A point is about a third of a millimetre, so three decimals is far finer than
 * any screen can express. It exists to stop float noise from a zoom of 1.3333
 * turning every save into a diff.
 */
export function roundAnchor(rect, places = 3) {
  const factor = 10 ** places;
  const round = (value) => Math.round(value * factor) / factor;

  return { x1: round(rect.x1), y1: round(rect.y1), x2: round(rect.x2), y2: round(rect.y2) };
}

/**
 * The rectangles a text selection covers, as anchors.
 *
 * EmbedPDF reports text rectangles in page space with a top-left origin, one
 * per run of text. They are passed through unchanged apart from the flip: a
 * highlight over three lines is three anchors, and merging them into one
 * bounding box would paint over the margin.
 */
export function textRectsToAnchors(rects, page) {
  return rects.map((rect) =>
    roundAnchor(
      clampToPage(
        pageToUser(
          {
            left: rect.origin.x,
            top: rect.origin.y,
            right: rect.origin.x + rect.size.width,
            bottom: rect.origin.y + rect.size.height,
          },
          page,
        ),
        page,
      ),
    ),
  );
}
