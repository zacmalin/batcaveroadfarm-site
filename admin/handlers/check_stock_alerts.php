<?php
// admin/handlers/check_stock_alerts.php — daily low-stock email (queue job 'check_stock_alerts', 8:00 AM; includes/queue.php).
// Nov 18, 2026 cleanup: uses the one low-stock rule (includes/low_stock.php) plus this email's own "Include in the daily
// low-stock email" box (is_alert_enabled), and sends through the shared mailer bcf_send_mail() instead of its own SMTP setup.
// Cron/command-line only — block web visitors from triggering alert emails
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__);
require_once '../../config.php';
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../../includes/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
require_once __DIR__ . '/../includes/email_template.php';
require_once __DIR__ . '/../../includes/low_stock.php';
require_once __DIR__ . '/../../includes/cron_health.php';
bcrf_cron_begin('check_stock_alerts');   // heartbeat for the admin System Health page

$low_stock_items = bcrf_low_stock_items($pdo, 'is_alert_enabled = 1');

if (!empty($low_stock_items)) {
    $rows = '';
    foreach ($low_stock_items as $item) {
        $rows .= '<tr><td style="padding:4px 14px 4px 0;color:#e8b84b;font-weight:bold;">' . htmlspecialchars($item['name'])
               . '</td><td style="padding:4px 0;">' . (int)$item['stock_quantity'] . ' remaining (min ' . (int)$item['min_stock_level'] . ')</td></tr>';
    }
    $inner = '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:24px;margin:0 0 14px;">Low stock alert</h1>'
           . '<p style="margin:0 0 16px;">The following items are at or below their minimum stock level:</p>'
           . '<table style="border-collapse:collapse;margin:0;">' . $rows . '</table>';
    $ok = bcf_send_mail(BCRF_STOCK_ALERT_TO, 'Low Stock Alert: ' . count($low_stock_items) . ' items', $inner, [
        'subtitle'   => 'Inventory Alert',
        'from_email' => bcrf_farm_email('support'),
        'text'       => trim(strip_tags(str_replace('</tr>', "\n", $inner))),
    ]);
    if (!$ok) {
        error_log('[stock_alerts] send failed: ' . ($GLOBALS['bcf_mail_last_error'] ?? ''));
        bcrf_cron_exit(1, 'Low-stock email failed to send: ' . mb_substr((string)($GLOBALS['bcf_mail_last_error'] ?? ''), 0, 200));
    }
}
