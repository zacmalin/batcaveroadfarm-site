<?php
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
            if ($host !== 'batcaveroadfarm.com') return $cache[$src] = '';
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
