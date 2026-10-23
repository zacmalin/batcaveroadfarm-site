<?php
// health.php — uptime check for UptimeRobot (fix pack N).
//
// Healthy:   HTTP 200, body "BCRF-OK"
// Problem:   HTTP 503 (database unreachable or not answering queries)
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
    echo 'BCRF-OK';
} catch (Throwable $e) {
    error_log('[health] ' . $e->getMessage());
    http_response_code(503);
    header('Retry-After: 300');
    echo 'BCRF-DOWN';
}
