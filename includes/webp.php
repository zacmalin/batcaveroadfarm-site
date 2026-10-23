<?php
// includes/webp.php — serve a smaller WebP copy of a local PNG/JPEG (fix pack M).
//
// bcrf_webp_src('/img/logo.png') returns '/img/logo.webp' when a WebP copy
// exists (or can be made right now) AND it is smaller than the original.
// Otherwise it returns null and the page keeps using the original file.
//
// The WebP is created once, on the first page view after install, using PHP's
// built-in GD library. Transparency is kept. If the server can't make WebP,
// or the folder isn't writable, nothing changes and the PNG is used.
// To regenerate (e.g. after replacing logo.png), just delete logo.webp.

if (!function_exists('bcrf_webp_src')) {
    function bcrf_webp_src(string $relPath, int $quality = 85): ?string
    {
        static $cache = [];
        if (array_key_exists($relPath, $cache)) return $cache[$relPath];

        $root = realpath(dirname(__DIR__));
        $rel  = '/' . ltrim(parse_url($relPath, PHP_URL_PATH) ?? '', '/');
        $src  = $root ? realpath($root . $rel) : false;

        // Local files under the site root only; PNG/JPEG only.
        if (!$src || !str_starts_with($src, $root . DIRECTORY_SEPARATOR) || !is_file($src)
            || !preg_match('/\.(png|jpe?g)$/i', $src)) {
            return $cache[$relPath] = null;
        }

        $dst    = preg_replace('/\.(png|jpe?g)$/i', '.webp', $src);
        $dstRel = preg_replace('/\.(png|jpe?g)$/i', '.webp', $rel);

        $fresh = is_file($dst) && filemtime($dst) >= filemtime($src);
        if (!$fresh) {
            $fresh = bcrf_webp_make($src, $dst, $quality);
        }
        // Only worth using if it actually saves bytes.
        if ($fresh && filesize($dst) > 0 && filesize($dst) < filesize($src)) {
            return $cache[$relPath] = $dstRel;
        }
        return $cache[$relPath] = null;
    }

    /** Convert $src to WebP at $dst. Returns true on success. Never throws. */
    function bcrf_webp_make(string $src, string $dst, int $quality): bool
    {
        if (!function_exists('imagewebp') || !is_writable(dirname($dst))) return false;
        try {
            $isPng = (bool)preg_match('/\.png$/i', $src);
            $im = $isPng ? @imagecreatefrompng($src) : @imagecreatefromjpeg($src);
            if (!$im) return false;
            if ($isPng) {
                if (!imageistruecolor($im)) imagepalettetotruecolor($im);
                imagealphablending($im, false);
                imagesavealpha($im, true);
            }
            // Write to a temp name first, then rename, so a visitor never gets half a file.
            $tmp = $dst . '.' . bin2hex(random_bytes(4)) . '.tmp';
            $ok  = imagewebp($im, $tmp, $quality);
            unset($im);   // (imagedestroy() is deprecated in PHP 8.5)
            if (!$ok || !is_file($tmp) || filesize($tmp) === 0) { @unlink($tmp); return false; }
            @chmod($tmp, 0644);
            if (!@rename($tmp, $dst)) { @unlink($tmp); return false; }
            // Never older than the source, even if the source has an odd future date
            // (otherwise it would be re-made on every page view).
            @touch($dst, max(time(), (int)filemtime($src)));
            return true;
        } catch (Throwable $e) {
            error_log('[webp] ' . $e->getMessage());
            return false;
        }
    }
}
