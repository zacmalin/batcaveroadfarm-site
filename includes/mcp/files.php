<?php
// includes/mcp/files.php
//
// Website file tools for the farm MCP server (Nov 2026).
//
// Rules:
//   - Only inside the site folder (public_html/batcaveroadfarm). Paths are resolved
//     with realpath, so ../ and symlinks can't escape it.
//   - Text files only (php, html, css, js, json, md, txt, xml, svg, csv, yml).
//   - Never readable: config.php, anything named like a secret/credential/.env,
//     .git, vendor, node_modules.
//   - Never writable (guardrails the AI must not be able to remove): the MCP server
//     itself (includes/mcp, api/), config.php, login/auth/permission code, dotfiles
//     such as .htaccess.
//   - Every write first copies the old file to /home2/<user>/bcrf-mcp-backups
//     (outside the website). PHP is syntax-checked and JSON is validated before saving.
//   - Edits change the LIVE server only, not GitHub.

if (!function_exists('mcp_files_root')) {

define('MCP_FILE_EXTS', ['php', 'html', 'htm', 'css', 'js', 'mjs', 'json', 'md', 'txt', 'xml', 'svg', 'csv', 'yml', 'yaml']);
define('MCP_READ_MAX', 200000);     // bytes per read_file call
define('MCP_WRITE_MAX', 1048576);    // 1 MB per file
define('MCP_BACKUP_KEEP', 300);        // newest backups kept
define('MCP_BACKUP_DAYS', 120);

function mcp_files_root(): string { return realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2); }

function mcp_backup_dir(): string {
    $d = getenv('BCRF_MCP_BACKUP_DIR') ?: (dirname(mcp_files_root(), 2) . '/bcrf-mcp-backups');
    if (!is_dir($d)) @mkdir($d, 0700, true);
    if (is_dir($d) && !is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
    return $d;
}

/** Never readable or writable. $rel is relative to the root, forward slashes. */
function mcp_path_hidden(string $rel): bool {
    $l = strtolower($rel);
    if ($l === 'config.php') return true;
    if (preg_match('#(^|/)(\.git|vendor|node_modules)(/|$)#', $l)) return true;
    if (preg_match('#(secret|credential|password|\.env|\.pem$|\.key$|id_rsa|\.user\.ini$|php\.ini$)#', $l)) return true;
    return false;
}

/** Readable but never writable by the AI. */
function mcp_path_locked(string $rel): bool {
    $l = strtolower($rel);
    if (mcp_path_hidden($rel)) return true;
    if (preg_match('#(^|/)\.#', $l)) return true;                                   // .htaccess and other dotfiles
    if (preg_match('#^(api|includes/mcp)(/|$)#', $l)) return true;                   // the MCP server itself
    if (in_array($l, ['admin/login.php', 'admin/logout.php', 'admin/admin.php', 'admin/mcp_connections.php',
                      'includes/auth.php', 'admin/includes/audit.php', 'admin/includes/permission_checks.php',
                      'admin/includes/permissions.php'], true)) return true;
    return false;
}

/**
 * Resolve a user path. Returns [abs, rel]. $forWrite allows a not-yet-existing file
 * whose folder exists.
 */
function mcp_resolve(string $path, bool $forWrite = false, bool $allowDir = false): array {
    $root = mcp_files_root();
    $path = trim(str_replace('\\', '/', $path));
    $path = ltrim(preg_replace('#^/?(public_html/)?(batcaveroadfarm/)?#', '', $path), '/');
    if (str_contains($path, "\0")) throw new McpToolError('Bad path.');
    $cand = $root . ($path === '' ? '' : '/' . $path);
    $abs = realpath($cand);
    if ($abs === false) {
        if (!$forWrite) throw new McpToolError("Not found: $path");
        $dir = realpath(dirname($cand));
        if ($dir === false) throw new McpToolError('That folder doesn\'t exist: ' . dirname($path));
        $abs = $dir . '/' . basename($cand);
    }
    if ($abs !== $root && !str_starts_with($abs, $root . '/')) throw new McpToolError('That path is outside the website folder.');
    $rel = ltrim(substr($abs, strlen($root)), '/');
    if (mcp_path_hidden($rel)) throw new McpToolError("$rel is off-limits (config or secrets).");
    if (is_dir($abs) && !$allowDir) throw new McpToolError("$rel is a folder. Use list_files.");
    if (!is_dir($abs)) {
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        // Read-only extras: .htaccess and PHP error logs (error_log / *.log), for diagnosing problems.
        $isHt = basename($abs) === '.htaccess' || basename($abs) === 'error_log' || $ext === 'log';
        if (!in_array($ext, MCP_FILE_EXTS, true) && !($isHt && !$forWrite)) throw new McpToolError("Only text files can be used here (." . implode(', .', MCP_FILE_EXTS) . ').');
    }
    return [$abs, $rel];
}

function mcp_t_list_files(PDO $pdo, array $a): array {
    [$abs, $rel] = mcp_resolve((string)($a['path'] ?? ''), false, true);
    if (!is_dir($abs)) throw new McpToolError("$rel is a file. Use read_file.");
    $out = [];
    foreach (scandir($abs) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $r = ($rel === '' ? '' : $rel . '/') . $f;
        if (mcp_path_hidden($r)) continue;
        $p = $abs . '/' . $f;
        $isDir = is_dir($p);
        $out[] = ['name' => $f . ($isDir ? '/' : ''), 'size' => $isDir ? null : filesize($p),
                  'modified' => date('Y-m-d H:i', (int)filemtime($p)),
                  'editable' => !$isDir && !mcp_path_locked($r) && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), MCP_FILE_EXTS, true)];
        if (count($out) >= 500) break;
    }
    return ['path' => $rel === '' ? '/' : $rel, 'entries' => $out];
}

function mcp_t_read_file(PDO $pdo, array $a): array {
    [$abs, $rel] = mcp_resolve((string)($a['path'] ?? ''));
    $data = (string)@file_get_contents($abs);
    if (str_contains(substr($data, 0, 8000), "\0")) throw new McpToolError('That looks like a binary file.');
    $lines = preg_split('/(?<=\n)/', $data);
    $total = count($lines);
    $from = max(1, (int)($a['offset_line'] ?? 1));
    $chunk = ''; $i = $from - 1;
    for (; $i < $total; $i++) {
        if (strlen($chunk) + strlen($lines[$i]) > MCP_READ_MAX && $chunk !== '') break;
        $chunk .= $lines[$i];
    }
    return [
        'path' => $rel, 'size' => strlen($data), 'sha256' => hash('sha256', $data),
        'total_lines' => $total, 'from_line' => $from, 'to_line' => $i,
        'next_offset_line' => $i < $total ? $i + 1 : null,
        'editable' => !mcp_path_locked($rel),
        'content' => $chunk,
    ];
}

/** Throws if the new content is broken. */
function mcp_validate_content(string $rel, string $content): void {
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if ($ext === 'php') {
        try { token_get_all($content, TOKEN_PARSE); }
        catch (ParseError $e) { throw new McpToolError('Not saved: PHP syntax error on line ' . $e->getLine() . ': ' . $e->getMessage()); }
    }
    if ($ext === 'json' && $content !== '') {
        json_decode($content);
        if (json_last_error() !== JSON_ERROR_NONE) throw new McpToolError('Not saved: invalid JSON (' . json_last_error_msg() . ').');
    }
    if (!mb_check_encoding($content, 'UTF-8')) throw new McpToolError('Not saved: content is not valid UTF-8.');
}

/** Copy the current file to the backup folder. Returns the backup id (or null if the file didn't exist). */
function mcp_backup_file(string $abs, string $rel, string $why): ?string {
    if (!is_file($abs)) return null;
    $dir = mcp_backup_dir();
    if (!is_dir($dir) || !is_writable($dir)) throw new McpToolError('Not saved: the backup folder is missing or not writable (' . $dir . ').');
    $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    if (!@copy($abs, "$dir/$id.bak")) throw new McpToolError('Not saved: could not make a backup first.');
    @chmod("$dir/$id.bak", 0600);
    file_put_contents("$dir/$id.json", json_encode([
        'id' => $id, 'path' => $rel, 'sha256' => hash_file('sha256', $abs), 'size' => filesize($abs),
        'created' => date('Y-m-d H:i:s'), 'why' => $why, 'by' => $_SESSION['user_name'] ?? '',
    ], JSON_UNESCAPED_SLASHES));
    mcp_prune_backups($dir);
    return $id;
}

function mcp_prune_backups(string $dir): void {
    $metas = glob($dir . '/*.json') ?: [];
    rsort($metas);   // ids start with the timestamp → newest first
    $cut = date('Ymd', time() - MCP_BACKUP_DAYS * 86400);
    foreach ($metas as $i => $m) {
        $id = basename($m, '.json');
        if ($i >= MCP_BACKUP_KEEP || substr($id, 0, 8) < $cut) { @unlink($m); @unlink("$dir/$id.bak"); }
    }
}

function mcp_write_atomic(string $abs, string $content): void {
    $perm = is_file($abs) ? (fileperms($abs) & 0777) : 0644;
    $tmp = dirname($abs) . '/.mcp-tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $content) !== strlen($content)) { @unlink($tmp); throw new McpToolError('Not saved: could not write the file (permissions?).'); }
    @chmod($tmp, $perm);
    if (!@rename($tmp, $abs)) { @unlink($tmp); throw new McpToolError('Not saved: could not replace the file.'); }
    if (function_exists('opcache_invalidate')) @opcache_invalidate($abs, true);
    clearstatcache(true, $abs);
}

function mcp_t_write_file(PDO $pdo, array $a): array {
    $hasB64 = isset($a['content_base64']) && $a['content_base64'] !== '';
    if (!$hasB64 && !array_key_exists('content', $a)) throw new McpToolError('Give content or content_base64.');
    $content = $hasB64 ? base64_decode((string)$a['content_base64'], true) : (string)$a['content'];
    if ($content === false) throw new McpToolError('content_base64 is not valid base64.');
    if (strlen($content) > MCP_WRITE_MAX) throw new McpToolError('That file is over 1 MB.');

    [$abs, $rel] = mcp_resolve((string)($a['path'] ?? ''), true);
    if (mcp_path_locked($rel)) throw new McpToolError("$rel is protected and can't be edited through the AI. Change it through GitHub instead.");
    $exists = is_file($abs);
    if (!$exists && empty($a['create'])) throw new McpToolError("$rel doesn't exist. Pass create: true to make a new file.");
    $before = $exists ? (string)file_get_contents($abs) : '';
    if ($exists && !empty($a['expected_sha256']) && !hash_equals(hash('sha256', $before), strtolower((string)$a['expected_sha256']))) {
        throw new McpToolError('Not saved: the file changed since you read it. Read it again first.');
    }
    if ($exists && $before === $content) return ['path' => $rel, 'changed' => false, 'note' => 'Content is identical; nothing saved.'];
    mcp_validate_content($rel, $content);

    $bid = mcp_backup_file($abs, $rel, 'before write_file');
    mcp_write_atomic($abs, $content);
    mcp_audit($pdo, $exists ? 'update' : 'create', 'site_files', null,
        ($exists ? 'Edited ' : 'Created ') . $rel . ($bid ? ' (backup ' . $bid . ')' : ''));
    return [
        'path' => $rel, 'changed' => true, 'created' => !$exists, 'backup_id' => $bid,
        'size' => strlen($content), 'sha256' => hash('sha256', $content),
        'reminder' => 'Live server only. Copy this change into GitHub too, or the next upload from GitHub will undo it.',
    ];
}

function mcp_backup_meta(string $id): array {
    if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}$/', $id)) throw new McpToolError('Bad backup id.');
    $dir = mcp_backup_dir();
    $m = json_decode((string)@file_get_contents("$dir/$id.json"), true);
    if (!is_array($m) || !is_file("$dir/$id.bak")) throw new McpToolError("Backup $id not found.");
    return $m;
}

function mcp_t_list_backups(PDO $pdo, array $a): array {
    $dir = mcp_backup_dir();
    $want = trim((string)($a['path'] ?? ''));
    if ($want !== '') { try { [, $want] = mcp_resolve($want, true); } catch (McpToolError $e) { $want = ltrim($want, '/'); } }
    $metas = glob($dir . '/*.json') ?: [];
    rsort($metas);
    $out = [];
    foreach ($metas as $m) {
        $j = json_decode((string)@file_get_contents($m), true);
        if (!is_array($j) || ($want !== '' && ($j['path'] ?? '') !== $want)) continue;
        $out[] = $j;
        if (count($out) >= mcp_limit($a, 25)) break;
    }
    return ['backups' => $out];
}

function mcp_t_restore_backup(PDO $pdo, array $a): array {
    $m = mcp_backup_meta((string)($a['backup_id'] ?? ''));
    [$abs, $rel] = mcp_resolve((string)$m['path'], true);
    if (mcp_path_locked($rel)) throw new McpToolError("$rel is protected.");
    $content = (string)file_get_contents(mcp_backup_dir() . '/' . $m['id'] . '.bak');
    mcp_validate_content($rel, $content);
    $bid = mcp_backup_file($abs, $rel, 'before restoring ' . $m['id']);
    mcp_write_atomic($abs, $content);
    mcp_audit($pdo, 'update', 'site_files', null, 'Restored ' . $rel . ' from backup ' . $m['id'] . ($bid ? ' (previous version: ' . $bid . ')' : ''));
    return ['path' => $rel, 'restored_from' => $m['id'], 'backup_of_replaced_version' => $bid,
            'reminder' => 'Live server only; GitHub is unchanged.'];
}

}
