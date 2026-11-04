<?php
// health.php — uptime check for UptimeRobot (fix pack N).
//
// Healthy:   HTTP 200, body "BCRF-OK"
// Problem:   HTTP 503 (database unreachable or not answering queries)
// health.php?jobs=1 also answers 503 if a cron job is late or failing (includes/cron_health.php).
//
// Proves PHP runs, the secrets file loads, MySQL accepts the login, and a real
// table can be read. Shows no details (those go to the PHP error log). Not
// cached, not indexed, and no session file is created per check.
define('BCRF_NO_SESSION', true);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/config.php';   // answers 503 itself if MySQL refuses the connection

try {
    $pdo->query('SELECT id FROM users LIMIT 1')->fetchAll();
    // ?jobs=1 (second UptimeRobot monitor): also 503 when a scheduled job is late or failing.
    // Names only, no details (those are on /admin/system_health.php).
    if (isset($_GET['jobs'])) {
        require_once __DIR__ . '/includes/cron_health.php';
        $bad = bcrf_cron_problems($pdo);
        if ($bad) {
            http_response_code(503);
            echo 'BCRF-JOBS-LATE: ' . implode(', ', array_column($bad, 'job'));
            exit;
        }
    }
    echo 'BCRF-OK';
} catch (Throwable $e) {
    error_log('[health] ' . $e->getMessage());
    http_response_code(503);
    header('Retry-After: 300');
    echo 'BCRF-DOWN';
}
