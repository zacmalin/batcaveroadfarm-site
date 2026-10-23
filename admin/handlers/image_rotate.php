<?php
// admin/handlers/image_rotate.php
// Rotate an existing vault image 90° (or 180°) with GD and save it back in place.

$src = null;
$rot = null;
try {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['toast_msg']  = 'Security token expired. Please try again.';
        $_SESSION['toast_type'] = 'error';
        header('Location: /admin/admin.php?view=media');
        exit;
    }

    // Accept the vault path (root-relative like /img/zoo/goat.webp). Tolerate a full URL.
    $rel = trim($_POST['rotate_image_path'] ?? '');
    $rel = parse_url($rel, PHP_URL_PATH) ?: $rel;
    $rel = '/' . ltrim($rel, '/');
    if (!preg_match('#^/img/#', $rel)) {
        throw new RuntimeException('Image path must be inside /img/.');
    }

    // Resolve to an absolute path and confirm it stays inside DOCUMENT_ROOT/img (no traversal).
    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $abs     = realpath($docroot . $rel);
    $imgroot = realpath($docroot . '/img');
    if ($abs === false || $imgroot === false || strpos($abs, $imgroot . DIRECTORY_SEPARATOR) !== 0) {
        throw new RuntimeException('Image not found or outside the image folder.');
    }
    if (!is_writable($abs)) {
        throw new RuntimeException('File is not writable: ' . basename($abs));
    }

    // Decode by real MIME, not extension.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($abs);
    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($abs),
        'image/png'  => @imagecreatefrompng($abs),
        'image/webp' => @imagecreatefromwebp($abs),
        'image/gif'  => @imagecreatefromgif($abs),
        default      => throw new RuntimeException("Unsupported image type: {$mime}"),
    };
    if (!$src) {
        throw new RuntimeException('Could not decode image.');
    }

    // GD rotates counter-clockwise for positive angles.
    $dir   = $_POST['rotate_direction'] ?? 'right';
    $angle = match ($dir) {
        'left'  => 90,
        'right' => -90,
        '180'   => 180,
        default => -90,
    };

    $transparent = imagecolorallocatealpha($src, 0, 0, 0, 127);
    $rot = imagerotate($src, $angle, $transparent);
    if (!$rot) {
        throw new RuntimeException('Rotation failed.');
    }
    if (in_array($mime, ['image/png', 'image/webp'], true)) {
        imagealphablending($rot, false);
        imagesavealpha($rot, true);
    }

    $written = match ($mime) {
        'image/jpeg' => imagejpeg($rot, $abs, 90),
        'image/png'  => imagepng($rot, $abs),
        'image/webp' => imagewebp($rot, $abs, 90),
        'image/gif'  => imagegif($rot, $abs),
    };
    if (!$written) {
        throw new RuntimeException('GD write failed.');
    }

    // Bust the vault image cache so the re-scan + cache-bust param pick up the change.
    foreach (array_keys($_SESSION) as $_sk) {
        if (str_starts_with($_sk, 'vault_images_')) unset($_SESSION[$_sk]);
    }
    $_SESSION['toast_msg']  = 'Rotated: ' . basename($abs);
    $_SESSION['toast_type'] = 'success';

} catch (Throwable $e) {
    error_log('[ERP] Image rotate failed: ' . $e->getMessage());
    $_SESSION['toast_msg']  = 'Rotate error: ' . $e->getMessage();
    $_SESSION['toast_type'] = 'error';
} finally {
    if ($src instanceof \GdImage) imagedestroy($src);
    if ($rot instanceof \GdImage) imagedestroy($rot);
}

$ret = !empty($_POST['rotate_return_folder']) ? '&folder=' . urlencode($_POST['rotate_return_folder']) : '';
header('Location: /admin/admin.php?view=media' . $ret);
exit;
