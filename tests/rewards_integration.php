<?php
// Run only against a disposable MySQL 8 database:
// BCRF_REWARDS_TEST_DSN='mysql:host=127.0.0.1;dbname=...' BCRF_REWARDS_TEST_USER=... php tests/rewards_integration.php
$dsn = getenv('BCRF_REWARDS_TEST_DSN');
if (!$dsn) { fwrite(STDERR, "Set BCRF_REWARDS_TEST_DSN for a disposable database.\n"); exit(2); }
$pdo = new PDO($dsn, getenv('BCRF_REWARDS_TEST_USER') ?: '', getenv('BCRF_REWARDS_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE customers (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB");
$pdo->exec("INSERT INTO customers VALUES (1),(2)");
$schema = require __DIR__ . '/../admin/migrations/0016_farm_rewards_foundation.php';
$schema($pdo);
$schema($pdo); // rerunnable
$pdo->exec("INSERT INTO reward_households (id, status, created_at) VALUES (1,'active',UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO reward_accounts (household_id, updated_at) VALUES (1,UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO reward_household_members (household_id,customer_id,role,verification_status,can_redeem,joined_at) VALUES (1,1,'manager','verified',1,UTC_TIMESTAMP(3)),(1,2,'adult','verified',1,UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO reward_policies (id,version,config_json,status,created_at) VALUES (1,'test-1','{}','active',UTC_TIMESTAMP(3))");
require __DIR__ . '/../includes/rewards.php';
function expect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejected(callable $run, string $message): void { try { $run(); } catch (BcrfRewardError $e) { return; } throw new RuntimeException('Expected rejection: '.$message); }
$base = ['household_id'=>1,'policy_id'=>1,'customer_id'=>1,'source_type'=>'sale','source_id'=>'100','entry_type'=>'earn_spend','points_delta'=>200,'idempotency_key'=>'test-sale-100'];
$a = bcrf_reward_post($pdo,$base);
expect($a['balance']===200 && !$a['replayed'],'first award');
$b = bcrf_reward_post($pdo,$base);
expect($b['replayed'] && $b['balance']===200,'idempotent replay');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['points_delta'=>300])),'conflicting replay');
$v = bcrf_reward_post($pdo,array_merge($base,['source_type'=>'visit','source_id'=>'person-1:2026-11-08','entry_type'=>'earn_visit','points_delta'=>25,'idempotency_key'=>'test-visit-1']));
expect($v['balance']===225,'visit award');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'redeem','points_delta'=>-250,'idempotency_key'=>'test-redeem-too-much'])),'overspend');
$d = bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'redeem','points_delta'=>-200,'source_type'=>'reward','source_id'=>'r1','idempotency_key'=>'test-redeem-1','customer_id'=>2]));
expect($d['balance']===25,'shared adult redemption');
$pdo->exec("UPDATE reward_household_members SET revoked_at=UTC_TIMESTAMP(3) WHERE customer_id=2");
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'redeem','points_delta'=>-10,'idempotency_key'=>'test-revoked','customer_id'=>2])),'revoked adult');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'earn_spend','points_delta'=>25,'source_id'=>'revoked-earn','idempotency_key'=>'test-revoked-earn','customer_id'=>2])),'revoked customer cannot earn');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'earn_visit','points_delta'=>25,'source_id'=>'revoked-visit','idempotency_key'=>'test-revoked-visit','customer_id'=>2])),'revoked customer cannot receive visit points');
$refund = bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'refund_reversal','points_delta'=>-200,'source_type'=>'refund','source_id'=>'refund-1','idempotency_key'=>'test-refund-1','related_entry_id'=>$a['id']]));
expect($refund['balance']===-175,'refund negative balance');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'refund_reversal','points_delta'=>-1,'source_type'=>'refund','source_id'=>'refund-2','idempotency_key'=>'test-refund-2','related_entry_id'=>$a['id']])),'over reversal');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'redeem','points_delta'=>-1,'idempotency_key'=>'test-negative-redeem'])),'negative balance redeem');
// Race two independent database sessions against the same 100-point balance.
// Each child starts together and attempts a 100-point redemption. Exactly one succeeds.
// Skip the concurrency assertion only when CLI pcntl is unavailable (CI installs it).
if (function_exists('pcntl_fork') && function_exists('stream_socket_pair')) {
    $topup = bcrf_reward_post($pdo,array_merge($base,['source_type'=>'sale','source_id'=>'topup','points_delta'=>275,'idempotency_key'=>'test-topup']));
    expect($topup['balance']===100,'race starting balance');
    $pipes = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($pipes === false) throw new RuntimeException('Cannot create race synchronization pipes.');
    $children = [];
    for ($i=0; $i<2; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('Cannot fork concurrent reward test.');
        if ($pid === 0) {
            fclose($pipes[1]);
            fread($pipes[0],1); // both children wait for parent release
            fclose($pipes[0]);
            try {
                // Never share a fork-inherited PDO connection.
                $db = new PDO($dsn, getenv('BCRF_REWARDS_TEST_USER') ?: '', getenv('BCRF_REWARDS_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
                bcrf_reward_post($db,array_merge($base,['entry_type'=>'redeem','points_delta'=>-100,'source_type'=>'reward','source_id'=>'race-'.($i+1),'idempotency_key'=>'test-race-'.($i+1)]));
                exit(0);
            } catch (BcrfRewardError $e) { exit(3); }
              catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\\n"); exit(4); }
        }
        $children[] = $pid;
    }
    fclose($pipes[0]);
    fwrite($pipes[1],"XX");
    fclose($pipes[1]);
    $success=0; $insufficient=0;
    foreach ($children as $pid) {
        pcntl_waitpid($pid,$status);
        if (!pcntl_wifexited($status)) throw new RuntimeException('Race child failed.');
        $code=pcntl_wexitstatus($status);
        if ($code===0) $success++;
        elseif ($code===3) $insufficient++;
        else throw new RuntimeException('Race child error code '.$code);
    }
    expect($success===1 && $insufficient===1,'exactly one concurrent redemption succeeds');
    // Forked children inherit the parent's MySQL socket; child shutdown can close it.
    // Reconnect in the parent before asserting the final persisted balance.
    $pdo = new PDO($dsn, getenv('BCRF_REWARDS_TEST_USER') ?: '', getenv('BCRF_REWARDS_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    expect((int)$pdo->query("SELECT balance_points FROM reward_accounts WHERE household_id=1")->fetchColumn()===0,'concurrent balance never overspent');
}
echo "Farm Rewards integration checks passed.\\n";
