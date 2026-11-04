<?php // includes/backup_offsite.php
/*
 * Encrypted backup archives + off-site copies to Google Drive (Nov 2026).
 *
 * Used by admin/cron/backup_db.php (nightly), admin/cron/backup_files.php (weekly)
 * and admin/backups.php (status page + "Connect Google Drive").
 *
 * Secrets (bcrf-secrets.php, outside the web root):
 *   'BACKUP_PASSWORD'      => password for the AES-256 zips. ALSO keep it in your password
 *                             manager — if Bluehost is lost, the backups are useless without it.
 *   'GDRIVE_CLIENT_ID'     => Google Cloud OAuth client (Web application)
 *   'GDRIVE_CLIENT_SECRET' => ...its secret
 * The Drive refresh token is saved by admin/backups.php to /home2/<user>/bcrf-gdrive.json (0600).
 *
 * Drive scope is drive.file: the app can only see files it created itself, never the rest of your Drive.
 */

if (!defined('BCRF_GDRIVE_FOLDER'))   define('BCRF_GDRIVE_FOLDER', 'BCRF Backups');
if (!defined('BCRF_GDRIVE_SCOPE'))    define('BCRF_GDRIVE_SCOPE', 'https://www.googleapis.com/auth/drive.file');
if (!defined('BCRF_GDRIVE_REDIRECT')) define('BCRF_GDRIVE_REDIRECT', 'https://batcaveroadfarm.com/admin/backups.php');
if (!defined('BCRF_GDRIVE_CHUNK'))    define('BCRF_GDRIVE_CHUNK', 8 * 1024 * 1024);   // must be a multiple of 256 KB

/** Folder above the web root (/home2/<user>), where the backups folder and Drive token live. */
function bcrf_backup_home(): string {
    $docroot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);   // includes/.. = docroot
    return dirname($docroot, 2);
}
function bcrf_backup_dir(): string {
    return getenv('BCRF_BACKUP_DIR') ?: (bcrf_backup_home() . '/bcrf-backups');
}
function bcrf_gdrive_token_file(): string {
    return getenv('BCRF_GDRIVE_TOKEN_FILE') ?: (bcrf_backup_home() . '/bcrf-gdrive.json');
}

// ── Encrypted zip ─────────────────────────────────────────────────────────────

/** True if this server's PHP can write AES-256 encrypted zips. */
function bcrf_backup_can_encrypt(): bool {
    return class_exists('ZipArchive') && defined('ZipArchive::EM_AES_256')
        && (!method_exists('ZipArchive', 'isEncryptionMethodSupported') || ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true));
}

/**
 * Build an AES-256 encrypted zip.
 * $sources: [ 'name inside zip' => '/absolute/path' ]; a path may be a file or a whole folder.
 * Returns the number of files added. Throws on any failure (never leaves a half-written zip).
 */
function bcrf_backup_make_zip(string $out, array $sources, string $password): int {
    if ($password === '') throw new RuntimeException('BACKUP_PASSWORD is not set in bcrf-secrets.php');
    if (!bcrf_backup_can_encrypt()) throw new RuntimeException('this PHP cannot write AES-256 zips (needs libzip 1.2+)');
    @unlink($out);
    $zip = new ZipArchive();
    if ($zip->open($out, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException("cannot create {$out}");
    $n = 0;
    $add = function (string $path, string $name) use ($zip, $password, &$n) {
        if (!$zip->addFile($path, $name)) throw new RuntimeException("cannot add {$path}");
        $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password);
        // Photos, PDFs and .gz are already compressed — store them as-is (much faster, same size).
        if (preg_match('/\.(jpe?g|png|webp|gif|pdf|gz|zip|mp4|mov)$/i', $name)) $zip->setCompressionName($name, ZipArchive::CM_STORE);
        $n++;
    };
    foreach ($sources as $inZip => $path) {
        if (is_file($path)) { $add($path, $inZip); continue; }
        if (!is_dir($path)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->isLink()) continue;
            $rel = substr($f->getPathname(), strlen(rtrim($path, '/')) + 1);
            $add($f->getPathname(), rtrim($inZip, '/') . '/' . str_replace('\\', '/', $rel));
        }
    }
    if (!$zip->close()) { @unlink($out); throw new RuntimeException('zip close failed: ' . $zip->getStatusString()); }
    @chmod($out, 0600);
    return $n;
}

/** Re-open the zip and read the first file back with the password — proves it is valid and decryptable. */
function bcrf_backup_verify_zip(string $file, string $password): int {
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) throw new RuntimeException('cannot reopen ' . basename($file));
    $count = $zip->numFiles;
    if ($count < 1) { $zip->close(); throw new RuntimeException('zip is empty'); }
    $zip->setPassword($password);
    $st = $zip->statIndex(0);
    if (($st['encryption_method'] ?? 0) !== ZipArchive::EM_AES_256) { $zip->close(); throw new RuntimeException('zip is not AES-256 encrypted'); }
    $data = $zip->getFromIndex(0, min(65536, max(1, (int)$st['size'])));
    $zip->close();
    if ($data === false && (int)$st['size'] > 0) throw new RuntimeException('zip did not decrypt with BACKUP_PASSWORD');
    return $count;
}

// ── Google Drive (REST v3, OAuth refresh token) ───────────────────────────────

function bcrf_gdrive_configured(): bool {
    return defined('BCRF_GDRIVE_CLIENT_ID') && BCRF_GDRIVE_CLIENT_ID !== ''
        && defined('BCRF_GDRIVE_CLIENT_SECRET') && BCRF_GDRIVE_CLIENT_SECRET !== '';
}
function bcrf_gdrive_state(): array {
    $f = bcrf_gdrive_token_file();
    $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return is_array($j) ? $j : [];
}
function bcrf_gdrive_save_state(array $s): void {
    $f = bcrf_gdrive_token_file();
    if (@file_put_contents($f, json_encode($s, JSON_PRETTY_PRINT), LOCK_EX) === false) throw new RuntimeException("cannot write {$f}");
    @chmod($f, 0600);
}
function bcrf_gdrive_connected(): bool {
    return bcrf_gdrive_configured() && !empty(bcrf_gdrive_state()['refresh_token']);
}

/** Small curl wrapper. $body: string|null. $fh: open file handle to stream as the body, with $len bytes. */
function bcrf_gdrive_http(string $method, string $url, array $headers = [], ?string $body = null, $fh = null, int $len = 0): array {
    // Test hook only: point at a local mock of the Google endpoints. Never set on the live server.
    if (($tb = getenv('BCRF_GDRIVE_TEST_BASE')) !== false && $tb !== '') {
        $url = preg_replace('#^https://(www|oauth2)\.googleapis\.com#', rtrim($tb, '/'), $url);
    }
    $ch = curl_init($url);
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => 600,
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$respHeaders) {
            $p = explode(':', $h, 2);
            if (count($p) === 2) $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
            return strlen($h);
        },
    ]);
    if ($fh !== null) {
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $fh);
        curl_setopt($ch, CURLOPT_INFILESIZE, $len);
    } elseif ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException("network error: {$err}");
    return [$code, $respHeaders, (string)$resp];
}

/** Trade the authorization code from the consent screen for a refresh token (admin/backups.php). */
function bcrf_gdrive_exchange_code(string $code): void {
    [$st, , $body] = bcrf_gdrive_http('POST', 'https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['code' => $code, 'client_id' => BCRF_GDRIVE_CLIENT_ID, 'client_secret' => BCRF_GDRIVE_CLIENT_SECRET,
                          'redirect_uri' => BCRF_GDRIVE_REDIRECT, 'grant_type' => 'authorization_code']));
    $j = json_decode($body, true) ?: [];
    if ($st !== 200 || empty($j['refresh_token'])) {
        throw new RuntimeException('Google did not return a refresh token (' . ($j['error_description'] ?? $j['error'] ?? "HTTP {$st}") . ')');
    }
    $s = bcrf_gdrive_state();
    $s['refresh_token'] = $j['refresh_token'];
    $s['connected_at']  = date('c');
    unset($s['folder_id']);
    bcrf_gdrive_save_state($s);
}

function bcrf_gdrive_access_token(): string {
    $s = bcrf_gdrive_state();
    if (empty($s['refresh_token'])) throw new RuntimeException('Google Drive is not connected (admin → Backups → Connect)');
    [$st, , $body] = bcrf_gdrive_http('POST', 'https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['client_id' => BCRF_GDRIVE_CLIENT_ID, 'client_secret' => BCRF_GDRIVE_CLIENT_SECRET,
                          'refresh_token' => $s['refresh_token'], 'grant_type' => 'refresh_token']));
    $j = json_decode($body, true) ?: [];
    if ($st !== 200 || empty($j['access_token'])) {
        $why = $j['error'] ?? "HTTP {$st}";
        if ($why === 'invalid_grant') $why .= ' — the Drive connection expired or was revoked; reconnect in admin → Backups';
        throw new RuntimeException('Drive sign-in failed: ' . $why);
    }
    return $j['access_token'];
}

function bcrf_gdrive_api(string $token, string $method, string $url, ?array $json = null): array {
    $h = ['Authorization: Bearer ' . $token];
    if ($json !== null) $h[] = 'Content-Type: application/json; charset=UTF-8';
    [$st, , $body] = bcrf_gdrive_http($method, $url, $h, $json !== null ? json_encode($json) : null);
    $j = json_decode($body, true);
    if ($st >= 300) throw new RuntimeException("Drive API {$method} HTTP {$st}: " . ($j['error']['message'] ?? substr($body, 0, 200)));
    return is_array($j) ? $j : [];
}

/** ID of the "BCRF Backups" folder (created on first use, then remembered). */
function bcrf_gdrive_folder(string $token): string {
    $s = bcrf_gdrive_state();
    if (!empty($s['folder_id'])) {
        try {
            $f = bcrf_gdrive_api($token, 'GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($s['folder_id']) . '?fields=id,trashed');
            if (empty($f['trashed'])) return $s['folder_id'];
        } catch (Throwable $e) { /* deleted or not visible — make a new one */ }
    }
    $q = "name = '" . BCRF_GDRIVE_FOLDER . "' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
    $r = bcrf_gdrive_api($token, 'GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query(['q' => $q, 'fields' => 'files(id)', 'pageSize' => 1]));
    $id = $r['files'][0]['id'] ?? '';
    if ($id === '') {
        $id = bcrf_gdrive_api($token, 'POST', 'https://www.googleapis.com/drive/v3/files?fields=id',
            ['name' => BCRF_GDRIVE_FOLDER, 'mimeType' => 'application/vnd.google-apps.folder'])['id'] ?? '';
    }
    if ($id === '') throw new RuntimeException('could not create the Drive folder');
    $s['folder_id'] = $id; bcrf_gdrive_save_state($s);
    return $id;
}

/** Resumable upload in 8 MB chunks (works for large files without loading them into memory). Returns Drive file id. */
function bcrf_gdrive_upload(string $token, string $path, string $name, string $folderId): string {
    $size = (int)filesize($path);
    if ($size < 1) throw new RuntimeException('refusing to upload an empty file');
    [$st, $hdr, $body] = bcrf_gdrive_http('POST',
        'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id,size',
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json; charset=UTF-8',
         'X-Upload-Content-Type: application/zip', 'X-Upload-Content-Length: ' . $size],
        json_encode(['name' => $name, 'parents' => [$folderId]]));
    $session = $hdr['location'] ?? '';
    if ($st !== 200 || $session === '') throw new RuntimeException("could not start upload (HTTP {$st}): " . substr($body, 0, 200));

    $fh = fopen($path, 'rb');
    if (!$fh) throw new RuntimeException("cannot read {$path}");
    $sent = 0; $result = null; $tries = 0;
    try {
        while ($sent < $size) {
            $len = (int)min(BCRF_GDRIVE_CHUNK, $size - $sent);
            $chunk = fopen('php://temp', 'w+b');
            fseek($fh, $sent); stream_copy_to_stream($fh, $chunk, $len); rewind($chunk);
            $range = 'bytes ' . $sent . '-' . ($sent + $len - 1) . '/' . $size;
            try {
                [$st, $hdr, $body] = bcrf_gdrive_http('PUT', $session, ['Content-Range: ' . $range, 'Content-Length: ' . $len], null, $chunk, $len);
            } catch (Throwable $e) { $st = 0; $body = $e->getMessage(); }
            fclose($chunk);
            if ($st === 200 || $st === 201) { $result = json_decode($body, true); break; }
            if ($st === 308) {   // chunk accepted; Google says how far it got
                $sent = isset($hdr['range']) && preg_match('/bytes=0-(\d+)/', $hdr['range'], $m) ? (int)$m[1] + 1 : $sent + $len;
                $tries = 0; continue;
            }
            if (++$tries > 4) throw new RuntimeException("upload failed at byte {$sent} (HTTP {$st}): " . substr($body, 0, 200));
            sleep(2 ** $tries);   // transient error: back off and resume this chunk
        }
    } finally { fclose($fh); }
    if (empty($result['id'])) throw new RuntimeException('upload finished without a file id');
    if (isset($result['size']) && (int)$result['size'] !== $size) throw new RuntimeException("Drive size {$result['size']} != local {$size}");
    return $result['id'];
}

/** Delete older backups with this name prefix from the folder, keeping the newest $keep. Returns how many were deleted. */
function bcrf_gdrive_prune(string $token, string $folderId, string $prefix, int $keep): int {
    $q = "'{$folderId}' in parents and name contains '" . str_replace("'", "\\'", $prefix) . "' and trashed = false";
    $r = bcrf_gdrive_api($token, 'GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query(
        ['q' => $q, 'orderBy' => 'createdTime desc', 'fields' => 'files(id,name)', 'pageSize' => 200]));
    $files = array_values(array_filter($r['files'] ?? [], fn($f) => str_starts_with((string)$f['name'], $prefix)));
    $n = 0;
    foreach (array_slice($files, max(1, $keep)) as $f) {
        bcrf_gdrive_api($token, 'DELETE', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($f['id']));
        $n++;
    }
    return $n;
}

/**
 * Copy one local backup to Drive and prune old copies.
 * Returns [status, message]: status 'ok' | 'skipped' (Drive not set up yet) | 'failed'.
 */
function bcrf_backup_offsite(string $zipPath, string $prefix, int $keep): array {
    if (!bcrf_gdrive_configured()) return ['skipped', 'Google Drive not set up (GDRIVE_CLIENT_ID/SECRET missing in bcrf-secrets.php)'];
    if (!bcrf_gdrive_connected())  return ['skipped', 'Google Drive not connected yet (admin → Backups → Connect Google Drive)'];
    try {
        $token  = bcrf_gdrive_access_token();
        $folder = bcrf_gdrive_folder($token);
        bcrf_gdrive_upload($token, $zipPath, basename($zipPath), $folder);
        $pruned = bcrf_gdrive_prune($token, $folder, $prefix, $keep);
        $s = bcrf_gdrive_state();
        $s['last_ok'][$prefix] = ['at' => date('c'), 'file' => basename($zipPath), 'bytes' => (int)filesize($zipPath)];
        unset($s['last_error'][$prefix]);
        bcrf_gdrive_save_state($s);
        return ['ok', 'uploaded ' . basename($zipPath) . ($pruned ? ", removed {$pruned} old copy(ies) from Drive" : '')];
    } catch (Throwable $e) {
        try { $s = bcrf_gdrive_state(); $s['last_error'][$prefix] = ['at' => date('c'), 'msg' => $e->getMessage()]; bcrf_gdrive_save_state($s); } catch (Throwable $ignored) {}
        return ['failed', $e->getMessage()];
    }
}

/** Email an alert (used by both backup crons). */
function bcrf_backup_alert(string $subject, string $headline, string $detail): void {
    error_log('[backup] ' . $subject . ': ' . $detail);
    if (function_exists('bcf_send_mail') && defined('BCRF_STOCK_ALERT_TO') && BCRF_STOCK_ALERT_TO !== '') {
        @bcf_send_mail(BCRF_STOCK_ALERT_TO, 'Bat Cave Road Farm: ' . $subject,
            '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:22px;margin:0 0 14px;">' . htmlspecialchars($headline) . '</h1>'
            . '<p style="margin:0 0 12px;">' . date('M j, Y g:i A') . '</p>'
            . '<p style="margin:0;font-family:monospace;font-size:13px;">' . htmlspecialchars($detail) . '</p>',
            ['subtitle' => 'System Alert']);
    }
}
