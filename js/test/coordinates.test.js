import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';

import {
  anchorToViewport,
  boxToPage,
  boxToViewport,
  clampToPage,
  pageToUser,
  pointToPage,
  pointToViewport,
  quarterTurns,
  roundAnchor,
  textRectsToAnchors,
  userToPage,
  viewportSize,
  viewportToAnchor,
} from '../src/coordinates.js';

const A4 = { width: 595, height: 842 };
const ROTATIONS = [0, 1, 2, 3];
const SCALES = [0.5, 1, 1.25, 2, 3.7];

const close = (actual, expected, message) =>
  assert.ok(Math.abs(actual - expected) < 1e-9, `${message}: ${actual} != ${expected}`);

const closeRect = (actual, expected, message) => {
  for (const key of ['x1', 'y1', 'x2', 'y2']) {
    close(actual[key], expected[key], `${message}.${key}`);
  }
};

describe('rotation input', () => {
  it('accepts quarter turns and degrees alike', () => {
    assert.equal(quarterTurns(0), 0);
    assert.equal(quarterTurns(1), 1);
    assert.equal(quarterTurns(90), 1);
    assert.equal(quarterTurns(180), 2);
    assert.equal(quarterTurns(270), 3);
  });

  it('wraps anything else back into range rather than throwing', () => {
    assert.equal(quarterTurns(4), 0);
    assert.equal(quarterTurns(-1), 3);
    assert.equal(quarterTurns(undefined), 0);
    assert.equal(quarterTurns('nonsense'), 0);
  });
});

describe('viewport size', () => {
  it('scales the page', () => {
    assert.deepEqual(viewportSize(A4, 0, 2), { width: 1190, height: 1684 });
  });

  it('swaps the axes on a quarter turn', () => {
    assert.deepEqual(viewportSize(A4, 90, 1), { width: 842, height: 595 });
    assert.deepEqual(viewportSize(A4, 270, 1), { width: 842, height: 595 });
    assert.deepEqual(viewportSize(A4, 180, 1), { width: 595, height: 842 });
  });
});

describe('the y flip', () => {
  it('turns a bottom-left anchor into a top-left box', () => {
    assert.deepEqual(userToPage({ x1: 72, y1: 700, x2: 300, y2: 720 }, A4), {
      left: 72,
      top: 122,
      right: 300,
      bottom: 142,
    });
  });

  it('turns it back', () => {
    assert.deepEqual(pageToUser({ left: 72, top: 122, right: 300, bottom: 142 }, A4), {
      x1: 72,
      y1: 700,
      x2: 300,
      y2: 720,
    });
  });

  it('sorts a backwards anchor as it flips it', () => {
    assert.deepEqual(userToPage({ x1: 300, y1: 720, x2: 72, y2: 700 }, A4), {
      left: 72,
      top: 122,
      right: 300,
      bottom: 142,
    });
  });
});

describe('point placement', () => {
  it('puts the page origin where the rotation says it goes', () => {
    // The top-left corner of the page, at each rotation, at scale 1.
    assert.deepEqual(pointToViewport({ x: 0, y: 0 }, A4, 0, 1), { x: 0, y: 0 });
    assert.deepEqual(pointToViewport({ x: 0, y: 0 }, A4, 90, 1), { x: 842, y: 0 });
    assert.deepEqual(pointToViewport({ x: 0, y: 0 }, A4, 180, 1), { x: 595, y: 842 });
    assert.deepEqual(pointToViewport({ x: 0, y: 0 }, A4, 270, 1), { x: 0, y: 595 });
  });

  it('keeps every corner inside the rotated viewport', () => {
    const corners = [
      { x: 0, y: 0 },
      { x: A4.width, y: 0 },
      { x: 0, y: A4.height },
      { x: A4.width, y: A4.height },
    ];

    for (const rotation of ROTATIONS) {
      for (const scale of SCALES) {
        const size = viewportSize(A4, rotation, scale);

        for (const corner of corners) {
          const placed = pointToViewport(corner, A4, rotation, scale);

          assert.ok(placed.x >= -1e-9 && placed.x <= size.width + 1e-9, 'x within viewport');
          assert.ok(placed.y >= -1e-9 && placed.y <= size.height + 1e-9, 'y within viewport');
        }
      }
    }
  });

  it('is exactly reversible at every rotation and scale', () => {
    const point = { x: 123.456, y: 654.321 };

    for (const rotation of ROTATIONS) {
      for (const scale of SCALES) {
        const there = pointToViewport(point, A4, rotation, scale);
        const back = pointToPage(there, A4, rotation, scale);

        close(back.x, point.x, `x at rotation ${rotation} scale ${scale}`);
        close(back.y, point.y, `y at rotation ${rotation} scale ${scale}`);
      }
    }
  });
});

describe('box placement', () => {
  it('never comes back with a negative width after a quarter turn', () => {
    const box = { left: 72, top: 100, right: 300, bottom: 120 };

    for (const rotation of ROTATIONS) {
      const placed = boxToViewport(box, A4, rotation, 1);

      assert.ok(placed.right >= placed.left, `width at rotation ${rotation}`);
      assert.ok(placed.bottom >= placed.top, `height at rotation ${rotation}`);
    }
  });

  it('swaps a box\'s width and height on a quarter turn', () => {
    const box = { left: 72, top: 100, right: 272, bottom: 120 };

    const upright = boxToViewport(box, A4, 0, 1);
    const turned = boxToViewport(box, A4, 90, 1);

    close(upright.right - upright.left, 200, 'upright width');
    close(turned.bottom - turned.top, 200, 'turned height');
    close(turned.right - turned.left, 20, 'turned width');
  });

  it('is reversible at every rotation and scale', () => {
    const box = { left: 72.5, top: 100.25, right: 300.75, bottom: 120.125 };

    for (const rotation of ROTATIONS) {
      for (const scale of SCALES) {
        const back = boxToPage(boxToViewport(box, A4, rotation, scale), A4, rotation, scale);

        for (const key of ['left', 'top', 'right', 'bottom']) {
          close(back[key], box[key], `${key} at rotation ${rotation} scale ${scale}`);
        }
      }
    }
  });
});

describe('the full anchoring round trip', () => {
  const anchor = { x1: 72, y1: 700.2, x2: 310.5, y2: 715.8 };

  it('returns the anchor unchanged at every rotation and scale', () => {
    for (const rotation of ROTATIONS) {
      for (const scale of SCALES) {
        const drawn = anchorToViewport(anchor, A4, rotation, scale);
        const stored = viewportToAnchor(drawn, A4, rotation, scale);

        closeRect(stored, anchor, `rotation ${rotation} scale ${scale}`);
      }
    }
  });

  it('places the same anchor at proportionally the same spot at any zoom', () => {
    const atOne = anchorToViewport(anchor, A4, 0, 1);
    const atThree = anchorToViewport(anchor, A4, 0, 3);

    close(atThree.left, atOne.left * 3, 'left scales');
    close(atThree.top, atOne.top * 3, 'top scales');
  });

  it('draws a highlight near the top of an upright page near the top of the viewport', () => {
    // y1 = 700 of 842 is high on the page, so it must be low in `top`.
    const drawn = anchorToViewport(anchor, A4, 0, 1);

    assert.ok(drawn.top < A4.height / 2, 'a high anchor draws high on the page');
    close(drawn.top, 842 - 715.8, 'top is the flipped upper edge');
    close(drawn.left, 72, 'left is untouched at rotation 0');
  });

  it('moves that same highlight to the right edge when the page is turned 90', () => {
    // Rotation is clockwise, so the top edge of the page swings round to become
    // the right edge of the viewport.
    const drawn = anchorToViewport(anchor, A4, 90, 1);
    const size = viewportSize(A4, 90, 1);

    assert.ok(drawn.left > size.width / 2, 'the top of the page is now its right');
  });

  it('moves it to the left edge when the page is turned 270', () => {
    const drawn = anchorToViewport(anchor, A4, 270, 1);
    const size = viewportSize(A4, 270, 1);

    assert.ok(drawn.right < size.width / 2, 'the top of the page is now its left');
  });

  it('survives a rotation applied after the anchor was stored', () => {
    // The anchor is drawn upright, the reader then turns the page, and what is
    // read back must still be the anchor that was stored -- this is the case
    // that a viewport-coordinate scheme gets wrong.
    const drawnUpright = anchorToViewport(anchor, A4, 0, 1);
    const storedUpright = viewportToAnchor(drawnUpright, A4, 0, 1);

    const drawnTurned = anchorToViewport(storedUpright, A4, 270, 2.5);
    const storedTurned = viewportToAnchor(drawnTurned, A4, 270, 2.5);

    closeRect(storedTurned, anchor, 'anchor survives the turn');
  });
});

describe('clamping', () => {
  it('pulls an anchor back onto the page', () => {
    assert.deepEqual(clampToPage({ x1: -10, y1: -5, x2: 700, y2: 900 }, A4), {
      x1: 0,
      y1: 0,
      x2: 595,
      y2: 842,
    });
  });

  it('sorts as it clamps', () => {
    assert.deepEqual(clampToPage({ x1: 300, y1: 400, x2: 100, y2: 200 }, A4), {
      x1: 100,
      y1: 200,
      x2: 300,
      y2: 400,
    });
  });

  it('leaves an anchor that already fits alone', () => {
    const anchor = { x1: 72, y1: 700, x2: 300, y2: 720 };

    assert.deepEqual(clampToPage(anchor, A4), anchor);
  });
});

describe('rounding', () => {
  it('drops float noise without moving the anchor anywhere visible', () => {
    assert.deepEqual(roundAnchor({ x1: 72.00000001, y1: 700.4445, x2: 310.5, y2: 715.8 }), {
      x1: 72,
      y1: 700.445,
      x2: 310.5,
      y2: 715.8,
    });
  });
});

describe('text selection', () => {
  it('keeps one anchor per line rather than one box around them all', () => {
    const anchors = textRectsToAnchors(
      [
        { origin: { x: 72, y: 100 }, size: { width: 450, height: 14 } },
        { origin: { x: 72, y: 118 }, size: { width: 450, height: 14 } },
        { origin: { x: 72, y: 136 }, size: { width: 230, height: 14 } },
      ],
      A4,
    );

    assert.equal(anchors.length, 3);
    assert.deepEqual(anchors[0], { x1: 72, y1: 728, x2: 522, y2: 742 });
    assert.deepEqual(anchors[2], { x1: 72, y1: 692, x2: 302, y2: 706 });
  });

  it('clamps a run that reports past the edge of the page', () => {
    const anchors = textRectsToAnchors(
      [{ origin: { x: 580, y: 100 }, size: { width: 100, height: 14 } }],
      A4,
    );

    assert.equal(anchors[0].x2, 595);
  });
});
