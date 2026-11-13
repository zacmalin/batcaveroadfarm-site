<?php // admin/cron/reminders.php
/*
 * Nov 13, 2026 (migration 0063). Every 15 minutes:
 *   1. the admin bell picks up anything new (includes/notifications.php: party and booking requests, online payments,
 *      overdue deposits, late tasks);
 *   2. between 9 AM and 6 PM farm time, deposit, balance and pass renewal reminder emails (includes/reminders.php).
 *      Those stay held (counted, not sent) until BCRF_REMINDER_EMAILS_LIVE is true, after the owner OKs the wording.
 * Safe to run any number of times. Started by the runner (includes/queue.php).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }

ob_start();
$bcrf_cfg = null;
foreach ([__DIR__ . '/../config.php', __DIR__ . '/../../config.php', __DIR__ . '/../../../config.php'] as $cand) {
    if (is_file($cand)) { $bcrf_cfg = $cand; break; }
}
if (!$bcrf_cfg) { fwrite(STDERR, "reminders: config.php not found\n"); exit(1); }
require_once $bcrf_cfg;
require_once dirname($bcrf_cfg) . '/includes/notifications.php';
require_once dirname($bcrf_cfg) . '/includes/reminders.php';
require_once dirname($bcrf_cfg) . '/admin/includes/email_template.php';
if (ob_get_level() > 0) { ob_end_clean(); }
require_once dirname($bcrf_cfg) . '/includes/cron_health.php';
bcrf_cron_begin('reminders');
$_SESSION = ['user_id' => null, 'user_name' => 'Reminders'];

try {
    if (!bcrf_notifications_ready($pdo)) { echo "Bell and reminders not installed yet (migration 0063).\n"; bcrf_cron_finish(); exit(0); }
    $added = bcrf_notify_sweep($pdo);
    $hour = (int)(new DateTimeImmutable('now', new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York')))->format('G');
    $r = ['due' => 0, 'sent' => 0, 'held' => 0];
    if ($hour >= 9 && $hour < 18) $r = bcrf_reminders_run($pdo);
    echo '[' . date('Y-m-d H:i:s') . "] Bell: {$added} new. Reminders: {$r['due']} due, {$r['sent']} sent" . ($r['held'] ? ", {$r['held']} held until the wording is OK'd" : '') . ".\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'reminders: ' . $e->getMessage() . "\n");
    bcrf_cron_exit(1, $e->getMessage());
}
bcrf_cron_finish();
