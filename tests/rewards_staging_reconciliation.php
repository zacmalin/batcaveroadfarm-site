<?php
/**
 * Roost Rewards staging reconciliation — READ ONLY.
 * Run from CLI with BCRF_REWARDS_PREFLIGHT_DSN, _USER, _PASSWORD.
 * Never points at production. Does not adopt or execute migrations.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/migrations.php';
$dsn = getenv('BCRF_REWARDS_PREFLIGHT_DSN') ?: '';
if (!preg_match('/(?:^|;)dbname=ddailnmy_bcrf_staging(?:;|$)/', $dsn)) {
    fwrite(STDERR, "Refusing: DSN must explicitly name ddailnmy_bcrf_staging.\n");
    exit(2);
}
try {
    $pdo = new PDO($dsn, getenv('BCRF_REWARDS_PREFLIGHT_USER') ?: '',
        getenv('BCRF_REWARDS_PREFLIGHT_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $actual = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($actual !== 'ddailnmy_bcrf_staging') throw new RuntimeException('Connected to wrong schema');
    $files = bcrf_migrations_files();
    $rows = $pdo->query('SELECT version,name,checksum,baseline FROM schema_migrations ORDER BY version')->fetchAll();
    $known = array_column($rows, null, 'version');
    $errors = [];
    foreach ($files as $v => $f) {
        if ($v > '0025') continue;
        $record = $known[$v] ?? null;
        $state = $record ? ($record['checksum'] === $f['checksum'] ? 'recorded' : 'CHECKSUM MISMATCH') : 'unrecorded';
        printf("%s %-42s %s\n", $v, $f['name'], $state);
        if ($record && $record['checksum'] !== $f['checksum']) $errors[] = "Checksum mismatch $v";
    }
    foreach (range(1, 25) as $n) {
        $v = sprintf('%04d', $n);
        if (!isset($files[$v])) $errors[] = "Migration file missing: $v";
    }
    $expected = ['customers','sales','refunds','check_ins'];
    $q = $pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
    foreach (['customers'=>['id'=>'int'], 'sales'=>['id'=>'bigint unsigned','customer_id'=>'int'],
        'refunds'=>['id'=>'bigint unsigned','sale_id'=>'bigint unsigned'],
        'check_ins'=>['id'=>'bigint unsigned','business_date'=>'date']] as $table=>$cols) {
        $q->execute([$actual,$table]);
        $got = array_column($q->fetchAll(), 'COLUMN_TYPE', 'COLUMN_NAME');
        foreach ($cols as $col=>$type) {
            if (!isset($got[$col]) || !str_starts_with(strtolower($got[$col]), $type))
                $errors[] = "$table.$col incompatible or absent";
        }
    }
    $rewardCount = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='ddailnmy_bcrf_staging' AND TABLE_NAME LIKE 'reward\\_%'")->fetchColumn();
    if ($rewardCount) $errors[] = "$rewardCount existing reward_ tables: reconcile before applying";
    $tableCount = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='ddailnmy_bcrf_staging' AND TABLE_TYPE='BASE TABLE'")->fetchColumn();
    printf("Staging tables: %d; existing reward_ tables: %d; recorded migrations: %d\n", $tableCount, $rewardCount, count($rows));
    if ($tableCount !== 107 && count($rows) === 0) $errors[] = 'Unrecorded baseline no longer matches imported 107-table checkpoint';
    if ($errors) {
        foreach ($errors as $error) fwrite(STDERR, "BLOCKER: $error\n");
        exit(1);
    }
    echo "Read-only structural checks passed. Earlier migrations 0001-0015 are NOT automatically adopted; manual schema reconciliation is still required.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'BLOCKER: '.$e->getMessage()."\n");
    exit(1);
}
