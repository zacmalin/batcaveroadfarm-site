<?php
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
/**
 * includes/img_dims.php — width/height attributes for local images.
 *
 * bcrf_img_attrs($src) returns ' width="W" height="H"' for an image stored on
 * this site, or '' if the file can't be found/read (remote URL, missing file).
 * Browsers use the pair to reserve the right space before the image loads,
 * which stops the page from jumping (Cumulative Layout Shift).
 *
 * Accepts: https://batcaveroadfarm.com/img/x.webp, /img/x.webp, img/x.webp
 * Reads only the image header (getimagesize), cached per request.
 */
if (!function_exists('bcrf_img_attrs')) {
    function bcrf_img_attrs(?string $src): string
    {
        static $cache = [];
        $src = trim((string)$src);
        if ($src === '') return '';
        if (array_key_exists($src, $cache)) return $cache[$src];

        $path = $src;
        if (preg_match('#^(?:https?:)?//([^/]+)(/.*)?$#i', $path, $m)) {
            $host = strtolower(preg_replace('/^www\./', '', $m[1]));
            if ($host !== (bcrf_farm_domain())) return $cache[$src] = '';
            $path = $m[2] ?? '/';
        }
        $path = rawurldecode(strtok($path, '?#'));
        $root = realpath(dirname(__DIR__));
        $file = $root ? realpath($root . '/' . ltrim($path, '/')) : false;

        // Must exist, be a file, and live inside the site root.
        if (!$file || !is_file($file) || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0) {
            return $cache[$src] = '';
        }
        $info = @getimagesize($file);
        if (!$info || empty($info[0]) || empty($info[1])) return $cache[$src] = '';

        return $cache[$src] = ' width="' . (int)$info[0] . '" height="' . (int)$info[1] . '"';
    }
}

/**
 * bcrf_img_thumb($src, $maxW) — a smaller WebP copy of a local photo, for spots
 * that show it small (homepage recipe and blog cards). Uploads can be 2400px
 * wide; a phone shows them about 300px wide, so the full file wastes ~400 KB.
 *
 * Returns $src unchanged when the image is remote, missing, already within
 * ~15% of $maxW, or anything goes wrong; so the worst case is today's behaviour.
 * Otherwise returns /img/_thumbs/<maxW>/<same path>.webp?v=<mtime>, creating it
 * with GD on first request and again whenever the original is replaced.
 * (The Media Vault skips /img/_thumbs/; see admin/data/loader.php.)
 */
if (!function_exists('bcrf_img_thumb')) {
    function bcrf_img_thumb(?string $src, int $maxW = 640): string
    {
        $src = trim((string)$src);
        if ($src === '' || $maxW < 32) return $src;
        try {
            $path = $src; $prefix = '';
            if (preg_match('#^((?:https?:)?//([^/]+))(/.*)?$#i', $path, $m)) {
                $host = strtolower(preg_replace('/^www\./', '', $m[2]));
                if ($host !== bcrf_farm_domain()) return $src;
                $prefix = $m[1];
                $path   = $m[3] ?? '/';
            }
            $path = rawurldecode((string)strtok($path, '?#'));
            $root = realpath(dirname(__DIR__));
            $file = $root ? realpath($root . '/' . ltrim($path, '/')) : false;
            if (!$file || !is_file($file) || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0) return $src;

            $rel = str_replace('\\', '/', substr($file, strlen($root)));          // /img/recipies/x.webp
            if (!str_starts_with($rel, '/img/') || str_starts_with($rel, '/img/_thumbs/')) return $src;

            $info = @getimagesize($file);
            if (!$info || empty($info[0]) || empty($info[1])) return $src;
            if ($info[0] <= (int)ceil($maxW * 1.15)) return $src;                   // already small enough

            $thumbRel = '/img/_thumbs/' . $maxW . preg_replace('#\.[A-Za-z0-9]+$#', '', substr($rel, 4)) . '.webp';
            $thumbAbs = $root . $thumbRel;

            if (!is_file($thumbAbs) || filemtime($thumbAbs) < filemtime($file)) {
                if (!function_exists('imagewebp')) return $src;
                $im = match ($info[2]) {
                    IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
                    IMAGETYPE_PNG  => @imagecreatefrompng($file),
                    IMAGETYPE_WEBP => @imagecreatefromwebp($file),
                    IMAGETYPE_GIF  => @imagecreatefromgif($file),
                    default        => false,
                };
                if (!$im) return $src;
                $w = $maxW;
                $h = max(1, (int)round($info[1] * $w / $info[0]));
                $canvas = imagecreatetruecolor($w, $h);
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                imagecopyresampled($canvas, $im, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
                $dir = dirname($thumbAbs);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return $src;
                $tmp = $thumbAbs . '.' . getmypid() . '.tmp';                       // write then rename: no half-written files served
                if (!@imagewebp($canvas, $tmp, 80) || !@rename($tmp, $thumbAbs)) { @unlink($tmp); return $src; }
                @touch($thumbAbs, max(time(), (int)filemtime($file)));                 // never older than its original
                clearstatcache(true, $thumbAbs);
            }
            $url = implode('/', array_map('rawurlencode', explode('/', $thumbRel)));
            return $prefix . $url . '?v=' . filemtime($thumbAbs);
        } catch (Throwable $e) {
            error_log('[BCRF] bcrf_img_thumb failed for ' . $src . ': ' . $e->getMessage());
            return $src;
        }
    }
}
