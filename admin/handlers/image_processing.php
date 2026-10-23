<?php
// admin/handlers/image_processing.php
// Handles GD image upload, resize, and format conversion.

$source = null; // declared early so finally block can always call imagedestroy safely

try {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['toast_msg']  = 'Security token expired. Please try again.';
        $_SESSION['toast_type'] = 'error';
        header('Location: /admin/admin.php?view=media');
        exit;
    }

    // Fix 4: sanitize output filename — alphanumeric, hyphens, underscores only
    $raw_name      = trim($_POST['output_filename_override'] ?? '');
    $clean_name    = preg_replace('/[^a-zA-Z0-9_\-]/', '_', basename($raw_name));

    $target_dir    = $_POST['upload_subdirectory_target'] ?? '/';
    $target_format = $_POST['format_conversion_mode']     ?? 'webp';
    $compression_quality = filter_input(INPUT_POST, 'compression_quality_scale', FILTER_VALIDATE_INT) ?: 80;
    $resize_width        = filter_input(INPUT_POST, 'resize_dimension_width',    FILTER_VALIDATE_INT);

    // Clamp quality to valid range
    $compression_quality = max(10, min(100, $compression_quality));

    // Whitelist format — reject anything unexpected
    if (!in_array($target_format, ['webp', 'jpg', 'png'])) {
        throw new RuntimeException("Invalid output format requested.");
    }

    $base_storage_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/img/' . trim($target_dir, '/');
    if (!is_dir($base_storage_root)) {
        mkdir($base_storage_root, 0755, true);
    }

    // ── SOURCE: UPLOADED FILE ─────────────────────────────────────────────────
    if (!empty($_FILES['image_source_file']['tmp_name']) &&
         $_FILES['image_source_file']['error'] === UPLOAD_ERR_OK) {

        $tmp = $_FILES['image_source_file']['tmp_name'];

        // Fix 2: detect real MIME from file contents, not client-supplied extension
        $finfo     = new finfo(FILEINFO_MIME_TYPE);
        $real_mime = $finfo->file($tmp);

        $source = match($real_mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmp),
            'image/png'  => @imagecreatefrompng($tmp),
            'image/webp' => @imagecreatefromwebp($tmp),
            'image/gif'  => @imagecreatefromgif($tmp),
            default      => throw new RuntimeException("Unsupported image type: {$real_mime}"),
        };

    }
    // ── SOURCE: REMOTE URL ────────────────────────────────────────────────────
    elseif (!empty($_POST['image_source_url'])) {

        $remote_url = trim($_POST['image_source_url']);

        // Fix 3: only allow https:// — block file://, http://, data:, etc.
        if (!preg_match('#^https://#i', $remote_url)) {
            throw new RuntimeException("Remote URL must use HTTPS.");
        }

        // Fix 3: stream context with 8MB byte limit and 10s timeout
        $ctx = stream_context_create([
            'http' => [
                'timeout'          => 10,
                'follow_location'  => 1,
                'max_redirects'    => 3,
            ],
            'https' => [
                'timeout'          => 10,
                'follow_location'  => 1,
                'max_redirects'    => 3,
            ],
        ]);

        // Read up to 8MB — anything larger is almost certainly not a web image
        $url_content = @file_get_contents($remote_url, false, $ctx, 0, 8 * 1024 * 1024);

        if (!$url_content) {
            throw new RuntimeException("Could not fetch remote image.");
        }

        // Validate the fetched bytes are actually an image
        $finfo     = new finfo(FILEINFO_MIME_TYPE);
        $real_mime = $finfo->buffer($url_content);

        if (!in_array($real_mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])) {
            throw new RuntimeException("Remote URL did not return a valid image ({$real_mime}).");
        }

        $source = @imagecreatefromstring($url_content);
        unset($url_content); // free memory immediately
    }

    if (!$source) {
        throw new RuntimeException("No image source provided or image could not be decoded.");
    }

    // ── RESIZE ────────────────────────────────────────────────────────────────
    $orig_w = imagesx($source);
    $orig_h = imagesy($source);

    if ($resize_width && $resize_width > 0 && $resize_width < $orig_w) {
        $new_w  = $resize_width;
        $new_h  = (int)floor($orig_h * ($new_w / $orig_w));
        $canvas = imagecreatetruecolor($new_w, $new_h);

        if (in_array($target_format, ['png', 'webp'])) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
        }

        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);
        imagedestroy($source);
        $source = $canvas;
    }

    // ── WRITE ─────────────────────────────────────────────────────────────────
    $output_filename = ($clean_name ?: uniqid('farm_asset_')) . '.' . $target_format;
    $absolute_path   = rtrim($base_storage_root, '/') . '/' . $output_filename;

    $written = match($target_format) {
        'jpg'  => imagejpeg($source, $absolute_path, $compression_quality),
        'png'  => imagepng($source,  $absolute_path, (int)floor((100 - $compression_quality) / 10)),
        'webp' => imagewebp($source, $absolute_path, $compression_quality),
    };

    if (!$written) {
        throw new RuntimeException("GD write failed: {$absolute_path}");
    }

    $_SESSION['toast_msg']  = "Image saved: /{$output_filename}";
    // Bust vault image cache
    foreach (array_keys($_SESSION) as $_sk) { if (str_starts_with($_sk, 'vault_images_')) unset($_SESSION[$_sk]); }
    $_SESSION['toast_type'] = 'success';

} catch (Throwable $e) {
    error_log('[ERP] Image processing failed: ' . $e->getMessage());
    $_SESSION['toast_msg']  = "Image pipeline error: " . $e->getMessage();
    $_SESSION['toast_type'] = 'error';
} finally {
    // Fix 5: always free the GD resource, even if an exception was thrown
    if (isset($source) && is_resource($source)) {
        imagedestroy($source);
    }
}

header("Location: /admin/admin.php?view=media");
exit;
