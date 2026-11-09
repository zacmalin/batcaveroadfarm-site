<?php
/**
 * Staging-only adoption of historical migrations 0001–0015.
 * No historical migration bodies are executed.
 * Requires the separate 81-check audit and seed bootstrap to have passed.
 * Run with --apply after reviewing the dry run.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/migrations.php';

$dsn = getenv('BCRF_REWARDS_PREFLIGHT_DSN') ?: '';
if (!preg_match('/(?:^|;)dbname=ddailnmy_bcrf_staging(?:;|$)/', $dsn)) {
    fwrite(STDERR, "BLOCKER: staging-only DSN required\n"); exit(2);
}
$apply = in_array('--apply', $argv, true);
try {
    $pdo = new PDO($dsn, getenv('BCRF_REWARDS_PREFLIGHT_USER') ?: '',
        getenv('BCRF_REWARDS_PREFLIGHT_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'ddailnmy_bcrf_staging') {
        throw new RuntimeException('Wrong database');
    }
    // This adoption is exclusively for the empty, structure-only copy.
    foreach (['users','customers','inventory','bookings','pass_visits','sales','sale_lines',
        'payments','refunds','tickets','check_ins','inventory_levels','inventory_adjustments'] as $table) {
        if ((int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() !== 0) {
            throw new RuntimeException("$table is not empty; refusing historical adoption");
        }
    }
    $expectedSeeds = [
        'tax_categories' => 3, 'registers' => 1, 'locations' => 4, 'number_sequences' => 1,
    ];
    foreach ($expectedSeeds as $table => $count) {
        $actual = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        if ($actual !== $count) throw new RuntimeException("$table expected $count seed rows; found $actual");
    }
    foreach ([
        "SELECT COUNT(*) FROM tax_categories WHERE (code='general' AND rate_bp=475) OR (code='food_unprepared' AND rate_bp=200) OR (code='exempt' AND rate_bp=0)" => 3,
        "SELECT COUNT(*) FROM registers WHERE name='Farm Stand'" => 1,
        "SELECT COUNT(*) FROM locations WHERE (code='farmstand' AND sellable=1 AND is_default=1) OR (code='thrift' AND sellable=1 AND is_default=0) OR (code='kitchen' AND sellable=0 AND is_default=0) OR (code='feed' AND sellable=0 AND is_default=0)" => 4,
        "SELECT COUNT(*) FROM number_sequences WHERE name='receipt' AND period=YEAR(UTC_TIMESTAMP()) AND next_value=1" => 1,
    ] as $sql => $expected) {
        if ((int)$pdo->query($sql)->fetchColumn() !== $expected) {
            throw new RuntimeException('Seed values mismatch: ' . $sql);
        }
    }
    $files = bcrf_migrations_files();
    $migrations = [];
    foreach (range(1, 15) as $n) {
        $version = sprintf('%04d', $n);
        if (!isset($files[$version]) || !preg_match('/^[a-f0-9]{64}$/', $files[$version]['checksum'])) {
            throw new RuntimeException("Missing/invalid migration $version");
        }
        $migrations[] = $files[$version];
    }
    $rows = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    if ($rows) throw new RuntimeException('schema_migrations is not empty; refusing partial or repeated adoption');
    $rewardTables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'reward\\_%'")->fetchColumn();
    if ($rewardTables !== 0) throw new RuntimeException('Rewards tables already exist');

    // Structural audit must have been run separately: this script does not
    // purport to establish complete equivalence of historical schema or data.
    echo "READY: staging-only historical adoption; 15 files with SHA-256 checksums; seeds verified.\n";
    foreach ($migrations as $m) echo $m['version'] . ' ' . $m['name'] . ' ' . $m['checksum'] . "\n";
    if (!$apply) {
        echo "DRY RUN ONLY. Run the 81-check structural audit first, then --apply.\n";
        exit(0);
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("INSERT INTO schema_migrations (version,name,checksum,baseline,applied_at,applied_by,duration_ms) VALUES (?,?,?,1,UTC_TIMESTAMP(),NULL,0)");
        foreach ($migrations as $m) {
            $st->execute([$m['version'], $m['name'], $m['checksum']]);
        }
        $recorded = $pdo->query("SELECT version,name,checksum,baseline FROM schema_migrations ORDER BY version")->fetchAll();
        if (count($recorded) !== 15) throw new RuntimeException('Expected exactly 15 adopted records');
        foreach ($recorded as $i => $r) {
            $m = $migrations[$i];
            if ($r['version'] !== $m['version'] || $r['name'] !== $m['name'] ||
                $r['checksum'] !== $m['checksum'] || (int)$r['baseline'] !== 1) {
                throw new RuntimeException('Recorded checksum or baseline mismatch: ' . $m['version']);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    echo "SUCCESS: 0001–0015 recorded as adopted (baseline=1); no migration bodies executed.\n";
    echo "NEXT: validate Rewards 0016–0025 separately; do not deploy production.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'BLOCKER: ' . $e->getMessage() . "\n"); exit(1);
}
