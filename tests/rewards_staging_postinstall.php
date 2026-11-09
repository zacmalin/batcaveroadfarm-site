<?php
/**
 * Read-only post-install Rewards staging verification.
 * Does not create test fixtures or run the destructive integration suite.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/migrations.php';
$dsn = getenv('BCRF_REWARDS_PREFLIGHT_DSN') ?: '';
if (!preg_match('/(?:^|;)dbname=ddailnmy_bcrf_staging(?:;|$)/', $dsn)) {
    fwrite(STDERR, "BLOCKER: staging-only DSN required\n"); exit(2);
}
try {
    $pdo = new PDO($dsn, getenv('BCRF_REWARDS_PREFLIGHT_USER') ?: '',
        getenv('BCRF_REWARDS_PREFLIGHT_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'ddailnmy_bcrf_staging') throw new RuntimeException('Wrong database');
    $files = bcrf_migrations_files();
    $rows = $pdo->query('SELECT version,name,checksum,baseline FROM schema_migrations ORDER BY version')->fetchAll();
    if (count($rows) !== 25) throw new RuntimeException('Expected 25 migration records; found '.count($rows));
    foreach ($rows as $i=>$row) {
        $version=sprintf('%04d',$i+1);
        if ($row['version']!==$version || !isset($files[$version]) ||
            $row['name']!==$files[$version]['name'] ||
            $row['checksum']!==$files[$version]['checksum'] ||
            (int)$row['baseline']!==($i<15 ? 1 : 0)) {
            throw new RuntimeException("Migration mismatch $version");
        }
    }
    echo "PASS migration history: 15 adopted + 10 applied; all checksums verified\n";
    $expected=[
        'reward_accounts','reward_adult_verifications','reward_consent_events',
        'reward_email_outbox','reward_email_suppressions','reward_household_invitations',
        'reward_household_members','reward_households','reward_invitation_delivery_outbox',
        'reward_ledger','reward_policies','reward_pos_outbox','reward_redemption_benefits',
        'reward_redemption_requests','reward_refund_processing','reward_verified_attendance',
    ];
    $actual=$pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'reward\\_%' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    sort($expected);
    if ($actual!==$expected) throw new RuntimeException('Rewards table inventory differs: '.implode(',',$actual));
    echo "PASS reward tables: 16/16\n";
    $checks=[
        'reward_ledger'=>['uq_reward_idempotency','uq_reward_earning_source'],
        'reward_household_members'=>['uq_one_active_household','uq_household_customer'],
        'reward_redemption_requests'=>['uq_roost_redemption_token','uq_roost_redemption_receipt','uq_roost_redemption_ledger'],
    ];
    $idx=[];
    foreach ($pdo->query("SELECT TABLE_NAME,INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()") as $r) $idx[$r['TABLE_NAME']][$r['INDEX_NAME']]=true;
    foreach ($checks as $table=>$names) foreach ($names as $name) {
        if (!isset($idx[$table][$name])) throw new RuntimeException("Missing index $table.$name");
        echo "PASS index $table.$name\n";
    }
    foreach (['reward_accounts','reward_households','reward_ledger','reward_policies','reward_redemption_requests'] as $table) {
        $count=(int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        echo "DATA $table: $count rows\n";
    }
    echo "SUCCESS read-only Rewards staging schema verification. Behavioral integration and frontend tests still pending.\n";
} catch(Throwable $e) {
    fwrite(STDERR,"BLOCKER: ".$e->getMessage()."\n"); exit(1);
}
