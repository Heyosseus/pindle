import { createPdfiumEngine } from '@embedpdf/engines/pdfium-direct-engine';

import { Api } from './api.js';
import { clampToPage, quarterTurns, roundAnchor, textRectsToAnchors, viewportSize, viewportToAnchor } from './coordinates.js';
import { clear, el, emit } from './dom.js';
import { drawOverlay } from './overlay.js';
import { locate } from './reanchor.js';
import { Store } from './store.js';
import { drawThread } from './thread.js';

const ZOOM_STEPS = [0.5, 0.75, 1, 1.25, 1.5, 2, 3, 4];

/**
 * One mounted viewer: an EmbedPDF engine, the pages it rendered, the marks over
 * them, and the thread beside them.
 *
 * The document is opened by URL in range-request mode, so PDFium fetches the
 * trailer and then only the objects the visible page needs. That is the whole
 * reason the streaming controller supports ranges: without it, opening a
 * hundred-page contract downloads a hundred pages before showing the first.
 */
export class Viewer {
  constructor(root, config) {
    this.root = root;
    this.config = config;
    this.store = new Store();
    this.api = new Api(config);

    this.scale = Number(config.scale) || 1;
    this.rotation = 0;
    this.pages = [];
    this.engine = null;
    this.document = null;
    this.destroyed = false;
    this.pageNodes = new Map();
  }

  async mount() {
    this.root.classList.add('pindle');
    this.root.setAttribute('data-pindle-mounted', 'true');

    this.buildChrome();

    try {
      await Promise.all([this.load(), this.open()]);
    } catch (error) {
      this.fail(error);

      return this;
    }

    this.store.subscribe(() => this.redrawAnnotations());
    this.renderPages();
    this.redrawAnnotations();
    this.bindKeys();

    emit(this.root, 'ready', { pages: this.pages.length });

    return this;
  }

  /** The annotations, from the API, before anything is drawn. */
  async load() {
    this.store.replaceAll(await this.api.list());
  }

  /** The document itself, through PDFium. */
  async open() {
    this.engine = await createPdfiumEngine(this.config.wasmUrl);

    this.document = await this.engine
      .openDocumentUrl(
        { id: `${this.config.annotatableType}:${this.config.annotatableId}:${this.config.documentKey}`, url: this.config.documentUrl },
        { mode: 'range-request', requestOptions: { credentials: 'same-origin' } },
      )
      .toPromise();

    this.pages = this.document.pages || [];
  }

  buildChrome() {
    clear(this.root);

    this.pagesNode = el('div', { class: 'pindle__pages' });
    this.threadNode = el('aside', { class: 'pindle-thread', 'aria-label': 'Comments' });

    this.root.append(
      this.buildToolbar(),
      el('div', { class: 'pindle__body' }, [
        el('div', { class: 'pindle__scroll' }, this.pagesNode),
        this.threadNode,
      ]),
    );
  }

  buildToolbar() {
    const zoomOut = el('button', { type: 'button', class: 'pindle__tool', 'aria-label': 'Zoom out', onclick: () => this.zoom(-1) }, '−');
    const zoomIn = el('button', { type: 'button', class: 'pindle__tool', 'aria-label': 'Zoom in', onclick: () => this.zoom(1) }, '+');
    const rotate = el('button', { type: 'button', class: 'pindle__tool', 'aria-label': 'Rotate', onclick: () => this.rotate() }, '↻');

    this.zoomLabel = el('span', { class: 'pindle__zoom' }, `${Math.round(this.scale * 100)}%`);

    this.highlightButton = this.config.readonly
      ? null
      : el('button', {
          type: 'button',
          class: 'pindle__tool pindle__tool--primary',
          onclick: () => this.highlightSelection(),
        }, 'Keep selection');

    return el('div', { class: 'pindle__toolbar' }, [
      zoomOut,
      this.zoomLabel,
      zoomIn,
      rotate,
      this.highlightButton,
      el('span', { class: 'pindle__spacer' }),
      (this.statusNode = el('span', { class: 'pindle__status', role: 'status' })),
    ]);
  }

  zoom(direction) {
    const index = ZOOM_STEPS.findIndex((step) => step >= this.scale);
    const next = ZOOM_STEPS[Math.min(Math.max((index < 0 ? ZOOM_STEPS.length - 1 : index) + direction, 0), ZOOM_STEPS.length - 1)];

    if (next === this.scale) {
      return;
    }

    this.scale = next;
    this.zoomLabel.textContent = `${Math.round(this.scale * 100)}%`;

    this.renderPages();
    this.redrawAnnotations();
  }

  rotate() {
    this.rotation = quarterTurns(this.rotation + 1);

    this.renderPages();
    this.redrawAnnotations();
  }

  /**
   * Lay out one node per page at the current zoom and rotation, and ask the
   * engine to paint each.
   *
   * The nodes are sized from the page's own dimensions before any bitmap
   * arrives, so the scroll position does not jump as pages fill in.
   */
  renderPages() {
    clear(this.pagesNode);
    this.pageNodes.clear();

    for (const page of this.pages) {
      const size = viewportSize(page.size, this.rotation, this.scale);

      const canvas = el('img', { class: 'pindle-page__canvas', alt: '', draggable: 'false' });
      const layer = el('div', { class: 'pindle-page__layer' });

      const node = el('div', {
        class: 'pindle-page',
        'data-pindle-page': String(page.index + 1),
        style: { width: `${size.width}px`, height: `${size.height}px` },
      }, [canvas, layer]);

      this.pagesNode.append(node);
      this.pageNodes.set(page.index + 1, { node, canvas, layer, page });

      if (!this.config.readonly) {
        this.bindSelection(node, page);
      }

      this.paint(page, canvas);
    }
  }

  async paint(page, canvas) {
    try {
      const blob = await this.engine
        .renderPage(this.document, page, {
          scaleFactor: this.scale,
          rotation: this.rotation,
          dpr: globalThis.devicePixelRatio || 1,
        })
        .toPromise();

      if (this.destroyed) {
        return;
      }

      const url = URL.createObjectURL(blob);

      // Revoked once the browser has decoded it; holding every page's bitmap
      // URL for the life of the viewer is how a long document exhausts memory.
      canvas.addEventListener('load', () => URL.revokeObjectURL(url), { once: true });
      canvas.src = url;
    } catch (error) {
      this.status(`Page ${page.index + 1} could not be drawn.`);
      emit(this.root, 'error', { error });
    }
  }

  redrawAnnotations() {
    for (const [number, { layer, page }] of this.pageNodes) {
      drawOverlay(layer, this.store.onPage(number), page.size, this.rotation, this.scale, {
        selectedId: this.store.selectedId,
        onSelect: (id) => this.store.select(id),
      });
    }

    const selected = this.store.get(this.store.selectedId);

    drawThread(this.threadNode, selected, {
      readonly: this.config.readonly,
      maxLength: this.config.maxCommentLength,
      suggestion: this.suggestion?.id === selected?.id ? this.suggestion : null,
      onResolve: (id, resolved) => this.resolve(id, resolved),
      onDelete: (id) => this.destroyAnnotation(id),
      onComment: (id, body, parentId) => this.comment(id, body, parentId),
      onDeleteComment: (id, commentId) => this.deleteComment(id, commentId),
      onFind: (id) => this.findOrphan(id),
      onAccept: () => this.acceptSuggestion(),
      onDismiss: () => {
        this.suggestion = null;
        this.redrawAnnotations();
      },
    });

    const orphans = this.store.orphans().length;

    this.status(orphans === 0
      ? ''
      : `${orphans} annotation${orphans === 1 ? '' : 's'} may no longer point at the right place.`);
  }

  /**
   * Keep whatever the reader dragged over, together with the words underneath it.
   *
   * The page on screen is a bitmap, so the browser has no text to select and no
   * idea where the words are; the snippet comes from PDFium's own text
   * rectangles, matched against the area that was dragged. It is never used for
   * drawing -- it exists so that an annotation orphaned by a re-issued document
   * could one day be re-found by its words.
   */
  async highlightSelection() {
    const selection = this.currentSelection();

    if (!selection) {
      this.status('Drag across the page to choose an area first.');

      return;
    }

    await this.create({
      page: selection.page,
      // A dragged box is one rectangle over a place on the page, which is what
      // `area` means. A `highlight` is per-line and needs a text selection the
      // bitmap cannot give.
      type: 'area',
      rects: selection.rects,
      text_snippet: await this.snippet(selection),
    });
  }

  /**
   * The words inside a selection, or null when they cannot be read.
   *
   * Best-effort by design: a scanned page has no text layer, and an annotation
   * on one is perfectly valid with no snippet at all.
   */
  async snippet(selection) {
    const entry = this.pageNodes.get(selection.page);
    const anchor = selection.rects[0];

    if (!entry || !anchor) {
      return null;
    }

    try {
      const rects = await this.engine.getPageTextRects(this.document, entry.page).toPromise();

      const words = rects
        .filter((rect) => overlaps(textRectsToAnchors([rect], entry.page.size)[0], anchor))
        .map((rect) => rect.content)
        .join('')
        .trim();

      return words === '' ? null : words.slice(0, 2000);
    } catch {
      return null;
    }
  }

  /**
   * The area the reader dragged, as anchors on one page.
   *
   * Dragging over a bitmap is an area selection, not a text selection, so what
   * is stored is the box that was dragged. `text_snippet` is filled from the
   * engine's text rectangles where they overlap it, which is what would let a
   * future version re-find an orphan.
   */
  currentSelection() {
    return this.pendingSelection || null;
  }

  /**
   * Let the reader drag a box over a page, and remember it as anchors.
   *
   * The drag is tracked in CSS pixels relative to the page node, and converted
   * to user-space points the moment it ends -- so what is remembered is already
   * independent of the zoom and rotation it was drawn at, and stays correct if
   * either changes before it is saved.
   */
  bindSelection(node, page) {
    let start = null;
    let marquee = null;

    const at = (event) => {
      const bounds = node.getBoundingClientRect();

      return { x: event.clientX - bounds.left, y: event.clientY - bounds.top };
    };

    node.addEventListener('pointerdown', (event) => {
      // Clicking an existing mark selects it; only bare page starts a drag.
      if (event.button !== 0 || event.target.closest('.pindle-mark')) {
        return;
      }

      start = at(event);
      marquee = el('div', { class: 'pindle-page__marquee' });
      node.append(marquee);
      node.setPointerCapture(event.pointerId);
    });

    node.addEventListener('pointermove', (event) => {
      if (!start) {
        return;
      }

      const now = at(event);

      Object.assign(marquee.style, {
        left: `${Math.min(start.x, now.x)}px`,
        top: `${Math.min(start.y, now.y)}px`,
        width: `${Math.abs(now.x - start.x)}px`,
        height: `${Math.abs(now.y - start.y)}px`,
      });
    });

    const finish = (event) => {
      if (!start) {
        return;
      }

      const now = at(event);
      const box = {
        left: Math.min(start.x, now.x),
        top: Math.min(start.y, now.y),
        right: Math.max(start.x, now.x),
        bottom: Math.max(start.y, now.y),
      };

      marquee?.remove();
      marquee = null;
      start = null;

      // A click is not a drag. Anything smaller than a few points is somebody
      // dismissing the thread panel, not drawing a box.
      if (box.right - box.left < 4 || box.bottom - box.top < 4) {
        this.store.select(null);

        return;
      }

      this.pendingSelection = {
        page: page.index + 1,
        rects: selectionFrom(box, page.size, this.rotation, this.scale),
      };

      this.status('Press "Highlight selection" to keep this.');
    };

    node.addEventListener('pointerup', finish);
    node.addEventListener('pointercancel', finish);
  }

  async create(annotation) {
    try {
      const created = await this.api.create(annotation);

      this.store.put(created);
      this.store.select(created.id);
      this.pendingSelection = null;

      emit(this.root, 'annotation-created', created);
    } catch (error) {
      this.fail(error);
    }
  }

  /**
   * Move between the marks that still want attention, from the keyboard.
   *
   * A reviewer working through a forty-page contract should not be hunting for
   * the next open comment with a scrollbar. `n` and `p` walk the open marks in
   * page order, `r` settles the one in hand, and Escape puts the thread away.
   *
   * Bound to the viewer's own root rather than the document, so a page carrying
   * two viewers, or a viewer beside a search box, never steals a keystroke that
   * was meant for something else.
   */
  bindKeys() {
    this.root.tabIndex = this.root.tabIndex < 0 ? 0 : this.root.tabIndex;

    this.root.addEventListener('keydown', (event) => {
      if (event.metaKey || event.ctrlKey || event.altKey) {
        return;
      }

      const typing = event.target instanceof HTMLElement
        && ['INPUT', 'TEXTAREA'].includes(event.target.tagName);

      if (typing) {
        return;
      }

      const handlers = {
        n: () => this.step(1),
        p: () => this.step(-1),
        j: () => this.step(1),
        k: () => this.step(-1),
        r: () => this.toggleResolved(),
        Escape: () => this.store.select(null),
      };

      const handler = handlers[event.key];

      if (handler) {
        event.preventDefault();
        handler();
      }
    });
  }

  /** The next mark wanting attention, in the order a reader meets them. */
  step(direction) {
    const open = this.store
      .all()
      .filter((annotation) => !annotation.resolved_at || annotation.orphaned)
      .sort((a, b) => a.page - b.page || a.created_at.localeCompare(b.created_at));

    if (open.length === 0) {
      this.status('Nothing is open on this document.');

      return;
    }

    const at = open.findIndex((annotation) => annotation.id === this.store.selectedId);
    const next = open[(((at < 0 ? -1 : at) + direction) % open.length + open.length) % open.length];

    this.store.select(next.id);
    this.scrollTo(next);
  }

  scrollTo(annotation) {
    const mark = this.root.querySelector(`[data-pindle-annotation="${annotation.id}"]`);

    mark?.scrollIntoView({ block: 'center', behavior: 'smooth' });
  }

  toggleResolved() {
    const annotation = this.store.get(this.store.selectedId);

    if (annotation && !this.config.readonly) {
      this.resolve(annotation.id, !annotation.resolved_at);
    }
  }

  /**
   * Look for an orphan's words in the document that replaced its own.
   *
   * Offered, never applied: the result goes into the thread panel as a
   * suggestion with the page it was found on, and a person decides. Moving a
   * reviewer's objection onto text an algorithm thought looked similar would be
   * a worse failure than leaving it flagged.
   */
  async findOrphan(id) {
    const annotation = this.store.get(id);

    if (!annotation?.text_snippet) {
      this.status('This mark recorded no text, so there is nothing to search for.');

      return;
    }

    this.status('Looking for those words in this version…');

    try {
      const pages = [];

      for (const page of this.pages) {
        const runs = await this.engine.getPageTextRects(this.document, page).toPromise();

        pages.push({ page: page.index + 1, size: page.size, runs });
      }

      const found = locate(pages, annotation.text_snippet);

      if (!found) {
        this.suggestion = null;
        this.status('Those words are not in this version of the document.');
        this.redrawAnnotations();

        return;
      }

      this.suggestion = { id, ...found };
      this.status('');
      this.redrawAnnotations();
    } catch (error) {
      this.fail(error);
    }
  }

  async acceptSuggestion() {
    const suggestion = this.suggestion;

    if (!suggestion) {
      return;
    }

    try {
      const moved = await this.api.reanchor(suggestion.id, suggestion.page, suggestion.rects);

      this.suggestion = null;
      this.store.put(moved);

      emit(this.root, 'annotation-reanchored', moved);
    } catch (error) {
      this.fail(error);
    }
  }

  async resolve(id, resolved) {
    try {
      const updated = await this.api.resolve(id, resolved);

      this.store.put(updated);

      emit(this.root, resolved ? 'annotation-resolved' : 'annotation-reopened', updated);
    } catch (error) {
      this.fail(error);
    }
  }

  async destroyAnnotation(id) {
    try {
      await this.api.destroy(id);

      this.store.remove(id);

      emit(this.root, 'annotation-deleted', { id });
    } catch (error) {
      this.fail(error);
    }
  }

  async comment(id, body, parentId) {
    try {
      const comment = await this.api.comment(id, body, parentId);

      this.store.addComment(id, comment);

      emit(this.root, 'comment-posted', comment);
    } catch (error) {
      this.fail(error);
    }
  }

  async deleteComment(id, commentId) {
    try {
      await this.api.deleteComment(commentId);

      this.store.removeComment(id, commentId);

      emit(this.root, 'comment-deleted', { id: commentId });
    } catch (error) {
      this.fail(error);
    }
  }

  status(message) {
    if (this.statusNode) {
      this.statusNode.textContent = message;
    }
  }

  fail(error) {
    this.status(error?.message || 'Something went wrong.');
    emit(this.root, 'error', { error });
  }

  /**
   * Tear the viewer down.
   *
   * Called before a Livewire navigation replaces the page, so that PDFium's
   * WASM heap is released rather than left behind with the detached DOM.
   */
  destroy() {
    this.destroyed = true;

    try {
      this.engine?.destroy?.();
    } catch {
      // A destroyed engine that will not confirm it is destroyed is not worth
      // an exception on the way out of a page.
    }

    this.root.removeAttribute('data-pindle-mounted');
    clear(this.root);
  }
}

/**
 * Track a drag over a page as an area selection.
 *
 * Exported and attached separately from the Viewer so that the geometry it
 * produces is testable without a canvas: it takes CSS pixel positions and gives
 * back anchors, and the conversion is the same one everything else uses.
 */
export function selectionFrom(box, page, rotation, scale) {
  return [roundAnchor(clampToPage(viewportToAnchor(box, page, rotation, scale), page))];
}

/** Whether two anchors share any area at all. */
export function overlaps(a, b) {
  return a.x1 < b.x2 && a.x2 > b.x1 && a.y1 < b.y2 && a.y2 > b.y1;
}

export { textRectsToAnchors };
