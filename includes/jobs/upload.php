<?php
// includes/jobs/upload.php
// Résumé uploads: PDF only, 5 MB max, saved OUTSIDE the website folder with a
// random name and no extension, so a file can never be opened or run by URL.
// Live path: /home2/ddailnmy/bcrf-uploads/jobs  (override with env BCRF_UPLOAD_DIR).

if (!function_exists('jobs_upload_dir')) {

    if (!defined('JOBS_UPLOAD_MAX')) define('JOBS_UPLOAD_MAX', 5 * 1024 * 1024);

    /** Folder for résumé files. Created on first use (0700) with a deny-all .htaccess as a backstop. */
    function jobs_upload_dir(): string
    {
        $base = getenv('BCRF_UPLOAD_DIR') ?: '';
        if ($base === '') {
            // includes/jobs → site root → public_html → home folder
            $base = dirname(__DIR__, 4) . '/bcrf-uploads';
        }
        $dir = rtrim($base, '/') . '/jobs';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        if (is_dir($dir) && !is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        return $dir;
    }

    /**
     * Check one $_FILES entry. Returns [ok(bool), error message or '', info[]].
     * Empty (no file chosen) returns [true, '', []].
     */
    function jobs_upload_check(?array $f): array
    {
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || ($f['name'] ?? '') === '') return [true, '', []];
        $e = (int)$f['error'];
        if ($e === UPLOAD_ERR_INI_SIZE || $e === UPLOAD_ERR_FORM_SIZE) return [false, 'Your résumé is over 5 MB. Please upload a smaller PDF.', []];
        if ($e !== UPLOAD_ERR_OK) return [false, 'Your résumé didn\'t upload. Please try again, or leave it off and send it later.', []];
        if (!is_uploaded_file($f['tmp_name'])) return [false, 'Your résumé didn\'t upload. Please try again.', []];
        $size = (int)filesize($f['tmp_name']);
        if ($size <= 0) return [false, 'That file is empty.', []];
        if ($size > JOBS_UPLOAD_MAX) return [false, 'Your résumé is over 5 MB. Please upload a smaller PDF.', []];

        $mime = '';
        if (class_exists('finfo')) $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $head = (string)@file_get_contents($f['tmp_name'], false, null, 0, 5);
        if ($head !== '%PDF-' || ($mime !== '' && $mime !== 'application/pdf')) {
            return [false, 'Résumés must be a PDF file. (On a phone, use "Print" then "Save as PDF", or leave it off.)', []];
        }
        $orig = basename(str_replace('\\', '/', (string)$f['name']));
        $orig = preg_replace('/[^\pL\pN ._()-]/u', '_', $orig);
        if (!preg_match('/\.pdf$/i', $orig)) $orig .= '.pdf';
        return [true, '', ['tmp' => $f['tmp_name'], 'size' => $size, 'original' => mb_substr($orig, 0, 200)]];
    }

    /** Move a checked upload into the folder. Returns the stored name (32 hex) or throws. */
    function jobs_upload_store(array $info): string
    {
        $dir = jobs_upload_dir();
        if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('Upload folder is missing or not writable: ' . $dir);
        $name = bin2hex(random_bytes(16));
        if (!move_uploaded_file($info['tmp'], $dir . '/' . $name)) throw new RuntimeException('Could not save the uploaded file.');
        @chmod($dir . '/' . $name, 0600);
        return $name;
    }

    /** Full path for a stored name, or '' if the name is bad / file is missing. */
    function jobs_upload_path(string $stored): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $stored)) return '';
        $p = jobs_upload_dir() . '/' . $stored;
        return is_file($p) ? $p : '';
    }

    function jobs_upload_delete(string $stored): void
    {
        $p = jobs_upload_path($stored);
        if ($p !== '') @unlink($p);
    }
}
