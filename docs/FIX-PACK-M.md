# Fix Pack M: Speed + Automatic Code Check (2026-10-23)

5 files for the server. No database changes. Builds on Packs K and L.

## What it does

| Change | Result |
|---|---|
| New `js/min.site.js` | The 7 small scripts every page loaded (gtag, nav, app, hours, subscribe, analytics, theme) are now 1 file: 7 requests become 1, and 11.6 KB becomes 8.6 KB (about 3 KB compressed). Each feature is isolated, so an error in one (like the open-hours badge) can't stop the others. |
| `includes/head.php`, `includes/footer.php` | Load the one combined file instead of the 7. The asset version is now `20261023m`, so browsers fetch the new file right away. |
| New `includes/webp.php` + `includes/header.php` | On the first page view after install, the site makes `img/logo.webp` from your `logo.png` (transparency kept). Browsers that support WebP (nearly all) get the smaller file. The WebP is only used if it's actually smaller; otherwise the PNG stays. If you ever replace `logo.png`, a new WebP is made automatically. |
| GitHub code check (already active, nothing to upload) | Every push to GitHub runs a check: a PHP 8.5 syntax check of every PHP file, a JavaScript syntax check, and a check that `min.site.js` is up to date. A broken file shows a red X and GitHub emails you. |

The old individual script files stay on the server. `send-message.php` still uses two of them, and nothing else breaks if a cached page asks for them.

## Install

1. Optional safety copy: in File Manager, compress `includes/head.php`, `includes/footer.php` and `includes/header.php` into `before-pack-m.zip`.
2. Upload `fix-pack-M-speed-5-files-2026-10-23.zip` to `public_html/batcaveroadfarm/`, choose Extract, and allow it to overwrite. Then delete the zip.

## Test (2 minutes, on your phone)

1. Open the homepage. Tap the menu button: the menu opens. Tap "Join our mailing list": the popup opens.
2. The "Open now / Closed" badge in the header shows the right status.
3. In File Manager, `img/` now contains `logo.webp` (made on that first page view). If it isn't there, the server couldn't make a smaller one, and the PNG is still used. That's fine.
4. Optional: in Cloudflare > Caching, choose Purge Everything, so the new file shows everywhere at once.

## If you edit a script later

Edit the source file (for example `js/min.hours.js`), then rebuild the combined file with `node tools/build-js.mjs` (or ask me). Upload `js/min.site.js` and bump `BCRF_ASSET_V` in `includes/head.php`. The GitHub check flags it if you forget to rebuild.

## Undo

Extract `before-pack-m.zip` over the site. The 7 old script files are still there, so it all works as before. You can leave `min.site.js`, `webp.php` and `logo.webp` in place.

## Tested

- Real Chromium (phone size), old and new versions side by side on the homepage, booking and contact pages. Same results: `gtag` and the dataLayer, global nav functions, the menu opening, the subscribe popup opening and closing with Escape, the hours badge, page-specific scripts still loading after the combined file, the logo at the same 107x80 size, and no new console errors. Script requests went from 7 to 1. Screenshots identical apart from the logo pixels.
- WebP helper: makes the file and keeps transparency; uses the PNG when the WebP isn't smaller; returns nothing for missing files, paths outside the site, or non-images; falls back cleanly when `img/` isn't writable; doesn't remake the file on later page views; no PHP 8.5 warnings or deprecations.
- GitHub check: passes on this code. On a throwaway branch with a deliberately broken PHP file it failed and pointed to the file and line (branch deleted afterwards).
