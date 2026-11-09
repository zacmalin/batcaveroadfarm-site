<?php
// CLI-only, bounded Rewards POS queue worker. No cron is installed by this file.
// Requires explicit, separately approved dispatch activation and CLI execution.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (getenv('BCRF_ROOST_POS_WORKER_CLI_APPROVED') !== '1') {
    fwrite(STDERR, "Rewards POS worker is disabled.\n");
    exit(2);
}
define('BCRF_NO_SESSION', true);
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/roost-pos-dispatch-runner.php';
if (!bcrf_roost_outbox_dispatch_enabled()) {
    fwrite(STDERR, "Rewards POS dispatch is disabled.\n");
    exit(2);
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(2);
}
$limit = filter_var($argv[1] ?? '10', FILTER_VALIDATE_INT,
    ['options'=>['min_range'=>1,'max_range'=>25]]);
if ($limit === false) {
    fwrite(STDERR, "Usage: php rewards_pos_worker.php [1..25]\n");
    exit(2);
}
$completed=0; $failed=0;
for ($i=0; $i<$limit; $i++) {
    try {
        $result=bcrf_roost_pos_dispatch_once($pdo);
    } catch (Throwable $e) {
        error_log('[Roost POS worker error] '.get_class($e));
        fwrite(STDERR, "Rewards POS worker interrupted.\n");
        exit(1);
    }
    if ($result==='empty') break;
    if ($result==='disabled') {
        fwrite(STDERR, "Rewards POS dispatch was disabled.\n");
        exit(2);
    }
    if ($result==='completed') $completed++;
    elseif ($result==='failed') $failed++;
    else {
        fwrite(STDERR, "Unexpected Rewards POS worker outcome.\n");
        exit(1);
    }
}
echo "Rewards POS worker: completed=$completed failed=$failed\n";
exit($failed > 0 ? 1 : 0);
