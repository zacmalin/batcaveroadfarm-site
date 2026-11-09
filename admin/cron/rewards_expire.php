<?php // admin/cron/rewards_expire.php
/*
 * Roost Rewards expiry (lean version, Nov 9, 2026): a positive balance expires after 12 months
 * with no purchase or visit. Also sends the 30-day and 7-day reminder emails before that. Rules: includes/rewards.php. Does nothing while Roost Rewards is OFF.
 * Safe to run more than once a day: each expiry posts once per quiet spell.
 *
 * cPanel cron (once a day, e.g. 3:25 AM):
 *   25 3 * * * /usr/local/bin/php /home2/ddailnmy/public_html/batcaveroadfarm/admin/cron/rewards_expire.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }

ob_start();
$bcrf_cfg = null;
foreach ([__DIR__ . '/../config.php', __DIR__ . '/../../config.php', __DIR__ . '/../../../config.php'] as $cand) {
    if (is_file($cand)) { $bcrf_cfg = $cand; break; }
}
if (!$bcrf_cfg) { fwrite(STDERR, "rewards_expire: config.php not found\n"); exit(1); }
require_once $bcrf_cfg;
require_once dirname($bcrf_cfg) . '/includes/rewards.php';
if (ob_get_level() > 0) { ob_end_clean(); }
require_once dirname($bcrf_cfg) . '/includes/cron_health.php';
bcrf_cron_begin('rewards_expire');
$_SESSION = ['user_id' => null, 'user_name' => 'Automatic expiry'];

try {
    $on = bcrf_rewards_enabled($pdo);   // nothing expires and no reminders go out while Roost Rewards is OFF
    $r = $on ? bcrf_rewards_send_reminders($pdo) : 0;
    $n = $on ? bcrf_rewards_expire($pdo) : 0;
    echo '[' . date('Y-m-d H:i:s') . "] Roost Rewards: {$r} reminder(s) sent, {$n} balance(s) expired.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'rewards_expire: ' . $e->getMessage() . "\n");
    bcrf_cron_exit(1, $e->getMessage());
}
bcrf_cron_finish();
