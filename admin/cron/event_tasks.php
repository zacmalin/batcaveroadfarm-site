<?php // admin/cron/event_tasks.php
/*
 * Farm OS Phase 3 slice 3 (Nov 11, 2026). Event checklists: tasks for confirmed parties and bookings; cancels them when an event is cancelled.
 * Rules: includes/event_tasks.php. Safe to run any number of times.
 *
 * Started by the runner (includes/queue.php, every 15 minutes), or a cPanel cron:
 *   5,20,35,50 * * * * /usr/local/bin/php /home2/ddailnmy/public_html/batcaveroadfarm/admin/cron/event_tasks.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }

ob_start();
$bcrf_cfg = null;
foreach ([__DIR__ . '/../config.php', __DIR__ . '/../../config.php', __DIR__ . '/../../../config.php'] as $cand) {
    if (is_file($cand)) { $bcrf_cfg = $cand; break; }
}
if (!$bcrf_cfg) { fwrite(STDERR, "event_tasks: config.php not found\n"); exit(1); }
require_once $bcrf_cfg;
require_once dirname($bcrf_cfg) . '/includes/event_tasks.php';
if (ob_get_level() > 0) { ob_end_clean(); }
require_once dirname($bcrf_cfg) . '/includes/cron_health.php';
bcrf_cron_begin('event_tasks');
$_SESSION = ['user_id' => null, 'user_name' => 'Event checklists'];

try {
    if (!bcrf_event_tasks_ready($pdo)) { echo "Event checklists not installed yet (migration 0031).\n"; bcrf_cron_finish(); exit(0); }
    $r = bcrf_event_tasks_sync($pdo);
    echo '[' . date('Y-m-d H:i:s') . "] Event checklists: {$r['made']} made, {$r['moved']} moved, {$r['cancelled']} cancelled.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'event_tasks: ' . $e->getMessage() . "\n");
    bcrf_cron_exit(1, $e->getMessage());
}
bcrf_cron_finish();
