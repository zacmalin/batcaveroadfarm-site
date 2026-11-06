<?php
// includes/pinterest_image.php — branded 1000 × 1500 (2:3) Pinterest JPEGs (Nov 5, 2026).
//
//   bcrf_pin_local_photo('https://batcaveroadfarm.com/img/x.webp')  → '/home/…/img/x.webp' or null
//   bcrf_pin_render($srcFile, $dstFile, ['title' => …, 'kicker' => …, 'focus_y' => 0.4])
//       → ['ok' => bool, 'bytes' => int, 'error' => string]
//
// Template v1 (BCRF_PIN_TEMPLATE_VERSION): the farm photo fills the top 1000 × 960 (cover crop, never
// stretched; small or very wide photos are framed whole over a blurred fill instead), a Wheat Gold rule, then a Cream panel with a Barn Red script kicker, the title in Young Serif
// (shrinks to fit, at most 3 lines) and the website in Lora. Brand colours from the farm brand settings.
// The original photo is only read, never changed. Photos must be local files under the site root (no
// remote fetching, so no SSRF), JPEG/PNG/WebP/GIF, at least 400 × 200 and at most 40 megapixels. Uses GD (FreeType checked on
// System Health → Image tools). Bump the version whenever the layout changes: drafts made with an older
// version are then regenerated on the next batch.

if (!function_exists('bcrf_pin_render')) {

define('BCRF_PIN_TEMPLATE_VERSION', 1);
define('BCRF_PIN_W', 1000);
define('BCRF_PIN_H', 1500);

/** Brand fonts (includes/fonts/). */
function bcrf_pin_font(string $which): string {
    $map = ['title' => 'YoungSerif-Regular.ttf', 'body' => 'Lora-Regular.ttf', 'bold' => 'Lora-Bold.ttf', 'script' => 'NothingYouCouldDo-Regular.ttf'];
    return __DIR__ . '/fonts/' . ($map[$which] ?? $map['body']);
}

/**
 * The local file behind a photo URL or path, or null. Accepts the farm's own absolute URLs
 * (batcaveroadfarm.com / www.) and site-relative paths. Anything else (other hosts, ../ tricks) is refused.
 */
function bcrf_pin_local_photo(string $src): ?string {
    $src = trim($src);
    if ($src === '') return null;
    $root = realpath(dirname(__DIR__));
    if (!$root) return null;
    if (preg_match('#^https?://#i', $src)) {
        $host = strtolower((string)parse_url($src, PHP_URL_HOST));
        $own  = ['batcaveroadfarm.com', 'www.batcaveroadfarm.com'];
        if (function_exists('bcrf_farm_url')) $own[] = strtolower((string)parse_url(bcrf_farm_url(), PHP_URL_HOST));
        if (!in_array($host, $own, true)) return null;
        $src = (string)parse_url($src, PHP_URL_PATH);
    }
    $path = '/' . ltrim(rawurldecode((string)parse_url($src, PHP_URL_PATH)), '/');
    $file = realpath($root . $path);
    if (!$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) return null;
    if (!preg_match('/\.(jpe?g|png|webp|gif)$/i', $file)) return null;
    return $file;
}

/** Open a photo as a truecolor GD image, upright (JPEG EXIF orientation). Throws on failure. */
function bcrf_pin_open(string $file) {
    $info = @getimagesize($file);
    if (!$info) throw new RuntimeException('Not a readable image: ' . basename($file));
    [$w, $h] = $info;
    if ($w < 400 || $h < 200) throw new RuntimeException("Photo is too small ({$w} × {$h}); Pins need at least 400 × 200 px.");
    if ($w * $h > 40000000) throw new RuntimeException("Photo is too large to process ({$w} × {$h}).");
    $im = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
        IMAGETYPE_PNG  => @imagecreatefrompng($file),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
        IMAGETYPE_GIF  => @imagecreatefromgif($file),
        default        => false,
    };
    if (!$im) throw new RuntimeException('Could not open ' . basename($file) . ' (unsupported format).');
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($file)['Orientation'] ?? 1);
        $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($rot) { $r = imagerotate($im, $rot, 0); if ($r) $im = $r; }
    }
    return $im;
}

/** Word-wrap $text to $maxW px at $size; returns lines. Long single words are kept whole. */
function bcrf_pin_wrap(string $text, string $font, float $size, int $maxW): array {
    $lines = []; $cur = '';
    foreach (preg_split('/\s+/u', trim($text)) as $word) {
        $try = $cur === '' ? $word : $cur . ' ' . $word;
        $b = imagettfbbox($size, 0, $font, $try);
        if ($cur !== '' && ($b[2] - $b[0]) > $maxW) { $lines[] = $cur; $cur = $word; }
        else $cur = $try;
    }
    if ($cur !== '') $lines[] = $cur;
    return $lines;
}

/** Draw one line centred at baseline $y. */
function bcrf_pin_center($im, string $text, string $font, float $size, int $y, int $color): void {
    $b = imagettfbbox($size, 0, $font, $text);
    $x = (int)round((BCRF_PIN_W - ($b[2] - $b[0])) / 2 - $b[0]);
    imagettftext($im, $size, 0, $x, $y, $color, $font, $text);
}

/**
 * Render the Pin image. $o: title (required), kicker (small script line above the title),
 * footer (default the farm website), focus_y (0 = keep the top of the photo, 0.5 = centre; animals use
 * ~0.35 so faces aren't cut off). Writes to a temp file then renames, so a half-written JPEG never shows.
 */
function bcrf_pin_render(string $src, string $dst, array $o): array {
    $out = ['ok' => false, 'bytes' => 0, 'error' => ''];
    try {
        foreach (['title', 'body', 'bold', 'script'] as $f) {
            if (!is_file(bcrf_pin_font($f))) throw new RuntimeException('Brand font missing: includes/fonts/' . basename(bcrf_pin_font($f)));
        }
        if (!function_exists('imagettftext')) throw new RuntimeException('GD FreeType is not available (System Health → Image tools).');
        $photo = bcrf_pin_open($src);
        $pw = imagesx($photo); $ph = imagesy($photo);

        $W = BCRF_PIN_W; $H = BCRF_PIN_H; $photoH = 960;
        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);
        $cream   = imagecolorallocate($im, 0xF7, 0xF1, 0xE3);
        $espresso= imagecolorallocate($im, 0x3E, 0x2A, 0x1E);
        $barnRed = imagecolorallocate($im, 0x8A, 0x33, 0x24);
        $wheat   = imagecolorallocate($im, 0xD9, 0xA4, 0x41);
        imagefilledrectangle($im, 0, 0, $W, $H, $cream);

        // Photo area. Big enough photos are cover-cropped to W × photoH around the focus point. Small or very
        // wide photos (they'd need more than 1.6× enlargement) are shown whole in a cream-bordered frame
        // over a blurred, darkened fill of the same photo, so nothing is blown up past 2×.
        $scale = max($W / $pw, $photoH / $ph);
        $cw = (int)round($W / $scale); $ch = (int)round($photoH / $scale);
        $fy = max(0.0, min(1.0, (float)($o['focus_y'] ?? 0.5)));
        $cx = (int)round(($pw - $cw) / 2);
        $cy = (int)round(($ph - $ch) * $fy);
        if ($scale <= 1.6) {
            imagecopyresampled($im, $photo, 0, 0, max(0, $cx), max(0, $cy), $W, $photoH, min($cw, $pw), min($ch, $ph));
        } else {
            // Background: tiny copy of the crop, stretched back up (soft), blurred, then darkened
            $tiny = imagecreatetruecolor(100, 96);
            imagecopyresampled($tiny, $photo, 0, 0, max(0, $cx), max(0, $cy), 100, 96, min($cw, $pw), min($ch, $ph));
            for ($i = 0; $i < 8; $i++) imagefilter($tiny, IMG_FILTER_GAUSSIAN_BLUR);
            imagesetinterpolation($tiny, IMG_BICUBIC);
            $big = imagescale($tiny, $W, $photoH, IMG_BICUBIC) ?: $tiny;
            imagecopy($im, $big, 0, 0, 0, 0, min($W, imagesx($big)), min($photoH, imagesy($big)));
            unset($tiny, $big);
            for ($i = 0; $i < 4; $i++) imagefilter($im, IMG_FILTER_GAUSSIAN_BLUR);
            imagefilledrectangle($im, 0, 0, $W, $photoH, imagecolorallocatealpha($im, 0x3E, 0x2A, 0x1E, 70));
            // Foreground: whole photo, at most 2× and inside an 880 × 840 box, with a cream border
            $fs = min(880 / $pw, 840 / $ph, 2.0);
            $fw = (int)round($pw * $fs); $fh = (int)round($ph * $fs);
            $fx = (int)(($W - $fw) / 2); $fyy = (int)(($photoH - $fh) / 2);
            imagefilledrectangle($im, $fx - 10, $fyy - 10, $fx + $fw + 9, $fyy + $fh + 9, $cream);
            imagecopyresampled($im, $photo, $fx, $fyy, 0, 0, $fw, $fh, $pw, $ph);
        }
        unset($photo);

        // Wheat Gold rule under the photo
        imagefilledrectangle($im, 0, $photoH, $W, $photoH + 9, $wheat);

        // Kicker (script, Barn Red)
        $y = $photoH + 92;
        $kicker = trim((string)($o['kicker'] ?? ''));
        if ($kicker !== '') { bcrf_pin_center($im, $kicker, bcrf_pin_font('script'), 38, $y, $barnRed); $y += 30; }

        // Title: largest size (66 → 38) that fits in 3 lines and in the space above the footer
        $footerTop = $H - 120;
        $room  = $footerTop - 40 - $y;
        $title = trim(preg_replace('/\s+/u', ' ', (string)$o['title']));
        $tf = bcrf_pin_font('title'); $size = 66; $lines = [];
        for (; $size >= 38; $size -= 4) {
            $lines = bcrf_pin_wrap($title, $tf, $size, 860);
            if (count($lines) <= 3 && count($lines) * (int)round($size * 1.28) <= $room) break;
        }
        $size = max(38, $size);
        if (count($lines) > 3) {   // still too long at the smallest size: keep 3 lines, end with …
            $lines = array_slice($lines, 0, 3);
            $lines[2] = rtrim(mb_substr($lines[2], 0, max(1, mb_strlen($lines[2]) - 2))) . '…';
        }
        $lineH = (int)round($size * 1.28);
        $block = count($lines) * $lineH;
        $y = (int)max($y + $size, $y + (($footerTop - $y) - $block) / 2 + $size);
        foreach ($lines as $ln) { bcrf_pin_center($im, $ln, $tf, $size, $y, $espresso); $y += $lineH; }

        // Footer: short Barn Red rule + website in Lora Bold + place in Lora
        imagefilledrectangle($im, (int)($W / 2 - 60), $footerTop, (int)($W / 2 + 60), $footerTop + 3, $barnRed);
        bcrf_pin_center($im, (string)($o['footer'] ?? 'batcaveroadfarm.com'), bcrf_pin_font('bold'), 28, $footerTop + 52, $espresso);
        bcrf_pin_center($im, (string)($o['place'] ?? 'Old Fort, North Carolina'), bcrf_pin_font('body'), 22, $footerTop + 88, $espresso);

        // Save (no EXIF is written by GD, so camera metadata is dropped)
        $dir = dirname($dst);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Cannot create ' . $dir);
        $tmp = $dst . '.' . bin2hex(random_bytes(4)) . '.tmp';
        imageinterlace($im, true);   // progressive JPEG
        $ok = imagejpeg($im, $tmp, 86);
        unset($im);
        if (!$ok || !is_file($tmp) || filesize($tmp) === 0) { @unlink($tmp); throw new RuntimeException('Could not write the JPEG.'); }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $dst)) { @unlink($tmp); throw new RuntimeException('Could not save ' . basename($dst)); }
        $out['ok'] = true; $out['bytes'] = (int)filesize($dst);
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
    }
    return $out;
}

}
