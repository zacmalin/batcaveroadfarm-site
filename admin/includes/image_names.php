<?php
// admin/includes/image_names.php
// Shared filename rules for admin image uploads: the quick-upload widget
// (handlers/quick_upload.php) and the Media Vault pipeline
// (handlers/image_processing.php) both use these so the two paths match.

if (!function_exists('bcrf_image_basename')) {

    /**
     * Turn whatever staff typed into a safe base name, without extension.
     * Same character rule as the pipeline always had: anything other than
     * letters, numbers, hyphens and underscores becomes "_". Returns '' when
     * nothing usable is left, which callers treat as "use the automatic name".
     */
    function bcrf_image_basename(string $raw): string
    {
        $raw  = str_replace('\\', '/', trim($raw));
        $raw  = basename($raw);                                        // no folders, no traversal
        $raw  = preg_replace('/\.(webp|jpe?g|png|gif|heic|avif)$/i', '', $raw);   // a typed extension is dropped
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $raw);
        $name = preg_replace('/_{2,}/', '_', $name);
        $name = trim($name, '_-');
        return substr($name, 0, 80);
    }

    /**
     * First free file name in $dir: "<base>.<ext>", then "<base>-2.<ext>",
     * "<base>-3.<ext>" and so on, so an upload never silently overwrites
     * another image.
     */
    function bcrf_image_free_name(string $dir, string $base, string $ext): string
    {
        $dir  = rtrim($dir, '/') . '/';
        $cand = $base;
        for ($n = 2; is_file($dir . $cand . '.' . $ext); $n++) {
            $cand = $base . '-' . $n;
            if ($n > 999) { $cand = $base . '-' . uniqid(); break; }
        }
        return $cand . '.' . $ext;
    }

    /** The Media Vault picker caches its file list in the session; clear it after a new file lands. */
    function bcrf_image_bust_vault_cache(): void
    {
        foreach (array_keys($_SESSION ?? []) as $k) {
            if (str_starts_with((string)$k, 'vault_images_')) unset($_SESSION[$k]);
        }
    }
}
