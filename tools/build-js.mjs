// tools/build-js.mjs — builds js/min.site.js (fix pack M).
//
// Combines the 7 scripts every public page loads into ONE file, so phones make
// 1 request instead of 7. Run it after editing any of the source files below:
//
//     npm i --no-save terser      (once)
//     node tools/build-js.mjs
//
// Then bump BCRF_ASSET_V in includes/head.php and upload js/min.site.js.
//
// The individual files stay on the server (send-message.php still uses
// min.gtag.js + min.analytics.js directly). This folder (tools/) is not
// needed on the web server.
//
// Order matters and matches the old <script defer> order:
//   head:   gtag, nav      footer: app, hours, subscribe, analytics, theme
// Parts marked `isolate` are wrapped in try/catch so an error in one feature
// (say, the open-hours badge) can't stop the rest of the file from running,
// the same way separate files behaved. gtag and app define global functions
// (gtag, toggleNav, closeNav, showPage, initReveal) so they are NOT wrapped.

import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const parts = [
  { file: 'js/min.gtag.js',      isolate: false },
  { file: 'js/min.nav.js',       isolate: true  },
  { file: 'js/min.app.js',       isolate: false },
  { file: 'js/min.hours.js',     isolate: true  },
  { file: 'js/min.subscribe.js', isolate: true  },
  { file: 'js/min.analytics.js', isolate: true  },
  { file: 'js/min.theme.js',     isolate: true  },
];

let src = '';
for (const p of parts) {
  const code = readFileSync(join(root, p.file), 'utf8').trim();
  src += `\n/* ---- ${p.file} ---- */\n`;
  src += p.isolate
    ? `try{\n${code}\n}catch(e){if(window.console)console.error('[${p.file}]',e);}\n`
    : `${code}\n;\n`;
}

let out = src;
try {
  const { minify } = await import('terser');
  const r = await minify(src, {
    ecma: 2017,
    toplevel: false,          // keep global names (gtag, toggleNav, ...)
    compress: { passes: 2 },
    mangle: true,
    format: { comments: false, preamble: '/* min.site.js - built by tools/build-js.mjs from ' + parts.map(p => p.file.replace('js/', '')).join(', ') + ' */' },
  });
  out = r.code + '\n';
} catch (e) {
  console.warn('terser not available, writing unminified bundle:', e.message);
}

writeFileSync(join(root, 'js/min.site.js'), out);
console.log(`js/min.site.js: ${out.length} bytes (sources: ${src.length} bytes)`);
