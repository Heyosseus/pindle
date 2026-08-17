/**
 * Pindle's build. Maintainers only.
 *
 * An application that installs Pindle never runs this and never installs npm.
 * The output is committed to the repository and published to
 * `public/vendor/pindle` by `vendor:publish`, which is the whole reason a
 * five-minute install is possible at all: a package that required the host to
 * configure Vite would be a package most Filament users never finish setting up.
 *
 * Three artefacts come out of it:
 *
 *   pindle.js    the viewer, with EmbedPDF's runtime bundled in
 *   pindle.css   the styles
 *   pdfium.wasm  PDFium itself, copied rather than inlined -- it is 4.5 MB, and
 *                base64 in a script tag would cost a third again in size and
 *                make it uncacheable separately from the code
 */

import { copyFile, mkdir, stat } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import * as esbuild from 'esbuild';

const root = dirname(fileURLToPath(import.meta.url));
const outdir = join(root, 'resources', 'dist');

const options = {
  entryPoints: [
    { in: join(root, 'js', 'src', 'index.js'), out: 'pindle' },
    { in: join(root, 'js', 'src', 'pindle.css'), out: 'pindle' },
  ],
  outdir,
  bundle: true,
  format: 'iife',
  globalName: 'PindleBundle',
  platform: 'browser',
  target: ['es2022', 'chrome111', 'firefox115', 'safari16'],
  minify: true,
  sourcemap: false,
  legalComments: 'none',
  logLevel: 'info',
  // PDFium is fetched at runtime from the published asset directory, so the
  // wasm never enters the JS bundle.
  external: ['*.wasm'],
  loader: { '.wasm': 'file' },
  banner: {
    js: '/*! Pindle viewer -- built from js/src, do not edit. See CONTRIBUTING.md. */',
  },
};

async function copyPdfium() {
  const source = join(root, 'node_modules', '@embedpdf', 'pdfium', 'dist', 'pdfium.wasm');
  const target = join(outdir, 'pdfium.wasm');

  await mkdir(outdir, { recursive: true });
  await copyFile(source, target);

  const { size } = await stat(target);

  console.log(`pdfium.wasm  ${(size / 1024 / 1024).toFixed(1)}mb`);
}

if (process.argv.includes('--watch')) {
  const context = await esbuild.context(options);

  await copyPdfium();
  await context.watch();

  console.log('watching js/src');
} else {
  await esbuild.build(options);
  await copyPdfium();
}
