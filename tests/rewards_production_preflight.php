<?php
// Read-only production compatibility preflight. Does not run migrations.
// Set BCRF_REWARDS_PREFLIGHT_DSN, _USER, _PASSWORD locally; never commit credentials.
$dsn = getenv('BCRF_REWARDS_PREFLIGHT_DSN');
if (!$dsn) { fwrite(STDERR, "Missing BCRF_REWARDS_PREFLIGHT_DSN.\n"); exit(2); }
try {
    $pdo = new PDO($dsn, getenv('BCRF_REWARDS_PREFLIGHT_USER') ?: '',
        getenv('BCRF_REWARDS_PREFLIGHT_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $collation = (string)$pdo->query("SHOW COLLATION LIKE 'utf8mb4_0900_ai_ci'")->fetch(PDO::FETCH_ASSOC)['Collation'] ?? '';
    $required = [
        'customers'=>['id'=>'int'],
        'sales'=>['id'=>'bigint','customer_id'=>'int'],
        'refunds'=>['id'=>'bigint','sale_id'=>'bigint'],
        'check_ins'=>['id'=>'bigint','business_date'=>'date'],
    ];
    $stmt = $pdo->prepare("SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $issues = [];
    foreach ($required as $table=>$columns) {
        $stmt->execute([$table]);
        $found = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) $found[$col['COLUMN_NAME']] = $col;
        foreach ($columns as $name=>$type) {
            if (!isset($found[$name])) $issues[] = "$table.$name missing";
            elseif ($found[$name]['DATA_TYPE'] !== $type) $issues[] = "$table.$name type mismatch (expected $type)";
        }
        if (isset($found['id']) && in_array($table,['refunds','check_ins'],true)
            && !str_contains($found['id']['COLUMN_TYPE'],'unsigned')) {
            $issues[] = "$table.id must be UNSIGNED for reward foreign keys";
        }
    }
    if ($collation === '') $issues[] = 'utf8mb4_0900_ai_ci collation unavailable';
    echo "Database engine: $version\n";
    echo "Collation: ".($collation ?: 'unavailable')."\n";
    foreach ($issues as $issue) echo "BLOCKER: $issue\n";
    echo $issues ? "Preflight BLOCKED; no changes made.\n" : "Preflight structural checks passed; no changes made.\n";
    exit($issues ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, "Preflight could not complete: ".$e->getMessage()."\n");
    exit(1);
}
