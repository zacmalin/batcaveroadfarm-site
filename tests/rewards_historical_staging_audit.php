<?php
/**
 * Historical migration staging audit (read-only).
 * Does not execute or adopt migrations. Never connect to production.
 *
 * Uses BCRF_REWARDS_PREFLIGHT_DSN, _USER, _PASSWORD.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/migrations.php';

$dsn = getenv('BCRF_REWARDS_PREFLIGHT_DSN') ?: '';
if (!preg_match('/(?:^|;)dbname=ddailnmy_bcrf_staging(?:;|$)/', $dsn)) {
    fwrite(STDERR, "BLOCKER: staging DSN required\n"); exit(2);
}
try {
    $pdo = new PDO($dsn, getenv('BCRF_REWARDS_PREFLIGHT_USER') ?: '',
        getenv('BCRF_REWARDS_PREFLIGHT_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'ddailnmy_bcrf_staging') {
        throw new RuntimeException('Wrong database');
    }
    $files = bcrf_migrations_files();
    $failures = [];
    $checks = 0;
    $check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
        $checks++;
        echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
        if (!$ok) $failures[] = $label;
    };
    $schema = 'ddailnmy_bcrf_staging';
    $tables = [];
    foreach ($pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'ddailnmy_bcrf_staging'") as $row) {
        $tables[$row['TABLE_NAME']] = true;
    }
    $required = [
        'audit_events','domain_events','queue_jobs','customers','social_queue_targets',
        'pinterest_batches','pinterest_auth','pinterest_boards','tax_categories',
        'number_sequences','sales','sale_lines','payments','inventory_adjustments',
        'registers','drawer_sessions','cash_movements','refunds','refund_lines',
        'refund_payments','ticket_types','tickets','check_ins','sale_holds',
        'locations','inventory_levels','inventory_counts','inventory_count_lines',
    ];
    foreach ($required as $table) $check(isset($tables[$table]), "table $table");
    $columns = [];
    foreach ($pdo->query("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'ddailnmy_bcrf_staging'") as $row) {
        $columns[$row['TABLE_NAME']][$row['COLUMN_NAME']] = strtolower($row['COLUMN_TYPE']);
    }
    $neededColumns = [
        'users' => ['totp_secret_enc','totp_enabled_at','totp_last_step','mfa_recovery_json'],
        'password_resets' => ['realm'],
        'inventory' => ['product_type','pricing_mode','unit','tax_category_id','track_inventory'],
        'sales' => ['drawer_session_id','location_id','refunded_cents','voided_at','voided_by','void_reason'],
        'sale_lines' => ['line_discount_bp','refunded_quantity','refunded_net_cents','refunded_tax_cents'],
        'tickets' => ['payment_status','order_json','stripe_session_id','paid_at','confirmation_sent_at','ga_client_id','ga_session_id','ga_purchase_sent'],
    ];
    foreach ($neededColumns as $table => $cols) {
        foreach ($cols as $column) $check(isset($columns[$table][$column]), "column $table.$column");
    }
    foreach (['recipes','ingredients'] as $table) {
        $check(!isset($columns[$table]['pairs_with_recipe_id']), "obsolete column removed: $table.pairs_with_recipe_id");
    }
    $indexes = [];
    foreach ($pdo->query("SELECT TABLE_NAME,INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = 'ddailnmy_bcrf_staging' ORDER BY SEQ_IN_INDEX") as $r) {
        $indexes[$r['TABLE_NAME']][$r['INDEX_NAME']][(int)$r['SEQ_IN_INDEX']] = $r['COLUMN_NAME'];
    }
    foreach ([
        ['sales','idx_drawer',['drawer_session_id']],
        ['tickets','idx_stripe',['stripe_session_id']],
        ['inventory_adjustments','idx_location',['location_id','created_at']],
    ] as [$table,$index,$cols]) {
        $actual = array_values($indexes[$table][$index] ?? []);
        $check(array_slice($actual,0,count($cols)) === $cols, "index $table.$index");
    }
    $foreignKeys = [];
    foreach ($pdo->query("SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = 'ddailnmy_bcrf_staging' AND REFERENCED_TABLE_NAME IS NOT NULL") as $r) {
        $foreignKeys[$r['TABLE_NAME']][$r['CONSTRAINT_NAME']][] = $r;
    }
    foreach ([
        ['sale_lines','fk_sl_sale','sale_id','sales','id'],
        ['refunds','fk_rf_sale','sale_id','sales','id'],
        ['refund_lines','fk_rl_refund','refund_id','refunds','id'],
    ] as [$table,$key,$column,$target,$targetColumn]) {
        $matches = array_filter($foreignKeys[$table][$key] ?? [],
            static fn($r) => $r['COLUMN_NAME']===$column && $r['REFERENCED_TABLE_NAME']===$target && $r['REFERENCED_COLUMN_NAME']===$targetColumn);
        $check(count($matches) === 1, "foreign key $table.$key");
    }
    foreach (range(1,15) as $n) {
        $v = sprintf('%04d',$n);
        $check(isset($files[$v]) && is_file($files[$v]['file']), "migration file $v");
    }
    $rows = $pdo->query('SELECT version,name,checksum,baseline FROM schema_migrations')->fetchAll();
    $check(count($rows) === 0, 'historical migrations unrecorded (expected before adoption)');
    $rewardTables = array_filter(array_keys($tables), static fn($t) => str_starts_with($t,'reward_'));
    $check(count($rewardTables) === 0, 'reward tables absent (expected before installation)');
    foreach (['tax_categories','registers','locations','number_sequences','inventory','customers','pass_visits','bookings','users'] as $table) {
        if (!isset($tables[$table])) { echo "DATA $table: missing table\n"; continue; }
        $count = (int)$pdo->query('SELECT COUNT(*) FROM `' . str_replace('`','``',$table) . '`')->fetchColumn();
        echo "DATA $table: $count rows\n";
    }
    echo "\n$checks structural checks; " . count($failures) . " failures.\n";
    echo "IMPORTANT: structural checks do not prove historical data transformations, seed values, or full baseline equivalence.\n";
    echo "No migrations executed or adopted; no data modified.\n";
    exit($failures ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'BLOCKER: ' . $e->getMessage() . "\n"); exit(1);
}
