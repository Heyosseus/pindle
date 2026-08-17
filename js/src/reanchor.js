import { clampToPage, pageToUser, roundAnchor } from './coordinates.js';

/**
 * Finding an orphan's words in the document that replaced its own.
 *
 * This is the part of Pindle that nothing else does. When a contract is
 * re-issued, every other tool leaves you with comments pointing at coordinates
 * on a page that has moved, and no way to tell which of them still mean
 * anything. Pindle knows they are orphaned; this is what lets it do something
 * about it.
 *
 * It is a search, not a repair. The result is offered to a person -- "found
 * this on page 4, move it?" -- and never applied on its own. Quietly moving a
 * reviewer's objection onto text an algorithm thought looked similar is a worse
 * failure than leaving it flagged.
 *
 * Kept free of the DOM and of the engine so it can be tested against fixed
 * text: the matching rules are the interesting part, and they should be
 * provable without a PDF.
 */

/** Whitespace and case are not evidence; collapse both before comparing. */
export function normalise(text) {
  return String(text ?? '')
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase();
}

/**
 * The rectangles covering a character range of a page's text.
 *
 * EmbedPDF reports one rectangle per run of text, each carrying its own
 * content. Walking the runs and keeping those that overlap the match is what
 * turns "characters 412 to 447" back into something drawable -- and it keeps
 * one rectangle per line, which is how a highlight is stored.
 */
export function rectsForRange(runs, start, end) {
  const covering = [];
  let cursor = 0;

  for (const run of runs) {
    const length = String(run.content ?? '').length;
    const from = cursor;
    const to = cursor + length;

    if (from < end && to > start) {
      covering.push(run);
    }

    cursor = to;
  }

  return covering;
}

/**
 * Merge the runs into one rectangle per line.
 *
 * Runs are split by font and style as much as by position, so a single line of
 * text can arrive as five of them. Storing five rectangles for one line would
 * be honest but useless; a reader sees one highlight.
 */
export function mergeByLine(runs, tolerance = 3) {
  const lines = [];

  for (const run of runs) {
    const top = run.origin.y;
    const line = lines.find((candidate) => Math.abs(candidate.top - top) <= tolerance);

    const box = {
      left: run.origin.x,
      top,
      right: run.origin.x + run.size.width,
      bottom: top + run.size.height,
    };

    if (line) {
      line.left = Math.min(line.left, box.left);
      line.top = Math.min(line.top, box.top);
      line.right = Math.max(line.right, box.right);
      line.bottom = Math.max(line.bottom, box.bottom);
    } else {
      lines.push({ ...box, top: box.top });
    }
  }

  return lines;
}

/**
 * Look for a snippet across a document's pages.
 *
 * `pages` is `[{ page, size, runs }]`, where `runs` are the engine's text
 * rectangles for that page. Returns the best candidate, or null when the words
 * are not there any more -- which is a real answer: the clause may genuinely
 * have been deleted, and saying so is better than guessing.
 */
export function locate(pages, snippet) {
  const needle = normalise(snippet);

  if (needle.length < 4) {
    // Too short to be evidence of anything. "the" appears on every page.
    return null;
  }

  const candidates = [];

  for (const { page, size, runs } of pages) {
    const text = normalise(runs.map((run) => run.content ?? '').join(''));
    const index = text.indexOf(needle);

    if (index === -1) {
      continue;
    }

    const covering = rectsForRange(runs, index, index + needle.length);

    if (covering.length === 0) {
      continue;
    }

    candidates.push({
      page,
      // A second occurrence on the same page is a weaker match than a unique
      // one: it means the words are boilerplate rather than the clause.
      unique: text.indexOf(needle, index + 1) === -1,
      rects: mergeByLine(covering).map((box) =>
        roundAnchor(clampToPage(pageToUser(box, size), size)),
      ),
    });
  }

  if (candidates.length === 0) {
    return null;
  }

  // Prefer a unique match, then the earliest page -- a re-issued contract
  // usually grows rather than reorders.
  candidates.sort((a, b) => Number(b.unique) - Number(a.unique) || a.page - b.page);

  const best = candidates[0];

  return {
    page: best.page,
    rects: best.rects,
    unique: best.unique,
    occurrences: candidates.length,
  };
}
