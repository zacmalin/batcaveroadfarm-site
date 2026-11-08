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
$pdo->exec("CREATE TABLE sale_lines (id BIGINT AUTO_INCREMENT PRIMARY KEY, sale_id BIGINT, product_type VARCHAR(30), gross_cents BIGINT, discount_cents BIGINT, refunded_net_cents BIGINT) ENGINE=InnoDB");
$pdo->exec("INSERT INTO sale_lines (sale_id,product_type,gross_cents,discount_cents,refunded_net_cents) VALUES (100,'retail',4000,0,0),(101,'retail',5500,0,0),(102,'retail',500,0,0),(103,'retail',500,0,0)");
$pdo->exec("CREATE TABLE payments (sale_id BIGINT,tender VARCHAR(30),amount_cents BIGINT,status VARCHAR(20),reconciled_at DATETIME NULL,reconcile_flag VARCHAR(30) NULL) ENGINE=InnoDB");
$pdo->exec("INSERT INTO payments (sale_id,tender,amount_cents,status) VALUES (100,'cash',4000,'completed'),(101,'cash',5500,'completed'),(102,'cash',500,'completed'),(103,'cash',500,'completed')");
$pdo->exec("CREATE TABLE refunds (id BIGINT PRIMARY KEY, sale_id BIGINT NOT NULL, net_cents BIGINT NOT NULL) ENGINE=InnoDB");
$pdo->exec("INSERT INTO refunds VALUES (1,100,4000),(2,101,0)");
$pdo->exec("CREATE TABLE refund_lines (refund_id BIGINT, sale_line_id BIGINT, net_cents BIGINT) ENGINE=InnoDB");
$schema = require __DIR__ . '/../admin/migrations/0016_farm_rewards_foundation.php';
$schema($pdo);
$schema($pdo); // rerunnable
$pdo->exec("CREATE TABLE check_ins (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, business_date DATE NOT NULL, party_size INT NOT NULL) ENGINE=InnoDB");
$pdo->exec("INSERT INTO check_ins VALUES (1,'2026-11-08',1),(2,'2026-11-08',2),(3,'2026-11-09',1),(4,'2026-11-10',1),(5,'2026-11-11',1)");
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
// Staff-only verification service: no public endpoint, no implicit point award.
require __DIR__ . '/../includes/roost-attendance.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$_SESSION['user_id'] = 7;
$_SESSION['user_role'] = 'administrator';
$staffAttendance = bcrf_roost_verify_attendee($pdo, 4, 2);
expect($staffAttendance > 0, 'staff can verify an eligible attendee');
rejected(fn()=>bcrf_roost_verify_attendee($pdo,4,1),'full check-in cannot accept another attendee');
rejected(fn()=>bcrf_roost_verify_attendee($pdo,5,3),'unverified household member cannot be verified'); // customer 3 not yet enrolled
$_SESSION['user_role'] = 'visitor';
rejected(fn()=>bcrf_roost_verify_attendee($pdo,5,2),'visitor cannot verify attendance');
$_SESSION['user_role'] = 'administrator';
expect((int)$pdo->query("SELECT COUNT(*) FROM reward_ledger WHERE entry_type='earn_visit'")->fetchColumn()===0,'attendance verification does not silently award points');
function expect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejected(callable $run, string $message): void { try { $run(); } catch (BcrfRewardError $e) { return; } throw new RuntimeException('Expected rejection: '.$message); }
$base = ['household_id'=>1,'policy_id'=>1,'customer_id'=>1,'source_type'=>'sale','source_id'=>'100','entry_type'=>'earn_spend','points_delta'=>200,'idempotency_key'=>'test-sale-100'];
$a = bcrf_reward_post($pdo,$base);
expect($a['balance']===200 && !$a['replayed'],'first award');
$activityAfterEarn = $pdo->query("SELECT last_qualifying_activity_at FROM reward_households WHERE id=1")->fetchColumn();
expect($activityAfterEarn !== false && $activityAfterEarn !== null, 'earning records household qualifying activity');
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
expect($pdo->query("SELECT last_qualifying_activity_at FROM reward_households WHERE id=1")->fetchColumn() === $activityAfterEarn, 'replayed earning does not refresh inactivity clock');
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
$pdo->exec("INSERT INTO refund_lines VALUES (1,1,4000)");
$pdo->exec("UPDATE sale_lines SET refunded_net_cents=4000 WHERE id=1");
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'refund_reversal','points_delta'=>-199,'source_type'=>'refund','source_id'=>'1','idempotency_key'=>'incorrect-refund-amount','related_entry_id'=>$a['id']])), 'ledger rejects incorrect reversal amount');
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

$pdo->exec("UPDATE reward_household_members SET revoked_at=NULL WHERE customer_id=2");
// Trusted visit-award orchestration: provenance, fixed points, replay and fabricated sources.
require __DIR__ . '/../includes/roost-visit-awards.php';
$visitAward = bcrf_roost_award_verified_visit($pdo, $staffAttendance);
expect(!$visitAward['replayed'] && $visitAward['id'] > 0, 'verified individual visit awards 25 points');
$visitReplay = bcrf_roost_award_verified_visit($pdo, $staffAttendance);
expect($visitReplay['replayed'] && $visitReplay['id'] === $visitAward['id'], 'same attendance never awards twice');
rejected(fn()=>bcrf_roost_award_verified_visit($pdo, 999999), 'fabricated attendance cannot earn');
expect((int)$pdo->query("SELECT COUNT(*) FROM reward_ledger WHERE entry_type='earn_visit' AND source_id='".(int)$staffAttendance."'")->fetchColumn()===1, 'single verified visit ledger award');


// Read-only expiration preview: only positive-balance, active, stale households.
require __DIR__ . '/../includes/roost-expiration-preview.php';
expect(!bcrf_roost_is_inactive_at('2025-11-08 12:00:00','2026-11-08 11:59:59'), 'not expired before anniversary');
expect(bcrf_roost_is_inactive_at('2025-11-08 12:00:00','2026-11-08 12:00:00'), 'expires on 12-month anniversary');
expect(bcrf_roost_is_inactive_at('2024-02-29 12:00:00','2025-02-28 12:00:00'), 'leap-day anniversary clamps');
$pdo->exec("UPDATE reward_households SET last_qualifying_activity_at='2024-01-01 00:00:00' WHERE id=1");
$pdo->exec("UPDATE reward_accounts SET balance_points=75 WHERE household_id=1");
$candidates = bcrf_roost_expiration_candidates($pdo);
expect(count($candidates)===1 && (int)$candidates[0]['household_id']===1, 'stale positive household appears in preview');
expect((int)$pdo->query("SELECT balance_points FROM reward_accounts WHERE household_id=1")->fetchColumn()===75, 'preview does not deduct points');
// Expiration processor must lock and recheck activity; replay cannot deduct twice.
require __DIR__ . '/../includes/roost-expiration-processor.php';
$expired = bcrf_roost_expire_household($pdo, 1);
expect($expired['expired'] && $expired['points']===75 && $expired['balance']===0, 'stale positive points expire exactly once');
$again = bcrf_roost_expire_household($pdo, 1);
expect(!$again['expired'] && $again['points']===0, 'expiration replay is a no-op');
expect((int)$pdo->query("SELECT COUNT(*) FROM reward_ledger WHERE entry_type='expire' AND household_id=1")->fetchColumn()===1, 'one expiration ledger event');
$pdo->exec("UPDATE reward_accounts SET balance_points=50 WHERE household_id=1");
$pdo->exec("UPDATE reward_households SET last_qualifying_activity_at=UTC_TIMESTAMP(3) WHERE id=1");
$fresh = bcrf_roost_expire_household($pdo, 1);
expect(!$fresh['expired'] && $fresh['balance']===50, 'recent activity blocks expiration');
// A POS refund must never reverse visit points, even if a caller forges a related entry.
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,[
    'entry_type'=>'refund_reversal','points_delta'=>-25,'source_type'=>'refund',
    'source_id'=>'2','idempotency_key'=>'refund-visit-forgery',
    'related_entry_id'=>$visitAward['id']
])), 'refund cannot reverse a visit award');
// Reconciled refund preview uses actual refund lines and sale-line running totals.
require_once __DIR__ . '/../includes/roost-refund-preview.php';
$preview = bcrf_roost_refund_reversal_preview($pdo,100);
expect($preview['cumulative_points_to_reverse']===200 && $preview['remaining_eligible_cents']===0,'full refund preview matches original points');
$pdo->exec("UPDATE refunds SET net_cents=-1 WHERE id=1");
rejected(fn()=>bcrf_roost_refund_reversal_preview($pdo,100),'negative refund header rejected');
$pdo->exec("UPDATE refunds SET net_cents=4000 WHERE id=1");
$pdo->exec("UPDATE refund_lines SET net_cents=3900 WHERE refund_id=1");
rejected(fn()=>bcrf_roost_refund_reversal_preview($pdo,100),'refund amount mismatch fails closed');
// Partial refunds must reverse only the change in floored points.
$pdo->exec("UPDATE refunds SET net_cents=1001 WHERE id=1");
$pdo->exec("UPDATE refund_lines SET net_cents=1001 WHERE refund_id=1");
$pdo->exec("UPDATE sale_lines SET refunded_net_cents=1001 WHERE id=1");
$partial = bcrf_roost_refund_reversal_preview($pdo,100);
expect($partial['original_eligible_cents']===4000 && $partial['remaining_eligible_cents']===2999 &&
    $partial['cumulative_points_to_reverse']===51,'partial refund rounds cumulative points correctly');
// A second refund changes the cumulative reversal by only the newly lost points.
$pdo->exec("INSERT INTO refunds VALUES (3,100,999)");
$pdo->exec("INSERT INTO refund_lines VALUES (3,1,999)");
$pdo->exec("UPDATE sale_lines SET refunded_net_cents=2000 WHERE id=1");
$twice = bcrf_roost_refund_reversal_preview($pdo,100);
expect($twice['cumulative_points_to_reverse']===100 && count($twice['eligible_refunds_cents'])===2,
    'multiple partial refunds reconcile cumulatively');
// Ineligible items may be refunded without reducing eligible points.
$pdo->exec("INSERT INTO sales VALUES (104,1,'partially_refunded',3000,0,3000)");
$pdo->exec("INSERT INTO sale_lines (sale_id,product_type,gross_cents,discount_cents,refunded_net_cents)
    VALUES (104,'retail',2000,0,0),(104,'gift_card',1000,0,1000)");
$pdo->exec("INSERT INTO refunds VALUES (4,104,1000)");
$pdo->exec("INSERT INTO refund_lines VALUES (4,6,1000)");
$mixed = bcrf_roost_refund_reversal_preview($pdo,104);
expect($mixed['cumulative_points_to_reverse']===0 && $mixed['remaining_eligible_cents']===2000,
    'ineligible refund does not reverse eligible points');
// Sub-dollar refunds may not cross a points boundary; no ledger debit is due.
$pdo->exec("INSERT INTO sales VALUES (105,1,'partially_refunded',101,0,101)");
$pdo->exec("INSERT INTO sale_lines (sale_id,product_type,gross_cents,discount_cents,refunded_net_cents)
    VALUES (105,'retail',101,0,1)");
$pdo->exec("INSERT INTO refunds VALUES (5,105,1)");
$pdo->exec("INSERT INTO refund_lines VALUES (5,7,1)");
$zeroRefund = bcrf_roost_refund_reversal_preview($pdo,105);
expect($zeroRefund['cumulative_points_to_reverse']===0 && bcrf_roost_refund_points_due(5,0,0)===0, 'one-cent refund not crossing a point threshold needs no debit');
expect(bcrf_roost_refund_is_points_noop($pdo,105,5,0), 'read-only preflight recognizes zero-point refund');
expect(!bcrf_roost_refund_is_points_noop($pdo,100,200,0), 'read-only preflight identifies refund needing reversal');
$pdo->exec("UPDATE refund_lines SET net_cents=0 WHERE refund_id=5");
$pdo->exec("UPDATE refunds SET net_cents=0 WHERE id=5");
$pdo->exec("UPDATE sale_lines SET refunded_net_cents=0 WHERE id=7");
$zeroRefund = bcrf_roost_refund_reversal_preview($pdo,105);
expect($zeroRefund['cumulative_points_to_reverse']===0 &&
    bcrf_roost_refund_points_due(5,0,0)===0,'zero-point refund requires no ledger debit');
expect(bcrf_roost_refund_points_due(200,100,51)===49,'incremental reversal accounts for prior refund');
expect(bcrf_roost_refund_points_due(200,100,100)===0,'no points deducted twice');
rejected(fn()=>bcrf_roost_refund_points_due(200,100,101),'over-reversal rejected');
rejected(fn()=>bcrf_roost_refund_points_due(200,201,0),'refund cannot exceed original award');
echo "Farm Rewards integration checks passed.\\n";
