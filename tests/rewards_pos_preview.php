<?php
// Isolated eligibility tests; never connects to live farm data.
// BCRF_REWARDS_TEST_DSN must target a disposable MySQL database.
$dsn = getenv('BCRF_REWARDS_TEST_DSN');
if (!$dsn) { fwrite(STDERR,"Set BCRF_REWARDS_TEST_DSN for a disposable database.\n"); exit(2); }
$pdo = new PDO($dsn,getenv('BCRF_REWARDS_TEST_USER') ?: '',getenv('BCRF_REWARDS_TEST_PASSWORD') ?: '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE sales (id BIGINT PRIMARY KEY,status VARCHAR(20),total_cents BIGINT,customer_id INT NULL,subtotal_cents BIGINT NOT NULL DEFAULT 0,discount_cents BIGINT NOT NULL DEFAULT 0)");
$pdo->exec("CREATE TABLE sale_lines (sale_id BIGINT,product_type VARCHAR(20),gross_cents BIGINT,discount_cents BIGINT,refunded_net_cents BIGINT)");
$pdo->exec("CREATE TABLE payments (sale_id BIGINT,tender VARCHAR(20),amount_cents BIGINT,status VARCHAR(12),reconciled_at DATETIME NULL,reconcile_flag VARCHAR(20) NULL)");
$pdo->exec("INSERT INTO sales (id,status,total_cents,subtotal_cents,discount_cents) VALUES (1,'completed',2750,3000,250),(2,'completed',500,500,0),(3,'voided',500,500,0)");
$pdo->exec("INSERT INTO sale_lines VALUES (1,'retail',2000,200,100),(1,'gift_card',500,0,0),(1,'admission',500,50,0),(2,'retail',500,0,0),(3,'retail',500,0,0)");
$pdo->exec("INSERT INTO payments (sale_id,tender,amount_cents,status) VALUES (1,'cash',2750,'completed'),(2,'cash',500,'pending'),(3,'cash',500,'completed')");
require __DIR__.'/../includes/rewards-pos-preview.php';
function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function mustReject(callable $fn,string $message):void {try {$fn();}catch(BcrfRewardError $e){return;}throw new RuntimeException($message);}
check(bcrf_rewards_sale_eligible_cents($pdo,1)===2150,'net eligible cents excludes gift card and refunded amount');
mustReject(fn()=>bcrf_rewards_sale_eligible_cents($pdo,2),'unpaid sale');
mustReject(fn()=>bcrf_rewards_sale_eligible_cents($pdo,3),'voided sale');
$pdo->exec("UPDATE payments SET status='completed' WHERE sale_id=2");
$pdo->exec("INSERT INTO payments (sale_id,tender,amount_cents,status) VALUES (2,'cash',1,'pending')");
mustReject(fn()=>bcrf_rewards_sale_eligible_cents($pdo,2),'completed sale with unresolved additional payment');
$pdo->exec("DELETE FROM payments WHERE sale_id=2 AND status='pending'");

check(bcrf_rewards_sale_eligible_cents($pdo,2)===500,'completed payment');
$pdo->exec("UPDATE payments SET tender='gift_card' WHERE sale_id=2");
mustReject(fn()=>bcrf_rewards_sale_eligible_cents($pdo,2),'gift card tender');
$pdo->exec("UPDATE payments SET tender='other' WHERE sale_id=2");
mustReject(fn()=>bcrf_rewards_sale_eligible_cents($pdo,2),'unverified tender');
$pdo->exec("UPDATE payments SET tender='cash',amount_cents=501 WHERE sale_id=2");
mustReject(fn()=>bcrf_rewards_sale_eligible_cents($pdo,2),'overpaid or mismatched payment');
$pdo->exec("UPDATE payments SET amount_cents=500 WHERE sale_id=2");
$pdo->exec("UPDATE sale_lines SET refunded_net_cents=501 WHERE sale_id=2");
mustReject(fn()=>bcrf_rewards_sale_eligible_cents($pdo,2),'refund exceeds net line');
$pdo->exec("UPDATE sale_lines SET refunded_net_cents=0,discount_cents=501 WHERE sale_id=2");
mustReject(fn()=>bcrf_rewards_sale_eligible_cents($pdo,2),'discount exceeds gross');
$pdo->exec("UPDATE sale_lines SET discount_cents=0 WHERE sale_id=2");
$pdo->exec("DELETE FROM sale_lines WHERE sale_id=2");
mustReject(fn()=>bcrf_rewards_sale_eligible_cents($pdo,2),'sale with no lines');
$pdo->exec("INSERT INTO sales (id,status,total_cents,subtotal_cents) VALUES (4,'completed',500,500)");
$pdo->exec("INSERT INTO sale_lines VALUES (4,'retail',500,0,0)");
$pdo->exec("INSERT INTO payments (sale_id,tender,amount_cents,status) VALUES (4,'venmo',500,'completed')");
mustReject(fn()=>bcrf_rewards_sale_eligible_cents($pdo,4),'unreconciled noncash');
$pdo->exec("UPDATE payments SET reconciled_at=UTC_TIMESTAMP() WHERE sale_id=4");
check(bcrf_rewards_sale_eligible_cents($pdo,4)===500,'reconciled noncash');
$pdo->exec("UPDATE payments SET reconcile_flag='wrong_amount' WHERE sale_id=4");
mustReject(fn()=>bcrf_rewards_sale_eligible_cents($pdo,4),'reconciliation exception');
$pdo->exec("CREATE TEMPORARY TABLE reward_households (id BIGINT PRIMARY KEY,status VARCHAR(20))");
$pdo->exec("CREATE TEMPORARY TABLE reward_household_members (household_id BIGINT,customer_id INT,verification_status VARCHAR(20),revoked_at DATETIME NULL)");
$pdo->exec("INSERT INTO reward_households VALUES (7,'active'),(8,'active')");
$pdo->exec("INSERT INTO reward_household_members VALUES (7,11,'verified',NULL),(8,12,'pending',NULL)");
mustReject(fn()=>bcrf_rewards_sale_verified_household($pdo,1),'anonymous sale has no household');
$pdo->exec("UPDATE sales SET customer_id=11 WHERE id=1");
check(bcrf_rewards_sale_verified_household($pdo,1)===['customer_id'=>11,'household_id'=>7],'verified sale owner');
check(bcrf_rewards_sale_claim_preview($pdo,1,11)===['customer_id'=>11,'household_id'=>7,'eligible_cents'=>2150],'authenticated customer can preview own eligible sale');
mustReject(fn()=>bcrf_rewards_sale_claim_preview($pdo,1,12),'other authenticated customer cannot claim sale');
mustReject(fn()=>bcrf_rewards_sale_claim_preview($pdo,1,0),'missing authenticated customer');

$pdo->exec("INSERT INTO reward_household_members VALUES (8,11,'verified',NULL)");
mustReject(fn()=>bcrf_rewards_sale_verified_household($pdo,1),'ambiguous verified household memberships');
$pdo->exec("DELETE FROM reward_household_members WHERE household_id=8 AND customer_id=11");
$pdo->exec("UPDATE reward_households SET status='inactive' WHERE id=7");
mustReject(fn()=>bcrf_rewards_sale_verified_household($pdo,1),'inactive household cannot claim sale');
$pdo->exec("UPDATE reward_households SET status='active' WHERE id=7");
$pdo->exec("UPDATE reward_household_members SET revoked_at=UTC_TIMESTAMP() WHERE customer_id=11");
mustReject(fn()=>bcrf_rewards_sale_verified_household($pdo,1),'revoked member');
$pdo->exec("UPDATE sales SET customer_id=12 WHERE id=1");
mustReject(fn()=>bcrf_rewards_sale_verified_household($pdo,1),'unverified member');
$pdo->exec("INSERT INTO sales (id,status,total_cents,subtotal_cents) VALUES (5,'completed',500,500)");
$pdo->exec("INSERT INTO sale_lines VALUES (5,'gift_card',500,0,0)");
$pdo->exec("INSERT INTO payments (sale_id,tender,amount_cents,status) VALUES (5,'cash',500,'completed')");
mustReject(fn()=>bcrf_rewards_sale_eligible_cents($pdo,5),'gift-card-only purchase has no eligible net value');
$pdo->exec("UPDATE sales SET subtotal_cents=100 WHERE id=1");
mustReject(fn()=>bcrf_rewards_sale_eligible_cents($pdo,1),'eligible value exceeds recorded net subtotal');
$pdo->exec("UPDATE sales SET subtotal_cents=3000,discount_cents=249 WHERE id=1");
mustReject(fn()=>bcrf_rewards_sale_eligible_cents($pdo,1),'sale discount disagrees with line discounts');
$pdo->exec("UPDATE sales SET discount_cents=250,subtotal_cents=3001 WHERE id=1");
mustReject(fn()=>bcrf_rewards_sale_eligible_cents($pdo,1),'sale subtotal disagrees with line gross');
echo "Farm Rewards POS eligibility checks passed.\\n";
