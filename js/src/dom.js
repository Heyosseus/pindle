/**
 * The three DOM helpers the viewer needs, and no framework.
 *
 * The design spec suggested Alpine for local state. It is not used, and the
 * reason is worth recording: the viewer's root carries `wire:ignore` so that
 * Livewire never diffs it, and Alpine's own initialisation is driven by the
 * very DOM walking that `wire:ignore` exists to prevent. A component that has
 * to be re-initialised after every Livewire navigation is a component that
 * eventually is not, and the canvas underneath it is destroyed or duplicated.
 *
 * Plain DOM has no such lifecycle to lose track of, and it means the published
 * bundle carries no framework at all -- which serves the "no npm, no build"
 * goal better than bundling one would.
 */

/** An element with attributes and children, in one call. */
export function el(tag, attributes = {}, children = []) {
  const node = document.createElement(tag);

  for (const [name, value] of Object.entries(attributes)) {
    if (value === null || value === undefined || value === false) {
      continue;
    }

    if (name === 'class') {
      node.className = value;
    } else if (name.startsWith('on') && typeof value === 'function') {
      node.addEventListener(name.slice(2).toLowerCase(), value);
    } else if (name === 'style' && typeof value === 'object') {
      Object.assign(node.style, value);
    } else {
      node.setAttribute(name, value === true ? '' : String(value));
    }
  }

  for (const child of [].concat(children)) {
    if (child === null || child === undefined || child === false) {
      continue;
    }

    // Strings become text nodes, never markup. Comment bodies arrive from other
    // users and are stored exactly as typed; this is where "no HTML, ever" is
    // actually enforced on the client.
    node.append(typeof child === 'string' ? document.createTextNode(child) : child);
  }

  return node;
}

/** Remove every child of a node. */
export function clear(node) {
  while (node.firstChild) {
    node.firstChild.remove();
  }
}

/**
 * Dispatch a Pindle event that the host page -- or a Livewire wrapper -- can
 * listen for without knowing anything about the viewer's internals.
 */
export function emit(node, name, detail) {
  node.dispatchEvent(new CustomEvent(`pindle:${name}`, { detail, bubbles: true }));
}
