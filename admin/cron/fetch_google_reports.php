<?php // admin/cron/fetch_google_reports.php
/*
 * Daily refresh of the admin "Search & Analytics" screen (Nov 2026): pulls Search Console and
 * GA4 numbers with the service account and caches them (includes/google_reports.php).
 *
 * cPanel cron (once a day, e.g. 6:10 AM):
 *   10 6 * * * /usr/local/bin/php /home2/ddailnmy/public_html/batcaveroadfarm/admin/cron/fetch_google_reports.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }

ob_start();
$bcrf_cfg = null;
foreach ([__DIR__ . '/../config.php', __DIR__ . '/../../config.php', __DIR__ . '/../../../config.php'] as $cand) {
    if (is_file($cand)) { $bcrf_cfg = $cand; break; }
}
if (!$bcrf_cfg) { fwrite(STDERR, "fetch_google_reports: config.php not found\n"); exit(1); }
require_once $bcrf_cfg;
if (ob_get_level() > 0) { ob_end_clean(); }
require_once dirname($bcrf_cfg) . '/includes/cron_health.php';
bcrf_cron_begin('fetch_google_reports');   // heartbeat for System Health
require_once dirname($bcrf_cfg) . '/includes/google_reports.php';
date_default_timezone_set('America/New_York');

$res = bcrf_greports_refresh($pdo);
echo '[' . date('Y-m-d H:i:s') . '] search console: ' . $res['gsc'] . ' | analytics: ' . $res['ga'] . "\n";
if ($res['gsc'] !== 'ok' && $res['ga'] !== 'ok') bcrf_cron_exit(1);
