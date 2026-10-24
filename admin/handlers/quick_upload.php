<?php
// admin/handlers/quick_upload.php
// AJAX image upload for the schema form inline image widget.
// Returns JSON: {ok, url, message}

ob_start();
header('Content-Type: application/json');

function jx(bool $ok, string $msg, array $extra = []): void {
    ob_end_clean();
    echo json_encode(array_merge(['ok' => $ok, 'message' => $msg], $extra));
    exit;
}

require_once '../../config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();   // config.php usually started it already (fix pack O)
if (empty($_SESSION['user_id'])) jx(false, 'Not authenticated.', [], 401);
// Fix pack O: staff only. Public customer accounts also have user_id, so check the role.
if (!in_array($_SESSION['user_role'] ?? '', ['administrator', 'moderator', 'Shopkeep', 'Media'], true)) {
    jx(false, 'Staff only.', [], 403);
}
require_once __DIR__ . '/../includes/audit.php';   // fix pack O: audit log

$csrf = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) jx(false, 'Security token expired.');

date_default_timezone_set('America/New_York');

$subfolder = trim($_POST['subfolder'] ?? 'inventory');
$subfolder = preg_replace('/[^a-zA-Z0-9_\-]/', '', $subfolder) ?: 'inventory';

$source = null;

try {
    if (empty($_FILES['image']['tmp_name']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        jx(false, 'No file received or upload error.');
    }

    $tmp   = $_FILES['image']['tmp_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($tmp);

    $source = match($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmp),
        'image/png'  => @imagecreatefrompng($tmp),
        'image/webp' => @imagecreatefromwebp($tmp),
        'image/gif'  => @imagecreatefromgif($tmp),
        default      => null,
    };

    if (!$source) jx(false, "Unsupported image type: {$mime}");

    // Auto-resize if wider than 1200px
    $ow = imagesx($source);
    $oh = imagesy($source);
    if ($ow > 1200) {
        $nw     = 1200;
        $nh     = (int)floor($oh * ($nw / $ow));
        $canvas = imagecreatetruecolor($nw, $nh);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
        $source = $canvas;   // old image is freed automatically (imagedestroy() is deprecated in PHP 8.5)
    }

    // Save as WebP
    $dir  = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/img/' . $subfolder . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = uniqid('img_') . '.webp';
    $path     = $dir . $filename;

    if (!imagewebp($source, $path, 82)) {
        jx(false, 'Could not write image file. Check folder permissions.');
    }

    $url = '/img/' . $subfolder . '/' . $filename;
    erp_audit($pdo, 'create', 'media', null, 'Image added (quick upload): ' . $url);
    jx(true, 'Uploaded.', ['url' => $url, 'full_url' => 'https://batcaveroadfarm.com' . $url]);

} catch (Throwable $e) {
    error_log('[ERP] quick_upload failed: ' . $e->getMessage());
    jx(false, 'Upload error: ' . $e->getMessage());
} finally {
    if (isset($source) && (is_resource($source) || $source instanceof GdImage)) {
        $source = null;   // frees the image (imagedestroy() is deprecated in PHP 8.5)
    }
}
