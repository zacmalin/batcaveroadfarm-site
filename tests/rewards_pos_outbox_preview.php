<?php
require_once __DIR__.'/../includes/roost-pos-outbox-preview.php';
$pdo=new PDO(getenv('BCRF_REWARDS_TEST_DSN'),getenv('BCRF_REWARDS_TEST_USER')?:'',getenv('BCRF_REWARDS_TEST_PASSWORD')?:'');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([['event_type'=>'bad','source_id'=>1],['event_type'=>'sale.completed','source_id'=>0],['event_type'=>'sale.refunded','source_id'=>'abc']] as $bad) {
    try {bcrf_roost_outbox_event_preview($pdo,$bad);throw new RuntimeException('Invalid event accepted.');}
    catch(InvalidArgumentException $expected) {}
}
$pdo->beginTransaction();
try {bcrf_roost_outbox_event_preview($pdo,['event_type'=>'sale.completed','source_id'=>1]);throw new RuntimeException('Open transaction accepted.');}
catch(LogicException $expected) {$pdo->rollBack();}
// Fixture sales and households are established by rewards_integration.php earlier in CI.
$pdo->exec("INSERT INTO sales (id,customer_id,status,subtotal_cents,discount_cents,total_cents)
 VALUES (901,1,'completed',2000,0,2000)");
$pdo->exec("INSERT INTO sale_lines (sale_id,product_type,gross_cents,discount_cents,refunded_net_cents)
 VALUES (901,'retail',2000,0,0)");
$pdo->exec("INSERT INTO payments (sale_id,tender,amount_cents,status) VALUES (901,'cash',2000,'completed')");
$good=bcrf_roost_outbox_event_preview($pdo,['event_type'=>'sale.completed','source_id'=>901]);
if ($good['points']!==100 || $good['household_id']!==1 || $good['customer_id']!==1)
    throw new RuntimeException('Verified sale preview mismatch.');
$pdo->exec("UPDATE sales SET customer_id=NULL WHERE id=901");
try {bcrf_roost_outbox_event_preview($pdo,['event_type'=>'sale.completed','source_id'=>901]);
    throw new RuntimeException('Unowned sale was accepted.');}
catch(BcrfRewardError $expected) {}
$pdo->exec("UPDATE sales SET customer_id=1 WHERE id=901");
$pdo->exec("UPDATE payments SET status='pending' WHERE sale_id=901");
try {bcrf_roost_outbox_event_preview($pdo,['event_type'=>'sale.completed','source_id'=>901]);
    throw new RuntimeException('Unsettled sale was accepted.');}
catch(BcrfRewardError $expected) {}
echo "POS event preview input and sale provenance guards passed.\\n";
