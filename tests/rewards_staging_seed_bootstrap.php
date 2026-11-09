<?php
/**
 * Seed the structure-only Farm Rewards staging database.
 * Explicitly staging-only; does NOT adopt or execute migrations.
 * Idempotent for the expected seed records.
 */
declare(strict_types=1);
$dsn = getenv('BCRF_REWARDS_PREFLIGHT_DSN') ?: '';
if (!preg_match('/(?:^|;)dbname=ddailnmy_bcrf_staging(?:;|$)/', $dsn)) {
    fwrite(STDERR, "BLOCKER: staging DSN required\n"); exit(2);
}
if (!in_array('--apply', $argv, true)) {
    echo "DRY RUN ONLY. Add --apply to insert staging seed records. No migrations will be adopted.\n";
}
$apply = in_array('--apply', $argv, true);
try {
    $pdo = new PDO($dsn, getenv('BCRF_REWARDS_PREFLIGHT_USER') ?: '',
        getenv('BCRF_REWARDS_PREFLIGHT_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'ddailnmy_bcrf_staging') {
        throw new RuntimeException('Wrong database');
    }
    $requiredEmpty = ['schema_migrations','users','customers','inventory','bookings','pass_visits','sales','refunds','tickets','check_ins'];
    foreach ($requiredEmpty as $table) {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        if ($count !== 0) throw new RuntimeException("$table has $count rows; refusing structure-only bootstrap");
    }
    $seeds = [
        'tax_categories' => [
            "INSERT IGNORE INTO tax_categories (code,name,rate_bp,notes,sort_order,created_at) VALUES ('general','General merchandise',475,'Copied from the register (Nov 2026). Confirm the combined state + county rate with NCDOR.',1,UTC_TIMESTAMP()),('food_unprepared','Unprepared food',200,'Copied from the register (Nov 2026): Produce, Bakery, Pantry.',2,UTC_TIMESTAMP()),('exempt','Exempt',0,NULL,9,UTC_TIMESTAMP())",
            "SELECT COUNT(*) FROM tax_categories WHERE code IN ('general','food_unprepared','exempt')",
            3
        ],
        'registers' => [
            "INSERT IGNORE INTO registers (name,sort_order,created_at) VALUES ('Farm Stand',1,UTC_TIMESTAMP())",
            "SELECT COUNT(*) FROM registers WHERE name = 'Farm Stand'",
            1
        ],
        'locations' => [
            "INSERT IGNORE INTO locations (code,name,sellable,is_default,sort_order,created_at) VALUES ('farmstand','Farmstand',1,1,1,UTC_TIMESTAMP()),('thrift','Thrift Shop',1,0,2,UTC_TIMESTAMP()),('kitchen','Kitchen',0,0,3,UTC_TIMESTAMP()),('feed','Feed Room',0,0,4,UTC_TIMESTAMP())",
            "SELECT COUNT(*) FROM locations WHERE code IN ('farmstand','thrift','kitchen','feed')",
            4
        ],
        'number_sequences' => [
            "INSERT IGNORE INTO number_sequences (name,period,next_value) VALUES ('receipt',YEAR(UTC_TIMESTAMP()),1)",
            "SELECT COUNT(*) FROM number_sequences WHERE name='receipt' AND period=YEAR(UTC_TIMESTAMP())",
            1
        ]
    ];
    foreach ($seeds as $table => [$sql,$verify,$expected]) {
        $existing = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        if ($existing !== 0) throw new RuntimeException("$table already contains $existing rows; refusing to alter existing configuration");
        echo "READY $table: expected $expected seed records\n";
    }
    if (!$apply) {
        echo "Dry run passed. Rerun with --apply to seed staging only.\n"; exit(0);
    }
    $pdo->beginTransaction();
    try {
        foreach ($seeds as $table => [$sql,$verify,$expected]) {
            $pdo->exec($sql);
            $count = (int)$pdo->query($verify)->fetchColumn();
            if ($count !== $expected) throw new RuntimeException("$table verification failed: expected $expected, got $count");
            echo "SEEDED $table: $count records verified\n";
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    echo "Staging seed bootstrap complete. Historical migrations remain unrecorded; Rewards migrations remain pending.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'BLOCKER: ' . $e->getMessage() . "\n"); exit(1);
}
