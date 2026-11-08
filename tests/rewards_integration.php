<?php
// Run only against a disposable MySQL 8 database:
// BCRF_REWARDS_TEST_DSN='mysql:host=127.0.0.1;dbname=...' BCRF_REWARDS_TEST_USER=... php tests/rewards_integration.php
$dsn = getenv('BCRF_REWARDS_TEST_DSN');
if (!$dsn) { fwrite(STDERR, "Set BCRF_REWARDS_TEST_DSN for a disposable database.\n"); exit(2); }
$pdo = new PDO($dsn, getenv('BCRF_REWARDS_TEST_USER') ?: '', getenv('BCRF_REWARDS_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE customers (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB");
$pdo->exec("INSERT INTO customers VALUES (1),(2)");
$pdo->exec("CREATE TABLE sales (id BIGINT PRIMARY KEY, customer_id INT NULL, status VARCHAR(24) NOT NULL, subtotal_cents BIGINT NOT NULL, discount_cents BIGINT NOT NULL DEFAULT 0, total_cents BIGINT NOT NULL) ENGINE=InnoDB");
$pdo->exec("INSERT INTO sales VALUES (100,1,'completed',4000,0,4000),(101,1,'completed',5500,0,5500),(102,2,'completed',500,0,500),(103,1,'voided',500,0,500)");
$pdo->exec("CREATE TABLE sale_lines (sale_id BIGINT, product_type VARCHAR(30), gross_cents BIGINT, discount_cents BIGINT, refunded_net_cents BIGINT) ENGINE=InnoDB");
$pdo->exec("INSERT INTO sale_lines VALUES (100,'retail',4000,0,0),(101,'retail',5500,0,0),(102,'retail',500,0,0),(103,'retail',500,0,0)");
$pdo->exec("CREATE TABLE payments (sale_id BIGINT,tender VARCHAR(30),amount_cents BIGINT,status VARCHAR(20),reconciled_at DATETIME NULL,reconcile_flag VARCHAR(30) NULL) ENGINE=InnoDB");
$pdo->exec("INSERT INTO payments (sale_id,tender,amount_cents,status) VALUES (100,'cash',4000,'completed'),(101,'cash',5500,'completed'),(102,'cash',500,'completed'),(103,'cash',500,'completed')");
$pdo->exec("CREATE TABLE refunds (id BIGINT PRIMARY KEY, sale_id BIGINT NOT NULL) ENGINE=InnoDB");
$pdo->exec("INSERT INTO refunds VALUES (1,100),(2,101)");
$schema = require __DIR__ . '/../admin/migrations/0016_farm_rewards_foundation.php';
$schema($pdo);
$schema($pdo); // rerunnable
$pdo->exec("CREATE TABLE check_ins (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, business_date DATE NOT NULL) ENGINE=InnoDB");
$pdo->exec("INSERT INTO check_ins VALUES (1,'2026-11-08'),(2,'2026-11-08'),(3,'2026-11-09')");
$attendanceSchema = require __DIR__ . '/../admin/migrations/0017_roost_rewards_attendance.php';
$attendanceSchema($pdo);
$attendanceSchema($pdo); // rerunnable
$pdo->exec("INSERT INTO reward_verified_attendance (check_in_id,customer_id,business_date,verified_by_staff_id,verified_at,created_at) VALUES (1,1,'2026-11-08',7,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
try {
    $pdo->exec("INSERT INTO reward_verified_attendance (check_in_id,customer_id,business_date,verified_by_staff_id,verified_at,created_at) VALUES (2,1,'2026-11-08',7,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    throw new RuntimeException('Duplicate attendee on same operating day accepted');
} catch (PDOException $e) {
    if ($e->getCode() !== '23000') throw $e;
}
$pdo->exec("INSERT INTO reward_verified_attendance (check_in_id,customer_id,business_date,verified_by_staff_id,verified_at,created_at) VALUES (2,2,'2026-11-08',7,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO reward_verified_attendance (check_in_id,customer_id,business_date,verified_by_staff_id,verified_at,created_at) VALUES (3,1,'2026-11-09',7,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");

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
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_id'=>'999','idempotency_key'=>'missing-sale'])),'missing sale cannot earn');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_id'=>'102','idempotency_key'=>'different-customer-sale'])),'sale belongs to another customer');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_id'=>'103','idempotency_key'=>'voided-sale'])),'voided sale cannot earn');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['points_delta'=>201,'idempotency_key'=>'inflated-points'])),'caller cannot inflate purchase points');
$pdo->exec("UPDATE sale_lines SET gross_cents=4100 WHERE sale_id=101");
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_id'=>'101','points_delta'=>275,'idempotency_key'=>'tampered-sale-lines'])),'inconsistent sale lines cannot earn');
$pdo->exec("UPDATE sale_lines SET gross_cents=5500 WHERE sale_id=101");
$pdo->exec("UPDATE payments SET status='pending' WHERE sale_id=101");
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_id'=>'101','points_delta'=>275,'idempotency_key'=>'unsettled-purchase'])),'unsettled purchase cannot earn');
$pdo->exec("UPDATE payments SET status='completed' WHERE sale_id=101");

rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_id'=>'fabricated-label','idempotency_key'=>'invalid-sale-label'])),'purchase source requires numeric sale ID');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_id'=>'0','idempotency_key'=>'invalid-sale-zero'])),'purchase source rejects zero sale ID');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_type'=>'visit','source_id'=>'wrong-kind','idempotency_key'=>'test-wrong-spend-source'])),'purchase earning requires sale source');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'earn_visit','source_type'=>'sale','source_id'=>'wrong-kind','points_delta'=>25,'idempotency_key'=>'test-wrong-visit-source'])),'visit earning requires visit source');

$pdo->exec("INSERT INTO reward_households (id,status,created_at) VALUES (2,'active',UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO reward_accounts (household_id,updated_at) VALUES (2,UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO customers VALUES (3)");
$pdo->exec("INSERT INTO reward_household_members (household_id,customer_id,role,verification_status,can_redeem,joined_at) VALUES (2,3,'manager','verified',1,UTC_TIMESTAMP(3))");
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['household_id'=>2,'customer_id'=>3,'idempotency_key'=>'other-household-same-sale'])),'same sale cannot award another household');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['household_id'=>2,'customer_id'=>1,'source_id'=>'101','points_delta'=>275,'idempotency_key'=>'wrong-household-customer'])),'sale cannot award to household other than verified customer household');

$b = bcrf_reward_post($pdo,$base);
expect($b['replayed'] && $b['balance']===200,'idempotent replay');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['idempotency_key'=>'different-key-same-sale'])),'duplicate sale source with different key');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['points_delta'=>300])),'conflicting replay');
$v = bcrf_reward_post($pdo,array_merge($base,['source_type'=>'visit','source_id'=>'1','entry_type'=>'earn_visit','points_delta'=>25,'idempotency_key'=>'test-visit-1']));
expect($v['balance']===225,'visit award');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_type'=>'visit','source_id'=>'not-an-id','entry_type'=>'earn_visit','points_delta'=>25,'idempotency_key'=>'fabricated-visit'])),'fabricated visit label rejected');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_type'=>'visit','source_id'=>'999','entry_type'=>'earn_visit','points_delta'=>25,'idempotency_key'=>'missing-visit'])),'nonexistent attendance rejected');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_type'=>'visit','source_id'=>'2','entry_type'=>'earn_visit','points_delta'=>25,'idempotency_key'=>'wrong-attendee'])),'another customer attendance rejected');
$pdo->exec("UPDATE reward_verified_attendance SET business_date='2026-11-10' WHERE id=1");
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_type'=>'visit','source_id'=>'1','entry_type'=>'earn_visit','points_delta'=>25,'idempotency_key'=>'mismatched-operating-day'])),'mismatched check-in date rejected');
$pdo->exec("UPDATE reward_verified_attendance SET business_date='2026-11-08' WHERE id=1");

rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_type'=>'visit','source_id'=>'invalid-visit-award','entry_type'=>'earn_visit','points_delta'=>26,'idempotency_key'=>'invalid-visit-award'])),'visit cannot earn more than 25 points');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_type'=>'visit','source_id'=>'underpaid-visit-award','entry_type'=>'earn_visit','points_delta'=>24,'idempotency_key'=>'underpaid-visit-award'])),'visit cannot earn fewer than 25 points');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['household_id'=>2,'customer_id'=>3,'source_type'=>'visit','source_id'=>'1','entry_type'=>'earn_visit','points_delta'=>25,'idempotency_key'=>'other-household-same-visit'])),'same visit cannot award another household');

rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_type'=>'visit','source_id'=>'1','entry_type'=>'earn_visit','points_delta'=>25,'idempotency_key'=>'different-key-same-visit'])),'duplicate visit source with different key');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'redeem','points_delta'=>-250,'idempotency_key'=>'test-redeem-too-much'])),'overspend');
$d = bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'redeem','points_delta'=>-200,'source_type'=>'reward','source_id'=>'r1','idempotency_key'=>'test-redeem-1','customer_id'=>2]));
expect($d['balance']===25,'shared adult redemption');
$pdo->exec("UPDATE reward_household_members SET can_redeem=0 WHERE customer_id=2");
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'redeem','points_delta'=>-1,'source_type'=>'reward','source_id'=>'disabled','idempotency_key'=>'test-disabled-redeem','customer_id'=>2])),'disabled redemption permission');
$pdo->exec("UPDATE reward_household_members SET can_redeem=1 WHERE customer_id=2");
$pdo->exec("UPDATE reward_household_members SET revoked_at=UTC_TIMESTAMP(3) WHERE customer_id=2");
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'redeem','points_delta'=>-10,'idempotency_key'=>'test-revoked','customer_id'=>2])),'revoked adult');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'earn_spend','points_delta'=>25,'source_id'=>'102','idempotency_key'=>'test-revoked-earn','customer_id'=>2])),'revoked customer cannot earn');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'earn_visit','points_delta'=>25,'source_id'=>'revoked-visit','idempotency_key'=>'test-revoked-visit','customer_id'=>2])),'revoked customer cannot receive visit points');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'refund_reversal','points_delta'=>-1,'source_type'=>'refund','source_id'=>'2','idempotency_key'=>'wrong-sale-refund','related_entry_id'=>$a['id']])),'refund must belong to awarded sale');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'refund_reversal','points_delta'=>-1,'source_type'=>'refund','source_id'=>'999','idempotency_key'=>'missing-refund','related_entry_id'=>$a['id']])),'refund must exist');
$refund = bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'refund_reversal','points_delta'=>-200,'source_type'=>'refund','source_id'=>'1','idempotency_key'=>'test-refund-1','related_entry_id'=>$a['id']]));
expect($refund['balance']===-175,'refund negative balance');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'refund_reversal','points_delta'=>-1,'source_type'=>'refund','source_id'=>'1','idempotency_key'=>'duplicate-refund-different-key','related_entry_id'=>$a['id']])),'same refund cannot reverse twice');

rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'refund_reversal','points_delta'=>-1,'source_type'=>'refund','source_id'=>'1','idempotency_key'=>'test-refund-2','related_entry_id'=>$a['id']])),'over reversal');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'redeem','points_delta'=>-1,'idempotency_key'=>'test-negative-redeem'])),'negative balance redeem');
// Race two independent database sessions against the same 100-point balance.
// Each child starts together and attempts a 100-point redemption. Exactly one succeeds.
// Skip the concurrency assertion only when CLI pcntl is unavailable (CI installs it).
if (function_exists('pcntl_fork') && function_exists('stream_socket_pair')) {
    $topup = bcrf_reward_post($pdo,array_merge($base,['source_type'=>'sale','source_id'=>'101','points_delta'=>275,'idempotency_key'=>'test-topup']));
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
