<?php
// Isolated eligibility tests; never connects to live farm data.
// BCRF_REWARDS_TEST_DSN must target a disposable MySQL database.
$dsn = getenv('BCRF_REWARDS_TEST_DSN');
if (!$dsn) { fwrite(STDERR,"Set BCRF_REWARDS_TEST_DSN for a disposable database.\n"); exit(2); }
$pdo = new PDO($dsn,getenv('BCRF_REWARDS_TEST_USER') ?: '',getenv('BCRF_REWARDS_TEST_PASSWORD') ?: '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE sales (id BIGINT PRIMARY KEY,status VARCHAR(20),total_cents BIGINT)");
$pdo->exec("CREATE TABLE sale_lines (sale_id BIGINT,product_type VARCHAR(20),gross_cents BIGINT,discount_cents BIGINT,refunded_net_cents BIGINT)");
$pdo->exec("CREATE TABLE payments (sale_id BIGINT,tender VARCHAR(20),amount_cents BIGINT,status VARCHAR(12))");
$pdo->exec("INSERT INTO sales VALUES (1,'completed',2150),(2,'completed',500),(3,'voided',500)");
$pdo->exec("INSERT INTO sale_lines VALUES (1,'retail',2000,200,100),(1,'gift_card',500,0,0),(1,'admission',500,50,0),(2,'retail',500,0,0),(3,'retail',500,0,0)");
$pdo->exec("INSERT INTO payments VALUES (1,'cash',2150,'completed'),(2,'cash',500,'pending'),(3,'cash',500,'completed')");
require __DIR__.'/../includes/rewards-pos-preview.php';
function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function mustReject(callable $fn,string $message):void {try {$fn();}catch(BcrfRewardError $e){return;}throw new RuntimeException($message);}
check(bcrf_rewards_sale_eligible_cents($pdo,1)===2150,'net eligible cents excludes gift card and refunded amount');
mustReject(fn()=>bcrf_rewards_sale_eligible_cents($pdo,2),'unpaid sale');
mustReject(fn()=>bcrf_rewards_sale_eligible_cents($pdo,3),'voided sale');
$pdo->exec("UPDATE payments SET status='completed' WHERE sale_id=2");
check(bcrf_rewards_sale_eligible_cents($pdo,2)===500,'completed payment');
$pdo->exec("UPDATE payments SET tender='gift_card' WHERE sale_id=2");
mustReject(fn()=>bcrf_rewards_sale_eligible_cents($pdo,2),'gift card tender');
$pdo->exec("UPDATE payments SET tender='other' WHERE sale_id=2");
mustReject(fn()=>bcrf_rewards_sale_eligible_cents($pdo,2),'unverified tender');
echo "Farm Rewards POS eligibility checks passed.\n";
