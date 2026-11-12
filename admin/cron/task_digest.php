<?php // admin/cron/task_digest.php
/*
 * Farm OS Phase 3 slice 2 (Nov 11, 2026). Morning list email: each person's late and today's tasks (once per farm day; nobody gets an empty email).
 * Rules: includes/task_notify.php. Safe to run any number of times.
 *
 * Started by the runner (includes/queue.php, daily 6:30 AM farm time), or a cPanel cron:
 *   30 6 * * * /usr/local/bin/php /home2/ddailnmy/public_html/batcaveroadfarm/admin/cron/task_digest.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }

ob_start();
$bcrf_cfg = null;
foreach ([__DIR__ . '/../config.php', __DIR__ . '/../../config.php', __DIR__ . '/../../../config.php'] as $cand) {
    if (is_file($cand)) { $bcrf_cfg = $cand; break; }
}
if (!$bcrf_cfg) { fwrite(STDERR, "task_digest: config.php not found\n"); exit(1); }
require_once $bcrf_cfg;
require_once dirname($bcrf_cfg) . '/includes/task_notify.php';
require_once dirname($bcrf_cfg) . '/admin/includes/email_template.php';
if (ob_get_level() > 0) { ob_end_clean(); }
require_once dirname($bcrf_cfg) . '/includes/cron_health.php';
bcrf_cron_begin('task_digest');
$_SESSION = ['user_id' => null, 'user_name' => 'Task emails'];

try {
    if (!bcrf_tasks_ready($pdo) || !bcrf_tasks_v2($pdo)) { echo "Task emails not installed yet (migration 0030).\n"; bcrf_cron_finish(); exit(0); }
    $r = bcrf_task_digest_run($pdo);
    echo '[' . date('Y-m-d H:i:s') . "] Morning lists: {$r['sent']} sent, {$r['empty']} with nothing due, {$r['failed']} failed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'task_digest: ' . $e->getMessage() . "\n");
    bcrf_cron_exit(1, $e->getMessage());
}
bcrf_cron_finish();
