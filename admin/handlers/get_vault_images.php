<?php
// admin/handlers/get_vault_images.php
// Returns vault image list as JSON for the inline schema image picker modal.

ob_start();
header('Content-Type: application/json');

function jx(bool $ok, string $msg, array $extra = []): void {
    ob_end_clean();
    echo json_encode(array_merge(['ok' => $ok, 'message' => $msg], $extra));
    exit;
}

require_once '../../config.php';
// Staff only — public customer accounts also have user_id, so check the role.
if (empty($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['administrator', 'moderator', 'Shopkeep', 'Media'], true)) jx(false, 'Not authenticated.');

$folder_filter = trim($_GET['folder'] ?? '');
$site_base     = 'https://batcaveroadfarm.com';
$img_root      = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/img/';
$exts          = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
$images        = [];
$cap           = 200;

// ── SESSION CACHE: bust with ?bust=1 or when a new image is processed ────────
$cache_key = 'vault_images_' . md5($folder_filter);
$cache_ttl = 120; // 2 minutes

if (empty($_GET['bust']) && !empty($_SESSION[$cache_key]['ts']) &&
    (time() - $_SESSION[$cache_key]['ts']) < $cache_ttl) {
    jx(true, 'OK (cached)', $_SESSION[$cache_key]['data']);
}

if (!is_dir($img_root)) jx(false, 'Image directory not found.');

try {
    $dirs = $folder_filter
        ? [$img_root . trim($folder_filter, '/') . '/']
        : array_merge([$img_root], glob($img_root . '*/', GLOB_ONLYDIR) ?: []);

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) continue;
        $folder_name = ($dir === $img_root) ? '_root' : basename(rtrim($dir, '/'));
        foreach (scandir($dir) ?: [] as $file) {
            if ($file[0] === '.') continue;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, $exts)) continue;
            $full = $dir . $file;
            $rel  = '/img/' . ($folder_name === '_root' ? '' : $folder_name . '/') . $file;
            $images[] = [
                'url'        => $rel,
                'full_url'   => $site_base . $rel,
                'folder'     => $folder_name,
                'filename'   => $file,
                'size'       => filesize($full),
            ];
            if (count($images) >= $cap) break 2;
        }
    }

    // Sort newest first by filename (uniqid-based names sort naturally)
    usort($images, fn($a, $b) => strcmp($b['filename'], $a['filename']));

    // Collect unique folders for filter tabs
    $folders = array_values(array_unique(array_column($images, 'folder')));

    jx(true, 'OK', ['images' => $images, 'folders' => $folders, 'capped' => count($images) >= $cap]);

} catch (Throwable $e) {
    error_log('[ERP] get_vault_images failed: ' . $e->getMessage());
    jx(false, 'Could not scan image vault.');
}
