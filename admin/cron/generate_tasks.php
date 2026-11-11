<?php // admin/cron/generate_tasks.php
/*
 * Farm OS Phase 3 tasks (Nov 11, 2026): create each chore's tasks for the days ahead (usually 7). Rules: includes/tasks.php.
 * Safe to run any number of times: one task per chore per day. Past days are never filled in.
 * Tasks also does this when someone opens the Tasks screen, so a missed night only matters if nobody looks.
 *
 * Started by the runner (includes/queue.php, daily 2:30 AM farm time), or a cPanel cron:
 *   30 2 * * * /usr/local/bin/php /home2/ddailnmy/public_html/batcaveroadfarm/admin/cron/generate_tasks.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }

ob_start();
$bcrf_cfg = null;
foreach ([__DIR__ . '/../config.php', __DIR__ . '/../../config.php', __DIR__ . '/../../../config.php'] as $cand) {
    if (is_file($cand)) { $bcrf_cfg = $cand; break; }
}
if (!$bcrf_cfg) { fwrite(STDERR, "generate_tasks: config.php not found\n"); exit(1); }
require_once $bcrf_cfg;
require_once dirname($bcrf_cfg) . '/includes/tasks.php';
if (ob_get_level() > 0) { ob_end_clean(); }
require_once dirname($bcrf_cfg) . '/includes/cron_health.php';
bcrf_cron_begin('generate_tasks');
$_SESSION = ['user_id' => null, 'user_name' => 'Chore schedule'];

try {
    if (!bcrf_tasks_ready($pdo)) { echo "Tasks not installed yet (migration 0029).\n"; bcrf_cron_finish(); exit(0); }
    $n = bcrf_tasks_generate($pdo);
    echo '[' . date('Y-m-d H:i:s') . "] Chores: {$n} task(s) created.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'generate_tasks: ' . $e->getMessage() . "\n");
    bcrf_cron_exit(1, $e->getMessage());
}
bcrf_cron_finish();
