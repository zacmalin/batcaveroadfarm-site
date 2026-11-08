<?php
// Parallel worker for the disposable rewards integration database.
// Usage: php tests/rewards_concurrency_worker.php <refund-id> <earning-id>
require_once __DIR__ . '/../includes/roost-refund-processing.php';
if ($argc !== 3 || !ctype_digit($argv[1]) || !ctype_digit($argv[2])) exit(2);
$pdo = new PDO(getenv('BCRF_REWARDS_TEST_DSN'), getenv('BCRF_REWARDS_TEST_USER') ?: '',
    getenv('BCRF_REWARDS_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET SESSION innodb_lock_wait_timeout=15');
try {
    for ($i=0; $i<12; $i++) {
        $result = bcrf_roost_record_zero_point_refund($pdo, (int)$argv[1], (int)$argv[2]);
        if (!$result['replayed'] || $result['points_reversed'] !== 0) {
            throw new RuntimeException('Concurrent refund replay was not idempotent.');
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
