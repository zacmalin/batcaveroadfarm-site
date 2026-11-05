<?php // admin/cron/backup_files.php
/*
 * Weekly files backup (Nov 2026).
 *
 * What it saves (everything that is NOT in GitHub):
 *   site/img, site/images, site/media, site/uploads  — photo vault + site images (whichever exist)
 *   bcrf-uploads/                                     — job résumés and other private uploads
 *   bcrf-secrets.php                                  — passwords and API keys
 * The code itself is in GitHub (zacmalin/batcaveroadfarm-site); the database has its own nightly backup.
 *
 * Output: /home2/<user>/bcrf-backups/bcrf-files-YYYYMMDD-HHMMSS.zip — AES-256 encrypted with
 * BACKUP_PASSWORD. Keeps the newest 4 here, copies each one to Google Drive (keeps 4 there).
 * Emails you if anything fails.
 *
 * cPanel cron (once a week, e.g. Sunday 3:40 AM):
 *   40 3 * * 0  /usr/local/bin/php /home2/ddailnmy/public_html/batcaveroadfarm/admin/cron/backup_files.php >> /home2/ddailnmy/bcrf-backups/backup_files.log 2>&1
 *
 * To restore: see docs/RESTORE.md.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }

ob_start();
$bcrf_cfg = null;
foreach ([__DIR__ . '/../config.php', __DIR__ . '/../../config.php', __DIR__ . '/../../../config.php'] as $cand) {
    if (is_file($cand)) { $bcrf_cfg = $cand; break; }
}
if (!$bcrf_cfg) { fwrite(STDERR, "backup_files: config.php not found\n"); exit(1); }
require_once $bcrf_cfg;
$bcrf_docroot = realpath(dirname($bcrf_cfg)) ?: dirname($bcrf_cfg);
@include_once $bcrf_docroot . '/admin/includes/email_template.php';
require_once $bcrf_docroot . '/includes/backup_offsite.php';
if (ob_get_level() > 0) { ob_end_clean(); }
require_once dirname($bcrf_cfg) . '/includes/cron_health.php';
bcrf_cron_begin('backup_files');   // heartbeat for the admin System Health page

@set_time_limit(0);
@ini_set('memory_limit', '512M');
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');   // farm time zone setting (Phase 0, C-03)

const BCRF_FILES_KEEP_LOCAL = 4;
const BCRF_FILES_KEEP_DRIVE = 4;

$log  = function (string $m) { echo '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n"; };
$fail = function (string $why) use ($log) {
    $log('FAILED: ' . $why);
    bcrf_backup_alert('files backup FAILED', 'Weekly files backup failed', $why);
    bcrf_cron_exit(1);
};

$dir = bcrf_backup_dir();
if (!is_dir($dir) && !@mkdir($dir, 0700, true)) $fail("cannot create folder {$dir}");
@chmod($dir, 0700);
if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");

// ── What to back up ──────────────────────────────────────────────────────────
$home = dirname($bcrf_docroot, 2);
$sources = [];
foreach (['img', 'images', 'media', 'uploads'] as $d) {
    if (is_dir($bcrf_docroot . '/' . $d)) $sources['site/' . $d] = $bcrf_docroot . '/' . $d;
}
if (is_dir($home . '/bcrf-uploads')) $sources['bcrf-uploads'] = $home . '/bcrf-uploads';
if (defined('BCRF_SECRETS_PATH') && BCRF_SECRETS_PATH !== '' && is_file(BCRF_SECRETS_PATH)) {
    $sources['bcrf-secrets.php'] = BCRF_SECRETS_PATH;
}
if (!$sources) $fail('nothing to back up — no img/uploads folders or secrets file found');

// ── Build + verify the encrypted zip ─────────────────────────────────────────
$t0  = microtime(true);
$out = $dir . '/bcrf-files-' . date('Ymd-His') . '.zip';
try {
    $added = bcrf_backup_make_zip($out, $sources, (string)BCRF_BACKUP_PASSWORD);
    $count = bcrf_backup_verify_zip($out, (string)BCRF_BACKUP_PASSWORD);
} catch (Throwable $e) {
    @unlink($out);
    $fail($e->getMessage());
}
$size = (int)filesize($out);
$log(sprintf('OK %s: %d files (%s), %s MB in %.1fs', basename($out), $count, implode(', ', array_keys($sources)),
    number_format($size / 1048576, 1), microtime(true) - $t0));

// ── Local retention ──────────────────────────────────────────────────────────
$all = glob($dir . '/bcrf-files-*.zip') ?: [];
rsort($all);
foreach (array_slice($all, BCRF_FILES_KEEP_LOCAL) as $old) @unlink($old);

// ── Off-site copy ────────────────────────────────────────────────────────────
[$st, $msg] = bcrf_backup_offsite($out, 'bcrf-files-', BCRF_FILES_KEEP_DRIVE);
$log('Google Drive: ' . $st . ' — ' . $msg);
if ($st === 'failed') {
    bcrf_backup_alert('off-site files backup FAILED', 'Files backup saved, but the Google Drive copy failed',
        $msg . ' (the backup is still on the server: ' . basename($out) . ')');
    bcrf_cron_exit(1);
}
exit(0);
