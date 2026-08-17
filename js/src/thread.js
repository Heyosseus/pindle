import { clear, el } from './dom.js';
import { thread } from './store.js';

/**
 * The margin beside the page: what has been said about the selected mark, and
 * the box to say something back.
 *
 * Threading is one level deep here as it is everywhere else -- a reply gets a
 * reply box that answers its root, not itself. Bodies are put on the page as
 * text nodes and never as markup; see `el` for why that is enforced at the DOM
 * layer rather than by escaping strings.
 */
export function drawThread(panel, annotation, options = {}) {
  clear(panel);

  if (!annotation) {
    panel.append(
      el('p', { class: 'pindle-thread__empty' }, 'Select a mark to read or add comments.'),
    );

    return panel;
  }

  panel.append(header(annotation, options));

  if (annotation.orphaned) {
    panel.append(orphanNotice(annotation, options));
  }

  const list = el('ol', { class: 'pindle-thread__list' });

  for (const root of thread(annotation.comments)) {
    list.append(comment(root, annotation, options));
  }

  panel.append(list);
  panel.append(composer(annotation, null, options, 'Add a comment'));

  return panel;
}

/**
 * What an orphan says for itself, and what can be done about it.
 *
 * The offer to go looking is the point. Every other tool leaves a reviewer with
 * a comment pointing at coordinates on a page that moved and no way to tell
 * whether it still means anything; this one can go and find the words. It
 * offers, and a person decides -- moving somebody's objection automatically,
 * onto text that merely looked similar, would be a worse failure than leaving
 * it flagged.
 */
function orphanNotice(annotation, options) {
  const notice = el('div', { class: 'pindle-thread__warning' }, [
    el('p', { class: 'pindle-thread__warning-text' },
      'The document has been replaced since this was written, so it may no longer point at the right place.'),
  ]);

  if (options.readonly) {
    return notice;
  }

  const suggestion = options.suggestion;

  if (!suggestion) {
    notice.append(
      el('button', {
        type: 'button',
        class: 'pindle-thread__find',
        onclick: () => options.onFind?.(annotation.id),
      }, annotation.text_snippet ? 'Find these words in this version' : 'No text was recorded to search for'),
    );

    return notice;
  }

  notice.append(
    el('p', { class: 'pindle-thread__found' },
      suggestion.unique
        ? `Found on page ${suggestion.page}.`
        : `Found on page ${suggestion.page}, but the same words appear elsewhere too.`),
    el('div', { class: 'pindle-thread__actions' }, [
      el('button', {
        type: 'button',
        class: 'pindle-thread__submit',
        onclick: () => options.onAccept?.(),
      }, 'Move it there'),
      el('button', {
        type: 'button',
        class: 'pindle-thread__remove',
        onclick: () => options.onDismiss?.(),
      }, 'Leave it'),
    ]),
  );

  return notice;
}

function header(annotation, options) {
  const resolved = Boolean(annotation.resolved_at);

  return el('div', { class: 'pindle-thread__header' }, [
    el('span', { class: 'pindle-thread__page' }, `Page ${annotation.page}`),
    options.readonly
      ? null
      : el('button', {
          type: 'button',
          class: 'pindle-thread__resolve',
          'aria-pressed': resolved ? 'true' : 'false',
          onclick: () => options.onResolve?.(annotation.id, !resolved),
        }, resolved ? 'Reopen' : 'Resolve'),
    options.readonly
      ? null
      : el('button', {
          type: 'button',
          class: 'pindle-thread__delete',
          onclick: () => options.onDelete?.(annotation.id),
        }, 'Delete'),
  ]);
}

function comment(root, annotation, options) {
  const replies = el('ol', { class: 'pindle-thread__replies' },
    root.replies.map((reply) => body(reply, annotation, options)));

  return el('li', { class: 'pindle-thread__item' }, [
    body(root, annotation, options),
    replies,
    options.readonly ? null : composer(annotation, root.id, options, 'Reply'),
  ]);
}

function body(entry, annotation, options) {
  return el('div', { class: 'pindle-thread__body' }, [
    el('p', { class: 'pindle-thread__text' }, entry.body),
    el('div', { class: 'pindle-thread__meta' }, [
      el('time', { datetime: entry.created_at || '' }, formatted(entry.created_at)),
      options.readonly
        ? null
        : el('button', {
            type: 'button',
            class: 'pindle-thread__remove',
            'aria-label': 'Withdraw this comment',
            onclick: () => options.onDeleteComment?.(annotation.id, entry.id),
          }, 'Withdraw'),
    ]),
  ]);
}

function composer(annotation, parentId, options, action) {
  const input = el('textarea', {
    class: 'pindle-thread__input',
    rows: parentId ? '2' : '3',
    maxlength: String(options.maxLength || 2000),
    placeholder: action,
    'aria-label': action,
  });

  const submit = () => {
    const value = input.value.trim();

    if (value === '') {
      return;
    }

    input.value = '';
    options.onComment?.(annotation.id, value, parentId);
  };

  return el('form', {
    class: 'pindle-thread__composer',
    onsubmit: (event) => {
      event.preventDefault();
      submit();
    },
  }, [
    input,
    el('button', { type: 'submit', class: 'pindle-thread__submit' }, action),
  ]);
}

function formatted(iso) {
  if (!iso) {
    return '';
  }

  const date = new Date(iso);

  return Number.isNaN(date.getTime()) ? '' : date.toLocaleString();
}
