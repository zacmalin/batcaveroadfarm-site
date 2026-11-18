<?php
// includes/brand.php — the farm's logos and tab icon, uploaded in Site Info (Nov 18, 2026).
//
// Site Info → Logos & icon takes three pictures: the main logo (website header, emails, gift cards),
// the round logo (search results, Pinterest seal, printed labels) and the icon (browser tab, phone
// home screen). Each upload is turned into every size and format the site needs, using PHP's GD:
//   main logo  → logo-800/400/300.webp (website), logo-400.png + logo-200.png (emails and older browsers)
//   round logo → round-512.png/.webp (search, social, Pinterest), round-1024.png (printed labels)
//   icon       → favicon.ico (16+32+48), icon-32.png, icon-180.png (phone home screen), icon-192/512.png (app manifest)
// Files go in /img/brand/<kind>/<version>/ (the code makes the folders) with the original kept as source.*.
// site_settings 'brand_assets' (JSON) says which version is in use. Older versions stay on disk, so
// "Undo last upload" steps back one version and "Reset to the original files" goes back to the files below.
// With nothing uploaded every helper returns this install's current files, so nothing changes until a farm
// uploads its own (resale: no logo is written into the code).

if (!function_exists('bcrf_brand_url')) {

if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/farm.php';

/** The three uploads, with what each is for (shown in Site Info) and the smallest picture accepted. */
function bcrf_brand_kinds(): array {
    return [
        'logo'  => ['label' => 'Main logo',  'min' => 150, 'square' => false,
                    'help'  => 'Website header, email header and gift cards. A wide or square PNG with a see-through background works best, at least 400 px wide.'],
        'round' => ['label' => 'Round logo', 'min' => 192, 'square' => true,
                    'help'  => 'Google search results, the Pinterest pin seal and printed labels. A square picture of the round badge, at least 512 px (1024 px prints sharpest).'],
        'icon'  => ['label' => 'Icon',       'min' => 48,  'square' => true,
                    'help'  => 'The browser tab and the phone home screen. A simple square mark that still reads when tiny, at least 512 px.'],
    ];
}

/** Install's own files, used until something is uploaded. */
function bcrf_brand_fallbacks(): array {
    return [
        'logo_webp'   => '/img/logo.webp',
        'logo_png'    => '/img/logo.png',
        'email_logo'  => '/img/logo.png',
        'round_png'   => '/img/logo.png',
        'print_logo'  => '/img/logos/print_square_logo.png',
        'favicon'     => '/img/favicon.ico',
        'icon_svg'    => '/img/icon.svg',
        'apple_touch' => '/img/icon.png',
        'icon_192'    => '/img/icon.png',
        'icon_512'    => '/img/icon.png',
    ];
}

/** Which kind and file each use comes from once uploaded. */
function bcrf_brand_uses(): array {
    return [
        'logo_webp'   => ['logo', 'logo-400.webp'],
        'logo_webp_300' => ['logo', 'logo-300.webp'],
        'logo_webp_800' => ['logo', 'logo-800.webp'],
        'logo_png'    => ['logo', 'logo-400.png'],
        'email_logo'  => ['logo', 'logo-200.png'],
        'round_png'   => ['round', 'round-512.png'],
        'round_webp'  => ['round', 'round-512.webp'],
        'print_logo'  => ['round', 'round-1024.png'],
        'favicon'     => ['icon', 'favicon.ico'],
        'icon_32'     => ['icon', 'icon-32.png'],
        'apple_touch' => ['icon', 'icon-180.png'],
        'icon_192'    => ['icon', 'icon-192.png'],
        'icon_512'    => ['icon', 'icon-512.png'],
    ];
}

/** Site root on disk (tests point it elsewhere with $GLOBALS['bcrf_brand_root']). */
function bcrf_brand_root(): string {
    return rtrim((string)($GLOBALS['bcrf_brand_root'] ?? dirname(__DIR__)), '/');
}

/** Saved state: ['logo' => ['cur' => version|'' , 'hist' => [[v, w, h, name, at, by], ...newest first]], ...]. */
function bcrf_brand_state(bool $fresh = false): array {
    static $state = null;
    if ($state !== null && !$fresh) return $state;
    $state = [];
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO) {
        try {
            $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'brand_assets'");
            $st->execute();
            $j = json_decode((string)$st->fetchColumn(), true);
            if (is_array($j)) $state = $j;
        } catch (Throwable $e) { /* no settings table yet: original files */ }
    }
    foreach (array_keys(bcrf_brand_kinds()) as $k) {
        $s = is_array($state[$k] ?? null) ? $state[$k] : [];
        $state[$k] = ['cur' => (string)($s['cur'] ?? ''), 'hist' => array_values(array_filter((array)($s['hist'] ?? []), 'is_array'))];
    }
    return $state;
}

/** Folder (site-relative) of one uploaded version. */
function bcrf_brand_dir(string $kind, string $ver): string {
    return '/img/brand/' . $kind . '/' . $ver;
}

/** The version in use for a kind, or '' when the original files are in use (or its folder has gone). */
function bcrf_brand_current(string $kind): string {
    $ver = bcrf_brand_state()[$kind]['cur'] ?? '';
    if ($ver === '' || !preg_match('/^\d{14}-[a-f0-9]{6}$/', $ver)) return '';
    return is_dir(bcrf_brand_root() . bcrf_brand_dir($kind, $ver)) ? $ver : '';
}

/** Saved details (w, h, name, at, by) of the version in use, or []. */
function bcrf_brand_meta(string $kind): array {
    $ver = bcrf_brand_current($kind);
    foreach (bcrf_brand_state()[$kind]['hist'] ?? [] as $h) if (($h['v'] ?? '') === $ver && $ver !== '') return $h;
    return [];
}

function bcrf_brand_has(string $kind): bool { return bcrf_brand_current($kind) !== ''; }

/**
 * Site-relative path (or full URL with $absolute) for one use: logo_webp, logo_webp_300, logo_webp_800, logo_png,
 * email_logo, round_png, round_webp, print_logo, schema_logo, favicon, icon_32, icon_svg, apple_touch, icon_192, icon_512.
 * Uploaded files live in a folder named for their version, so a new upload is a new address (no stale caches).
 * Returns '' only for a use with no file at all (icon_svg once an icon is uploaded, logo_webp_* without an upload).
 */
function bcrf_brand_url(string $use, bool $absolute = false): string {
    $path = '';
    if ($use === 'schema_logo') {   // Google's organisation logo: the round logo, else the main logo
        $path = bcrf_brand_has('round') ? bcrf_brand_url('round_png') : bcrf_brand_url('logo_png');
        return $absolute ? bcrf_farm_url($path) : $path;
    }
    $map = bcrf_brand_uses();
    if (isset($map[$use])) {
        [$kind, $file] = $map[$use];
        $ver = bcrf_brand_current($kind);
        if ($ver !== '') {
            $rel = bcrf_brand_dir($kind, $ver) . '/' . $file;
            if (is_file(bcrf_brand_root() . $rel)) $path = $rel;
            elseif (str_ends_with($file, '.webp')) {   // server couldn't write WebP: the PNG of the same picture
                $png = bcrf_brand_dir($kind, $ver) . '/' . ($kind === 'round' ? 'round-512.png' : 'logo-400.png');
                if (is_file(bcrf_brand_root() . $png)) $path = $png;
            }
        }
    }
    if ($use === 'icon_svg' && bcrf_brand_has('icon')) return '';   // the uploaded icon replaces the old SVG one
    if ($path === '') $path = bcrf_brand_fallbacks()[$use] ?? '';
    if ($path === '') return '';
    return $absolute ? bcrf_farm_url($path) : $path;
}

/** Full file path on disk for one use (Pinterest seal, printed labels), or '' when the file is missing. */
function bcrf_brand_file(string $use): string {
    $rel = bcrf_brand_url($use);
    $abs = $rel !== '' ? bcrf_brand_root() . $rel : '';
    return ($abs !== '' && is_file($abs)) ? $abs : '';
}

/** Width and height of the header logo (400 px copy). */
function bcrf_brand_logo_size(): array {
    $m = bcrf_brand_meta('logo');
    if (!empty($m['w']) && !empty($m['h'])) {
        $w = min(400, (int)$m['w']);
        return [$w, max(1, (int)round($m['h'] * $w / $m['w']))];
    }
    return [400, 299];   // the original /img/logo.png
}

/** The tab-icon link tags for any page head. $base: '' for same-site paths, or bcrf_farm_url() for full URLs. */
function bcrf_brand_icon_links(string $base = ''): string {
    $e = fn(string $u) => htmlspecialchars($base . $u, ENT_QUOTES, 'UTF-8');
    $out = '<link rel="icon" href="' . $e(bcrf_brand_url('favicon')) . '" sizes="any">';
    if (bcrf_brand_has('icon')) $out .= '<link rel="icon" href="' . $e(bcrf_brand_url('icon_32')) . '" type="image/png" sizes="32x32">';
    elseif (($svg = bcrf_brand_url('icon_svg')) !== '') $out .= '<link rel="icon" href="' . $e($svg) . '" type="image/svg+xml">';
    return $out . '<link rel="apple-touch-icon" href="' . $e(bcrf_brand_url('apple_touch')) . '">';
}

/** Icons for the web app manifest. */
function bcrf_brand_manifest_icons(): array {
    if (bcrf_brand_has('icon')) {
        return [
            ['src' => bcrf_brand_url('icon_192'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => bcrf_brand_url('icon_512'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ];
    }
    $f = bcrf_brand_url('icon_512');
    $dim = @getimagesize(bcrf_brand_root() . $f);
    return [['src' => $f, 'sizes' => $dim ? $dim[0] . 'x' . $dim[1] : 'any', 'type' => 'image/png', 'purpose' => 'any']];
}

/** Can this server make the files? ['ok' => bool, 'webp' => bool, 'problem' => string] */
function bcrf_brand_tools(): array {
    $gd = function_exists('imagecreatetruecolor') && function_exists('imagepng');
    $webp = $gd && function_exists('imagewebp');
    return ['ok' => $gd, 'webp' => $webp, 'problem' => $gd ? '' : 'This server has no image tools (PHP GD), so logos can\'t be converted here.'];
}

// ── Making the files ──────────────────────────────────────────────────────────────────────────────

/** Load an uploaded picture as a true-colour GD image with transparency kept. Throws a plain-words message. */
function bcrf_brand_load(string $file): GdImage {
    $info = @getimagesize($file);
    if (!$info || empty($info[0]) || empty($info[1])) throw new RuntimeException('That file isn\'t a picture this can read. Use a PNG, JPEG or WebP.');
    if ($info[0] * $info[1] > 24000000) throw new RuntimeException('That picture is too big (' . $info[0] . ' × ' . $info[1] . ' px). Use one under 4000 px on its longest side.');
    $im = match ($info[2]) {
        IMAGETYPE_PNG  => @imagecreatefrompng($file),
        IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
        IMAGETYPE_GIF  => @imagecreatefromgif($file),
        default        => false,
    };
    if (!$im) throw new RuntimeException('That file isn\'t a picture this can read. Use a PNG, JPEG or WebP.');
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    return $im;
}

/** A blank see-through canvas (or filled with $bg = [r, g, b]). */
function bcrf_brand_canvas(int $w, int $h, ?array $bg = null): GdImage {
    $c = imagecreatetruecolor($w, $h);
    imagealphablending($c, false);
    imagesavealpha($c, true);
    $fill = $bg ? imagecolorallocate($c, $bg[0], $bg[1], $bg[2]) : imagecolorallocatealpha($c, 0, 0, 0, 127);
    imagefilledrectangle($c, 0, 0, $w - 1, $h - 1, $fill);
    return $c;
}

/** The picture centred on a square see-through canvas (nothing cropped). */
function bcrf_brand_square(GdImage $im): GdImage {
    $w = imagesx($im); $h = imagesy($im);
    if ($w === $h) return $im;
    $s = max($w, $h);
    $c = bcrf_brand_canvas($s, $s);
    imagecopy($c, $im, intdiv($s - $w, 2), intdiv($s - $h, 2), 0, 0, $w, $h);
    return $c;
}

/** Resized copy that fits in $maxW × $maxH (never made bigger unless $grow), optionally on a solid background. */
function bcrf_brand_fit(GdImage $im, int $maxW, int $maxH, bool $grow = false, ?array $bg = null): GdImage {
    $w = imagesx($im); $h = imagesy($im);
    $scale = min($maxW / $w, $maxH / $h);
    if (!$grow) $scale = min(1.0, $scale);
    $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
    $c = bcrf_brand_canvas($nw, $nh, $bg);
    if ($bg) imagealphablending($c, true);   // blend the logo onto the solid colour
    imagecopyresampled($c, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagealphablending($c, false);
    imagesavealpha($c, true);
    return $c;
}

/** PNG bytes of an image. */
function bcrf_brand_png_bytes(GdImage $im): string {
    ob_start(); imagepng($im, null, 9); return (string)ob_get_clean();
}

/** A .ico holding PNG pictures (every current browser reads these). $pngs: [size => png bytes]. */
function bcrf_brand_ico(array $pngs): string {
    ksort($pngs);
    $head = pack('vvv', 0, 1, count($pngs));
    $dir = ''; $data = '';
    $offset = 6 + 16 * count($pngs);
    foreach ($pngs as $size => $png) {
        $dir .= pack('CCCCvvVV', $size >= 256 ? 0 : $size, $size >= 256 ? 0 : $size, 0, 0, 1, 32, strlen($png), $offset + strlen($data));
        $data .= $png;
    }
    return $head . $dir . $data;
}

/** Write a file in one go (temp name, then rename). Throws on failure. */
function bcrf_brand_put(string $path, string $bytes): void {
    $tmp = $path . '.' . bin2hex(random_bytes(3)) . '.tmp';
    if (@file_put_contents($tmp, $bytes) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not save the files in /img/brand/. Check the folder can be written to.');
    }
    @chmod($path, 0644);
}

/** Save a GD image as PNG (always) and/or WebP (when the server can). */
function bcrf_brand_write(GdImage $im, string $dir, string $name, bool $png = true, bool $webp = false): void {
    if ($png) bcrf_brand_put($dir . '/' . $name . '.png', bcrf_brand_png_bytes($im));
    if ($webp && function_exists('imagewebp')) {
        ob_start(); $ok = imagewebp($im, null, 85); $b = (string)ob_get_clean();
        if ($ok && $b !== '') bcrf_brand_put($dir . '/' . $name . '.webp', $b);
    }
}

/**
 * Make every file for one kind from a picture on disk, into a new version folder.
 * Returns ['v' => version, 'w' => width, 'h' => height] of the picture as used (square kinds after padding).
 */
function bcrf_brand_generate(string $kind, string $srcFile): array {
    $kinds = bcrf_brand_kinds();
    if (!isset($kinds[$kind])) throw new RuntimeException('Unknown logo type.');
    $tools = bcrf_brand_tools();
    if (!$tools['ok']) throw new RuntimeException($tools['problem']);

    $im = bcrf_brand_load($srcFile);
    if ($kinds[$kind]['square']) $im = bcrf_brand_square($im);
    $w = imagesx($im); $h = imagesy($im);
    $min = (int)$kinds[$kind]['min'];
    if (max($w, $h) < $min) throw new RuntimeException('That picture is only ' . $w . ' × ' . $h . ' px. The ' . strtolower($kinds[$kind]['label']) . ' needs at least ' . $min . ' px.');

    $ver = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
    $dir = bcrf_brand_root() . bcrf_brand_dir($kind, $ver);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Could not make the folder /img/brand/. Check /img can be written to.');

    try {
        // The original upload, kept as it came (handy for remaking files later)
        $ext = match (@getimagesize($srcFile)[2] ?? 0) { IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif', default => 'img' };
        if (!@copy($srcFile, $dir . '/source.' . $ext)) throw new RuntimeException('Could not save the files in /img/brand/. Check the folder can be written to.');

        if ($kind === 'logo') {
            foreach ([800, 300] as $mw) bcrf_brand_write(bcrf_brand_fit($im, $mw, $mw * 2), $dir, 'logo-' . $mw, false, true);
            bcrf_brand_write(bcrf_brand_fit($im, 400, 800), $dir, 'logo-400', true, true);
            bcrf_brand_write(bcrf_brand_fit($im, 200, 400), $dir, 'logo-200', true, false);
        } elseif ($kind === 'round') {
            bcrf_brand_write(bcrf_brand_fit($im, 512, 512, true), $dir, 'round-512', true, true);
            bcrf_brand_write(bcrf_brand_fit($im, 1024, 1024), $dir, 'round-1024', true, false);
        } else {
            $pngs = [];
            foreach ([16, 32, 48] as $s) $pngs[$s] = bcrf_brand_png_bytes(bcrf_brand_fit($im, $s, $s, true));
            bcrf_brand_put($dir . '/favicon.ico', bcrf_brand_ico($pngs));
            bcrf_brand_put($dir . '/icon-32.png', $pngs[32]);
            // Phones paint see-through corners black on the home screen, so this one sits on white
            $touch = bcrf_brand_canvas(180, 180, [255, 255, 255]);
            imagealphablending($touch, true);
            $inner = bcrf_brand_fit($im, 160, 160, true);
            imagecopy($touch, $inner, 10, 10, 0, 0, imagesx($inner), imagesy($inner));
            bcrf_brand_write($touch, $dir, 'icon-180');
            bcrf_brand_write(bcrf_brand_fit($im, 192, 192, true), $dir, 'icon-192');
            bcrf_brand_write(bcrf_brand_fit($im, 512, 512, true), $dir, 'icon-512');
        }
    } catch (Throwable $e) {
        foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);   // only the files just made
        @rmdir($dir);
        throw $e;
    }
    return ['v' => $ver, 'w' => $w, 'h' => $h];
}

/** Save the state JSON. */
function bcrf_brand_save_state(PDO $pdo, array $state): void {
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('brand_assets', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    bcrf_brand_state(true);
}

/** Upload: make the files and put the new version in use. Returns the new version. */
function bcrf_brand_upload(PDO $pdo, string $kind, string $srcFile, string $origName, string $by): string {
    $made = bcrf_brand_generate($kind, $srcFile);
    $state = bcrf_brand_state(true);
    array_unshift($state[$kind]['hist'], [
        'v' => $made['v'], 'w' => $made['w'], 'h' => $made['h'],
        'name' => mb_substr(basename($origName), 0, 120), 'at' => gmdate('Y-m-d H:i:s'), 'by' => mb_substr($by, 0, 60),
    ]);
    $state[$kind]['hist'] = array_slice($state[$kind]['hist'], 0, 20);   // the list keeps 20; older folders just stay on disk
    $state[$kind]['cur'] = $made['v'];
    bcrf_brand_save_state($pdo, $state);
    return $made['v'];
}

/** Undo: go back one upload (or to the original files when there's nothing older). Returns the version now in use ('' = original). */
function bcrf_brand_undo(PDO $pdo, string $kind): string {
    $state = bcrf_brand_state(true);
    $cur = $state[$kind]['cur'];
    if ($cur === '') return '';
    $next = '';
    $seen = false;
    foreach ($state[$kind]['hist'] as $h) {
        if ($seen && is_dir(bcrf_brand_root() . bcrf_brand_dir($kind, (string)$h['v']))) { $next = (string)$h['v']; break; }
        if (($h['v'] ?? '') === $cur) $seen = true;
    }
    $state[$kind]['cur'] = $next;
    bcrf_brand_save_state($pdo, $state);
    return $next;
}

/** Back to this install's original files (uploads stay in the list and on disk). */
function bcrf_brand_reset(PDO $pdo, string $kind): void {
    $state = bcrf_brand_state(true);
    $state[$kind]['cur'] = '';
    bcrf_brand_save_state($pdo, $state);
}

}
