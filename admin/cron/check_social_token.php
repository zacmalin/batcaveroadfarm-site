<?php // admin/cron/check_social_token.php
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../../includes/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
/*
 * Daily Facebook Page token check (Nov 2026). Emails you if the token stops working
 * (once a day until fixed) and before it or its data access expires (14, 7, 3, 2, 1, 0 days).
 * Saves the result for the admin banner (Dashboard + Social Queue). Logic: includes/social_token.php.
 *
 * cPanel cron (once a day, e.g. 8:05 AM):
 *   5 8 * * * /usr/local/bin/php /home2/ddailnmy/public_html/batcaveroadfarm/admin/cron/check_social_token.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }

ob_start();
$bcrf_cfg = null;
foreach ([__DIR__ . '/../config.php', __DIR__ . '/../../config.php', __DIR__ . '/../../../config.php'] as $cand) {
    if (is_file($cand)) { $bcrf_cfg = $cand; break; }
}
if (!$bcrf_cfg) { fwrite(STDERR, "check_social_token: config.php not found\n"); exit(1); }
require_once $bcrf_cfg;
$bcrf_docroot = realpath(dirname($bcrf_cfg)) ?: dirname($bcrf_cfg);
@include_once $bcrf_docroot . '/admin/includes/email_template.php';
require_once $bcrf_docroot . '/includes/social_token.php';
if (ob_get_level() > 0) { ob_end_clean(); }
require_once dirname($bcrf_cfg) . '/includes/cron_health.php';
bcrf_cron_begin('check_social_token');   // heartbeat for the admin System Health page
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');   // farm time zone setting (Phase 0, C-03)

$s = bcrf_social_token_check();
[, $summary] = bcrf_social_token_summary($s);
echo '[' . date('Y-m-d H:i:s') . '] ' . ($s['state'] ?? '?') . ' — ' . $summary . "\n";

if ($alert = bcrf_social_token_alert($s)) {
    bcrf_social_token_save($s);   // remember today's alert so it isn't repeated
    [$subject, $msg] = $alert;
    if (function_exists('bcf_send_mail') && defined('BCRF_STOCK_ALERT_TO') && BCRF_STOCK_ALERT_TO !== '') {
        bcf_send_mail(BCRF_STOCK_ALERT_TO, 'Bat Cave Road Farm: ' . $subject,
            '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:22px;margin:0 0 14px;">' . htmlspecialchars($subject) . '</h1>'
            . '<p style="margin:0 0 12px;">' . htmlspecialchars($msg) . '</p>'
            . ('<p style="margin:0;"><a href="' . bcrf_farm_url() . '/admin/admin.php?view=social" style="color:#e8b84b;">Open the Social Queue</a></p>'),
            ['subtitle' => 'System Alert']);
    }
    echo "  emailed: {$subject}\n";
} else {
    bcrf_social_token_save($s);
}
exit(in_array($s['state'] ?? '', ['invalid', 'missing'], true) ? 1 : 0);
