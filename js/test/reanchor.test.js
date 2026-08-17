import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';

import { locate, mergeByLine, normalise, rectsForRange } from '../src/reanchor.js';

const A4 = { width: 595, height: 842 };

/** Text runs laid out as lines, the way the engine reports them. */
function line(y, pieces) {
  let x = 72;

  return pieces.map((content) => {
    const width = content.length * 6;
    const run = { content, origin: { x, y }, size: { width, height: 14 } };

    x += width;

    return run;
  });
}

describe('normalising', () => {
  it('ignores case and whitespace, which are not evidence', () => {
    assert.equal(normalise('  Within  THIRTY\n(30) days '), 'within thirty (30) days');
    assert.equal(normalise(null), '');
  });
});

describe('runs covering a character range', () => {
  const runs = line(100, ['The Customer ', 'shall settle ', 'each invoice']);

  it('keeps the runs the match touches and no others', () => {
    const covering = rectsForRange(runs, 13, 20);

    assert.deepEqual(
      covering.map((r) => r.content),
      ['shall settle '],
    );
  });

  it('keeps every run a match spans', () => {
    const covering = rectsForRange(runs, 5, 30);

    assert.equal(covering.length, 3);
  });

  it('keeps nothing when the range touches nothing', () => {
    assert.deepEqual(rectsForRange(runs, 900, 950), []);
  });
});

describe('merging runs into lines', () => {
  it('gives one rectangle per line, not one per run', () => {
    const merged = mergeByLine([...line(100, ['a', 'b', 'c']), ...line(120, ['d', 'e'])]);

    assert.equal(merged.length, 2);
    assert.equal(merged[0].left, 72);
    assert.equal(merged[0].right, 72 + 3 * 6);
  });

  it('treats a run a pixel or two off the baseline as the same line', () => {
    const merged = mergeByLine([
      { content: 'a', origin: { x: 72, y: 100 }, size: { width: 10, height: 14 } },
      { content: 'b', origin: { x: 82, y: 102 }, size: { width: 10, height: 14 } },
    ]);

    assert.equal(merged.length, 1);
  });
});

describe('locating a snippet in a replaced document', () => {
  const pages = [
    { page: 1, size: A4, runs: line(100, ['The Customer shall settle each invoice in full']) },
    { page: 2, size: A4, runs: line(140, ['within sixty (60) days of the invoice date.']) },
    { page: 3, size: A4, runs: line(180, ['Signed for and on behalf of the Customer']) },
  ];

  it('finds the words and gives back anchors, not pixels', () => {
    const found = locate(pages, 'sixty (60) days');

    assert.equal(found.page, 2);
    assert.equal(found.unique, true);
    assert.equal(found.rects.length, 1);

    // Bottom-left origin: the run sat 140 from the top of an 842-point page.
    assert.ok(found.rects[0].y2 > 600, 'converted to user space');
    assert.ok(found.rects[0].x1 >= 0 && found.rects[0].x2 <= A4.width, 'clamped to the page');
  });

  it('says nothing rather than guessing when the clause is gone', () => {
    assert.equal(locate(pages, 'liquidated damages of ten per cent'), null);
  });

  it('refuses to match on something too short to be evidence', () => {
    assert.equal(locate(pages, 'the'), null);
    assert.equal(locate(pages, ''), null);
  });

  it('marks a match that appears twice on a page as not unique', () => {
    const repeated = [
      { page: 1, size: A4, runs: line(100, ['payment terms and payment terms again']) },
    ];

    const found = locate(repeated, 'payment terms');

    assert.equal(found.unique, false);
  });

  it('prefers a unique match over an earlier ambiguous one', () => {
    const mixed = [
      { page: 1, size: A4, runs: line(100, ['the schedule and the schedule']) },
      { page: 4, size: A4, runs: line(100, ['the schedule']) },
    ];

    const found = locate(mixed, 'the schedule');

    assert.equal(found.page, 4);
    assert.equal(found.unique, true);
  });

  it('takes the earliest page when neither is more unique than the other', () => {
    const both = [
      { page: 5, size: A4, runs: line(100, ['force majeure']) },
      { page: 2, size: A4, runs: line(100, ['force majeure']) },
    ];

    assert.equal(locate(both, 'force majeure').page, 2);
    assert.equal(locate(both, 'force majeure').occurrences, 2);
  });

  it('matches across runs split by styling', () => {
    const styled = [
      { page: 1, size: A4, runs: line(100, ['within ', 'thirty (30)', ' days']) },
    ];

    const found = locate(styled, 'within thirty (30) days');

    assert.equal(found.page, 1);
    assert.equal(found.rects.length, 1, 'three runs on one line are one rectangle');
  });

  it('finds nothing in a document with no text layer at all', () => {
    assert.equal(locate([{ page: 1, size: A4, runs: [] }], 'anything at all'), null);
  });
});
