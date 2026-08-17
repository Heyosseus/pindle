import { anchorToViewport } from './coordinates.js';
import { clear, el } from './dom.js';

/**
 * The layer of marks drawn over one rendered page.
 *
 * It is a pure function of (annotations, page size, rotation, scale): given the
 * same four it produces the same layer, which is what makes redrawing after a
 * zoom, a rotation, or a Livewire navigation a matter of calling it again
 * rather than of patching whatever is currently on screen.
 *
 * Nothing here is ever persisted. Every number it computes is a CSS pixel for
 * this exact moment; the anchors it reads are points in PDF user space and stay
 * that way.
 */
export function drawOverlay(layer, annotations, page, rotation, scale, handlers = {}) {
  clear(layer);

  for (const annotation of annotations) {
    for (const [index, anchor] of (annotation.rects || []).entries()) {
      layer.append(mark(annotation, anchor, index, page, rotation, scale, handlers));
    }
  }

  return layer;
}

function mark(annotation, anchor, index, page, rotation, scale, handlers) {
  const box = anchorToViewport(anchor, page, rotation, scale);

  const classes = ['pindle-mark', `pindle-mark--${annotation.type}`];

  if (annotation.orphaned) {
    classes.push('pindle-mark--orphaned');
  }

  if (annotation.resolved_at) {
    classes.push('pindle-mark--resolved');
  }

  if (handlers.selectedId === annotation.id) {
    classes.push('pindle-mark--selected');
  }

  const node = el('button', {
    type: 'button',
    class: classes.join(' '),
    'data-pindle-annotation': annotation.id,
    // Only the first rectangle of a multi-line highlight is reachable by
    // keyboard: three tab stops for one highlight is noise, not access.
    tabindex: index === 0 ? '0' : '-1',
    'aria-label': label(annotation),
    style: {
      left: `${box.left}px`,
      top: `${box.top}px`,
      width: `${Math.max(box.right - box.left, 2)}px`,
      height: `${Math.max(box.bottom - box.top, 2)}px`,
      ...(annotation.color && !annotation.orphaned ? { backgroundColor: annotation.color } : {}),
    },
    onclick: (event) => {
      event.preventDefault();
      handlers.onSelect?.(annotation.id);
    },
  });

  // The warning badge rides the first rectangle only, so a three-line orphaned
  // highlight carries one warning rather than three.
  if (annotation.orphaned && index === 0) {
    node.append(
      el('span', {
        class: 'pindle-mark__warning',
        title: 'The document has been replaced since this was written. It may no longer point at the right place.',
        'aria-hidden': 'true',
      }, '!'),
    );
  }

  return node;
}

function label(annotation) {
  const parts = [annotation.type];

  if (annotation.orphaned) {
    parts.push('on a document that has since been replaced');
  }

  if (annotation.resolved_at) {
    parts.push('resolved');
  }

  const comments = (annotation.comments || []).length;

  if (comments > 0) {
    parts.push(`${comments} comment${comments === 1 ? '' : 's'}`);
  }

  return parts.join(', ');
}
