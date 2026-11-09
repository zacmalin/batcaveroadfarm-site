<?php
/**
 * One-command Rewards staging installer / verifier.
 * Dry run by default; --apply executes ONLY pending 0016–0025.
 * Never accesses production, never changes the production checkout.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/migrations.php';
$dsn = getenv('BCRF_REWARDS_PREFLIGHT_DSN') ?: '';
if (!preg_match('/(?:^|;)dbname=ddailnmy_bcrf_staging(?:;|$)/', $dsn)) {
    fwrite(STDERR, "BLOCKER: exact staging DSN required\n"); exit(2);
}
$apply = in_array('--apply', $argv, true);
try {
    $pdo = new PDO($dsn, getenv('BCRF_REWARDS_PREFLIGHT_USER') ?: '',
        getenv('BCRF_REWARDS_PREFLIGHT_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'ddailnmy_bcrf_staging') {
        throw new RuntimeException('Database name mismatch');
    }
    $files = bcrf_migrations_files();
    $done = bcrf_migrations_applied($pdo);
    if (count($done) < 15) throw new RuntimeException('Historical adoption incomplete');
    foreach (range(1, 15) as $n) {
        $v = sprintf('%04d', $n);
        if (!isset($files[$v], $done[$v]) || $done[$v]['checksum'] !== $files[$v]['checksum'] ||
            $done[$v]['name'] !== $files[$v]['name'] || (int)$done[$v]['baseline'] !== 1) {
            throw new RuntimeException("Historical migration $v missing or checksum mismatch");
        }
    }
    foreach ($done as $v => $r) {
        if (!isset($files[$v]) || $files[$v]['checksum'] !== $r['checksum']) {
            throw new RuntimeException("Unexpected/changed migration record $v");
        }
        if ($v > '0025') throw new RuntimeException("Unexpected newer migration record $v");
    }
    $pending = [];
    foreach (range(16,25) as $n) {
        $v = sprintf('%04d', $n);
        if (!isset($files[$v])) throw new RuntimeException("Missing Rewards migration $v");
        if (isset($done[$v])) {
            if ((int)$done[$v]['baseline'] !== 0 || $done[$v]['name'] !== $files[$v]['name']) {
                throw new RuntimeException("Rewards migration $v has unexpected recorded status");
            }
            echo "ALREADY APPLIED $v {$files[$v]['name']}\n";
        } else {
            $pending[] = $files[$v];
            echo "PENDING $v {$files[$v]['name']}\n";
        }
    }
    if (!$apply) {
        echo "DRY RUN: " . count($pending) . " Rewards migrations pending; no changes made.\n";
        echo "WARNING: MySQL DDL is not transactionally reversible. Review a staging backup before --apply.\n";
        exit(0);
    }
    foreach ($pending as $m) {
        [$ok, $message] = bcrf_migration_apply($pdo, $m, null);
        echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n";
        if (!$ok) {
            throw new RuntimeException("Stopped after migration failure. DDL may have partially applied; inspect before rerunning.");
        }
    }
    $recorded = bcrf_migrations_applied($pdo);
    foreach (range(16,25) as $n) {
        $v = sprintf('%04d', $n);
        if (!isset($recorded[$v]) || $recorded[$v]['checksum'] !== $files[$v]['checksum'] ||
            (int)$recorded[$v]['baseline'] !== 0) throw new RuntimeException("Post-install verification failed for $v");
    }
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'reward\\_%' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    echo "REWARDS TABLES (" . count($tables) . "): " . implode(', ', $tables) . "\n";
    echo "SUCCESS: all ten Rewards migrations recorded and checksums verified in staging.\n";
    echo "Rewards activation and production deployment remain disabled/pending.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'BLOCKER: ' . $e->getMessage() . "\n"); exit(1);
}
