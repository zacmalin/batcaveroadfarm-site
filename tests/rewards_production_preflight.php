<?php
// Read-only production compatibility preflight. Does not run migrations.
// Set BCRF_REWARDS_PREFLIGHT_DSN, _USER, _PASSWORD locally; never commit credentials.
$dsn = getenv('BCRF_REWARDS_PREFLIGHT_DSN');
if (!$dsn) { fwrite(STDERR, "Missing BCRF_REWARDS_PREFLIGHT_DSN.\n"); exit(2); }
try {
    $pdo = new PDO($dsn, getenv('BCRF_REWARDS_PREFLIGHT_USER') ?: '',
        getenv('BCRF_REWARDS_PREFLIGHT_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $schema = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($schema === '' || strtolower($schema) === 'information_schema') {
        throw new RuntimeException('Select the application database explicitly in the DSN, not information_schema.');
    }
    $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $collationRow = $pdo->query("SHOW COLLATION LIKE 'utf8mb4_0900_ai_ci'")->fetch(PDO::FETCH_ASSOC);
    $collation = $collationRow ? (string)$collationRow['Collation'] : '';
    $required = [
        'customers'=>['id'=>'int'],
        'sales'=>['id'=>'bigint','customer_id'=>'int'],
        'refunds'=>['id'=>'bigint','sale_id'=>'bigint'],
        'check_ins'=>['id'=>'bigint','business_date'=>'date'],
    ];
    $stmt = $pdo->prepare("SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
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
        if (isset($found['id']) && in_array($table,['sales','refunds','check_ins'],true)
            && !str_contains($found['id']['COLUMN_TYPE'],'unsigned')) {
            $issues[] = "$table.id must be UNSIGNED for reward foreign keys";
        }
    }
    $tableStmt = $pdo->prepare("SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    foreach (array_keys($required) as $table) {
        $tableStmt->execute([$table]);
        $details = $tableStmt->fetch(PDO::FETCH_ASSOC);
        if (!$details) {
            $issues[] = "$table missing";
        } elseif (strtoupper((string)$details['ENGINE']) !== 'INNODB') {
            $issues[] = "$table engine must be InnoDB for foreign keys";
        }
    }
    // Detect an existing outbox from an earlier migration revision before
    // attempting any release that assumes immutable policy snapshots.
    $tableStmt->execute(['reward_pos_outbox']);
    $existingOutbox=$tableStmt->fetch(PDO::FETCH_ASSOC);
    if ($existingOutbox) {
        $stmt->execute(['reward_pos_outbox']);
        $outboxColumns=[];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col)
            $outboxColumns[$col['COLUMN_NAME']]=$col;
        if (!isset($outboxColumns['policy_id']) ||
            $outboxColumns['policy_id']['DATA_TYPE']!=='bigint' ||
            !str_contains($outboxColumns['policy_id']['COLUMN_TYPE'],'unsigned'))
            $issues[]='Existing reward_pos_outbox lacks BIGINT UNSIGNED policy_id; CREATE TABLE IF NOT EXISTS will not upgrade it';
        if (strtoupper((string)$existingOutbox['ENGINE'])!=='INNODB')
            $issues[]='Existing reward_pos_outbox must use InnoDB';
        if (isset($outboxColumns['policy_id']) &&
            strtoupper((string)($outboxColumns['policy_id']['IS_NULLABLE']??''))!=='YES')
            $issues[]='Existing reward_pos_outbox.policy_id must be nullable for refund events';
        $indexes=$pdo->query("SELECT INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX,NON_UNIQUE
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reward_pos_outbox'
            ORDER BY INDEX_NAME,SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
        $byIndex=[];
        foreach ($indexes as $index) {
            $name=$index['INDEX_NAME'];
            $byIndex[$name]['unique']=((int)$index['NON_UNIQUE']===0);
            $byIndex[$name]['columns'][]=$index['COLUMN_NAME'];
        }
        $hasEventUniqueness=false;
        foreach ($byIndex as $index)
            if ($index['unique'] && $index['columns']===['event_type','source_id'])
                $hasEventUniqueness=true;
        if (!$hasEventUniqueness)
            $issues[]='Existing reward_pos_outbox lacks unique event_type/source_id constraint';
        if (isset($outboxColumns['policy_id'], $outboxColumns['event_type'])) {
            $unresolved=(int)$pdo->query("SELECT COUNT(*) FROM reward_pos_outbox
                WHERE event_type='sale.completed' AND policy_id IS NULL
                  AND status IN ('pending','processing','failed')")->fetchColumn();
            if ($unresolved>0)
                $issues[]="Existing outbox has $unresolved unresolved sale events without pinned policy; manual reconciliation required";
        }
    }
    if (stripos($version, 'MariaDB') !== false) {
        $issues[] = 'MariaDB detected: migration SQL is MySQL 8-specific; verify separately before deployment';
    }
    if ($collation === '') $issues[] = 'utf8mb4_0900_ai_ci collation unavailable';
    echo "Selected database: $schema\n";
    echo "Database engine: $version\n";
    echo "Collation: ".($collation ?: 'unavailable')."\n";
    foreach ($issues as $issue) echo "BLOCKER: $issue\n";
    echo $issues ? "Preflight BLOCKED; no changes made.\n" : "Preflight structural checks passed; no changes made.\n";
    exit($issues ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, "Preflight could not complete: ".$e->getMessage()."\n");
    exit(1);
}
