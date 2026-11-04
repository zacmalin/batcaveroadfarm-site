<?php // admin/cron/purge_job_records.php
/*
 * Daily hiring-records cleanup (Nov 2026). Runs the same steps as the
 * Hiring > Records buttons, automatically:
 *   - applications 1 year past their decision / job end / round close have
 *     personal details removed and résumé files deleted (Talent Pool is kept);
 *   - spam applications older than 30 days are deleted;
 *   - job-alert signups stopped 90+ days ago are deleted;
 *   - leftover résumé files with no application are deleted.
 * Logic and rules: includes/jobs/retention.php. Keep period: JOBS_CRON_RETAIN_MONTHS.
 *
 * cPanel cron (once a day, e.g. 3:20 AM):
 *   20 3 * * * /usr/local/bin/php /home2/ddailnmy/public_html/batcaveroadfarm/admin/cron/purge_job_records.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }

ob_start();
$bcrf_cfg = null;
foreach ([__DIR__ . '/../config.php', __DIR__ . '/../../config.php', __DIR__ . '/../../../config.php'] as $cand) {
    if (is_file($cand)) { $bcrf_cfg = $cand; break; }
}
if (!$bcrf_cfg) { fwrite(STDERR, "purge_job_records: config.php not found\n"); exit(1); }
require_once $bcrf_cfg;
$bcrf_docroot = realpath(dirname($bcrf_cfg)) ?: dirname($bcrf_cfg);
require_once $bcrf_docroot . '/admin/includes/audit.php';
require_once $bcrf_docroot . '/includes/jobs/retention.php';
if (ob_get_level() > 0) { ob_end_clean(); }
date_default_timezone_set('America/New_York');
@set_time_limit(600);

if (!defined('JOBS_CRON_RETAIN_MONTHS')) define('JOBS_CRON_RETAIN_MONTHS', 12);   // 1 year (stated in the Privacy Policy)

// History and audit entries show who did it.
$_SESSION = ['user_id' => null, 'user_name' => 'Automatic cleanup'];

$ts = '[' . date('Y-m-d H:i:s') . '] ';
try {
    $cleared = 0; $files = 0; $rounds = 0;
    do {   // 100 per batch; stop early if a batch clears nothing (e.g. every one errored)
        [$done, $gone, $left] = jobs_retention_purge($pdo, JOBS_CRON_RETAIN_MONTHS, false);
        $cleared += $done; $files += $gone; $rounds++;
    } while ($done > 0 && $left > 0 && $rounds < 50);

    $spam = 0; $rounds = 0;
    do { [$n, $left] = jobs_spam_purge($pdo); $spam += $n; $rounds++; } while ($n > 0 && $left > 0 && $rounds < 50);

    $alerts  = jobs_alerts_purge($pdo);
    $orphans = jobs_orphans_purge($pdo);

    echo $ts . "applications cleared: {$cleared} ({$files} résumé files), spam deleted: {$spam}, "
       . "stopped alert signups deleted: {$alerts}, leftover files deleted: {$orphans}\n";
} catch (Throwable $e) {
    fwrite(STDERR, $ts . 'purge_job_records failed: ' . $e->getMessage() . "\n");
    exit(1);
}
