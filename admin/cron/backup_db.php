<?php
/**
 * admin/cron/backup_db.php — nightly database backup (fix pack K).
 *
 * Saves a compressed copy of the whole database to a folder OUTSIDE the web
 * root, keeps the last 14 days, and emails you if a backup fails.
 *
 *   Backups go to:  /home2/<user>/bcrf-backups/bcrf-db-YYYYMMDD-HHMMSS.sql.gz
 *
 * cPanel cron (once a day, e.g. 3:15 AM):
 *   /usr/local/bin/php /home2/ddailnmy/public_html/batcaveroadfarm/admin/cron/backup_db.php
 *
 * Command line only. It can never be run from a web browser.
 *
 * Uses mysqldump when the server has it (fastest, most complete). If not,
 * falls back to a built-in PHP exporter. Either file can be restored with
 * phpMyAdmin > Import (it accepts .sql.gz directly).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

ob_start();
$bcrf_cfg = null;
foreach ([__DIR__ . '/../config.php', __DIR__ . '/../../config.php', __DIR__ . '/../../../config.php'] as $cand) {
    if (is_file($cand)) { $bcrf_cfg = $cand; break; }
}
if (!$bcrf_cfg) { fwrite(STDERR, "backup_db: config.php not found\n"); exit(1); }
require_once $bcrf_cfg;
$bcrf_docroot = realpath(dirname($bcrf_cfg)) ?: dirname($bcrf_cfg);   // realpath: resolve the "/../" so folder math is right
@include_once $bcrf_docroot . '/admin/includes/email_template.php';
if (ob_get_level() > 0) { ob_end_clean(); }

@set_time_limit(0);
date_default_timezone_set('America/New_York');

const BCRF_BACKUP_KEEP_DAYS = 14;
const BCRF_BACKUP_KEEP_MIN  = 3;     // never delete below this many files

$dir = getenv('BCRF_BACKUP_DIR') ?: (dirname($bcrf_docroot, 2) . '/bcrf-backups');
$log = function (string $m) { echo '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n"; };

function bcrf_backup_fail(string $why): void
{
    fwrite(STDERR, "backup_db FAILED: {$why}\n");
    error_log('[backup_db] ' . $why);
    if (function_exists('bcf_send_mail') && defined('BCRF_STOCK_ALERT_TO') && BCRF_STOCK_ALERT_TO !== '') {
        @bcf_send_mail(BCRF_STOCK_ALERT_TO, 'Bat Cave Road Farm: database backup FAILED',
            '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:22px;margin:0 0 14px;">Nightly backup failed</h1>'
            . '<p style="margin:0 0 12px;">The database backup on ' . date('M j, Y g:i A') . ' did not complete.</p>'
            . '<p style="margin:0;font-family:monospace;font-size:13px;">' . htmlspecialchars($why) . '</p>',
            ['subtitle' => 'System Alert']);
    }
    exit(1);
}

if (!is_dir($dir) && !@mkdir($dir, 0700, true)) bcrf_backup_fail("cannot create folder {$dir}");
@chmod($dir, 0700);
if (!is_writable($dir)) bcrf_backup_fail("folder not writable: {$dir}");

// Keep the folder private even if it ever ends up under a web root.
if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");

$file = $dir . '/bcrf-db-' . date('Ymd-His') . '.sql.gz';
$t0   = microtime(true);
$how  = '';

// ── 1. mysqldump (preferred) ────────────────────────────────────────────────
function bcrf_find_mysqldump(): string
{
    foreach (['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/usr/bin/mariadb-dump', '/usr/local/bin/mariadb-dump'] as $p) {
        if (is_file($p) && is_executable($p)) return $p;
    }
    if (function_exists('shell_exec')) {
        $p = trim((string)@shell_exec('command -v mysqldump 2>/dev/null || command -v mariadb-dump 2>/dev/null'));
        if ($p !== '' && is_executable($p)) return $p;
    }
    return '';
}

function bcrf_dump_with_mysqldump(string $bin, string $file, string &$err): bool
{
    if (!function_exists('proc_open')) { $err = 'proc_open disabled'; return false; }

    // Password goes in a private temp file, never on the command line (where
    // other processes on a shared server could see it).
    $cnf = tempnam(dirname($file), '.my');
    @chmod($cnf, 0600);
    $q = fn($v) => '"' . addcslashes((string)$v, "\\\"") . '"';
    file_put_contents($cnf, "[client]\nuser={$q(DB_USER)}\npassword={$q(DB_PASS)}\nhost={$q(DB_HOST)}\n");

    $cmd = [$bin, '--defaults-extra-file=' . $cnf, '--single-transaction', '--quick', '--routines', '--triggers',
            '--no-tablespaces', '--default-character-set=utf8mb4', '--hex-blob', DB_NAME];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) { @unlink($cnf); $err = 'could not start mysqldump'; return false; }

    $gz = gzopen($file, 'wb6');
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 1 << 16);
        if ($chunk === false) break;
        if ($chunk !== '') gzwrite($gz, $chunk);
    }
    gzclose($gz);
    $err  = trim((string)stream_get_contents($pipes[2]));
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    @unlink($cnf);

    if ($code !== 0) { @unlink($file); $err = "mysqldump exit {$code}: {$err}"; return false; }
    return true;
}

// ── 2. Built-in PHP exporter (fallback) ─────────────────────────────────────
function bcrf_dump_with_php(PDO $pdo, string $file): void
{
    $gz = gzopen($file, 'wb6');
    $w  = fn(string $s) => gzwrite($gz, $s);
    $ident = fn(string $n) => '`' . str_replace('`', '``', $n) . '`';
    $val = function ($v) use ($pdo) {
        if ($v === null) return 'NULL';
        if (is_int($v) || is_float($v)) return (string)$v;
        $v = (string)$v;
        if (!mb_check_encoding($v, 'UTF-8')) return $v === '' ? "''" : '0x' . bin2hex($v);   // binary data
        return $pdo->quote($v);
    };

    $w("-- Bat Cave Road Farm database backup (PHP exporter)\n-- " . date('c') . "\n\n");
    $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

    $tables = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    $views  = [];
    foreach ($tables as [$name, $type]) {
        if (strtoupper((string)$type) === 'VIEW') { $views[] = $name; continue; }
        $create = $pdo->query('SHOW CREATE TABLE ' . $ident($name))->fetch(PDO::FETCH_NUM)[1];
        $w("DROP TABLE IF EXISTS {$ident($name)};\n{$create};\n\n");

        $st = $pdo->query('SELECT * FROM ' . $ident($name));
        $batch = [];
        $cols  = null;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if ($cols === null) $cols = '(' . implode(', ', array_map($ident, array_keys($row))) . ')';
            $batch[] = '(' . implode(', ', array_map($val, array_values($row))) . ')';
            if (count($batch) >= 200) { $w("INSERT INTO {$ident($name)} {$cols} VALUES\n" . implode(",\n", $batch) . ";\n"); $batch = []; }
        }
        if ($batch) $w("INSERT INTO {$ident($name)} {$cols} VALUES\n" . implode(",\n", $batch) . ";\n");
        $st->closeCursor();
        $w("\n");
    }
    foreach ($views as $name) {
        $create = $pdo->query('SHOW CREATE VIEW ' . $ident($name))->fetch(PDO::FETCH_NUM)[1];
        $create = preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', $create);
        $w("DROP VIEW IF EXISTS {$ident($name)};\n{$create};\n\n");
    }
    $w("SET FOREIGN_KEY_CHECKS=1;\n-- end of backup\n");
    gzclose($gz);
}

$bin = getenv('BCRF_BACKUP_FORCE_PHP') ? '' : bcrf_find_mysqldump();
$err = '';
if ($bin !== '' && bcrf_dump_with_mysqldump($bin, $file, $err)) {
    $how = 'mysqldump';
} else {
    if ($bin !== '') $log("mysqldump failed, using PHP exporter ({$err})");
    try {
        bcrf_dump_with_php($pdo, $file);
        $how = 'php';
    } catch (Throwable $e) {
        @unlink($file);
        bcrf_backup_fail('PHP exporter: ' . $e->getMessage());
    }
}
@chmod($file, 0600);

// ── Sanity check: file is real gzip and contains table definitions ───────────
$size = (int)@filesize($file);
$head = '';
if ($gz = @gzopen($file, 'rb')) { $head = (string)gzread($gz, 1 << 20); gzclose($gz); }
if ($size < 200 || stripos($head, 'CREATE TABLE') === false) {
    bcrf_backup_fail("backup file looks empty or broken ({$size} bytes): {$file}");
}

// ── Retention: delete backups older than 14 days, always keep the newest 3 ──
$all = glob($dir . '/bcrf-db-*.sql.gz') ?: [];
rsort($all);   // names sort by date, newest first
$removed = 0;
foreach (array_slice($all, BCRF_BACKUP_KEEP_MIN) as $old) {
    if (filemtime($old) < time() - BCRF_BACKUP_KEEP_DAYS * 86400 && @unlink($old)) $removed++;
}

$log(sprintf('OK %s via %s, %s KB in %.1fs. %d backup(s) kept, %d old removed.',
    basename($file), $how, number_format($size / 1024, 1), microtime(true) - $t0, count($all) - $removed, $removed));
exit(0);
