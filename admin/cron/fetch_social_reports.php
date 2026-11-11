<?php // admin/cron/fetch_social_reports.php
/*
 * Daily refresh of Reports › Social Media (Nov 11, 2026): Facebook Page + Instagram insights and recent
 * posts, stored by includes/social_reports.php so the admin page never waits on Meta.
 *
 * cPanel cron (once a day, 6:20 AM) — until the Farm OS runner takes over scheduling:
 *   20 6 * * * /usr/local/bin/php /home2/ddailnmy/public_html/batcaveroadfarm/admin/cron/fetch_social_reports.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }

ob_start();
$bcrf_cfg = null;
foreach ([__DIR__ . '/../config.php', __DIR__ . '/../../config.php', __DIR__ . '/../../../config.php'] as $cand) {
    if (is_file($cand)) { $bcrf_cfg = $cand; break; }
}
if (!$bcrf_cfg) { fwrite(STDERR, "fetch_social_reports: config.php not found\n"); exit(1); }
require_once $bcrf_cfg;
if (ob_get_level() > 0) { ob_end_clean(); }
require_once dirname($bcrf_cfg) . '/includes/cron_health.php';
bcrf_cron_begin('fetch_social_reports');   // heartbeat for System Health
require_once dirname($bcrf_cfg) . '/includes/social_reports.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');   // farm time zone setting (Phase 0, C-03)

if (!bcrf_socrep_tables_ready($pdo)) { echo "run migration 0026 first\n"; bcrf_cron_exit(1, 'migration 0026 not run'); }
$res = bcrf_socrep_refresh($pdo);
echo '[' . date('Y-m-d H:i:s') . '] facebook: ' . $res['facebook'] . ' | instagram: ' . $res['instagram'] . "\n";
if ($res['facebook'] !== 'ok' && $res['instagram'] !== 'ok') bcrf_cron_exit(1);
