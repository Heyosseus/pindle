import { Viewer } from './viewer.js';

/**
 * The bundle's only job at load time: find viewers on the page and mount them.
 *
 * Mounting is idempotent and driven by a data attribute rather than by a call
 * the host has to make, so a Blade page, a Livewire re-render and a Filament
 * panel all get the same behaviour without any of them knowing about the others.
 */
const viewers = new WeakMap();

function config(node) {
  const raw = node.getAttribute('data-pindle');

  if (!raw) {
    return null;
  }

  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

export async function mount(node) {
  if (viewers.has(node)) {
    return viewers.get(node);
  }

  const settings = config(node);

  if (!settings) {
    return null;
  }

  const viewer = new Viewer(node, settings);

  viewers.set(node, viewer);

  await viewer.mount();

  return viewer;
}

export function unmount(node) {
  const viewer = viewers.get(node);

  if (viewer) {
    viewer.destroy();
    viewers.delete(node);
  }
}

/**
 * Mount everything on the page that has not been mounted already.
 *
 * Safe to call as often as you like -- which is what lets it be hung off
 * Livewire's navigation events without any bookkeeping.
 */
export function mountAll(scope = document) {
  return Promise.all([...scope.querySelectorAll('[data-pindle]')].map((node) => mount(node)));
}

function boot() {
  mountAll();

  // Livewire replaces page content wholesale on a navigation. The viewer root
  // carries wire:ignore so its own DOM survives a component re-render, but a
  // full navigation builds a new document, and the new one needs mounting.
  document.addEventListener('livewire:navigated', () => mountAll());

  // Filament and Livewire both fire this when a component's DOM settles. A
  // viewer already mounted is skipped, so this only ever catches new ones.
  document.addEventListener('livewire:initialized', () => mountAll());
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
}

const Pindle = { mount, mountAll, unmount, Viewer };

if (typeof globalThis !== 'undefined') {
  globalThis.Pindle = Pindle;
}

export { Viewer };
export default Pindle;
