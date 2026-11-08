<?php
require_once __DIR__.'/../includes/roost-pos-outbox-test-handler.php';
$pdo=new PDO(getenv('BCRF_REWARDS_TEST_DSN'),getenv('BCRF_REWARDS_TEST_USER')?:'',getenv('BCRF_REWARDS_TEST_PASSWORD')?:'');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$event=['event_type'=>'sale.completed','source_id'=>901];
try {
    bcrf_roost_outbox_handle_test_event($pdo,$event,1);
    throw new RuntimeException('Unauthorized test posting accepted.');
} catch(LogicException $expected) {
    if ($expected->getMessage()==='Unauthorized test posting accepted.') throw $expected;
}
// The sale fixture is intentionally unsettled by prior tests. A test-only
// handler must fail closed and leave the reward ledger unchanged.
$before=(int)$pdo->query("SELECT COUNT(*) FROM reward_ledger WHERE source_type='sale' AND source_id='901'")->fetchColumn();
try {
    bcrf_roost_outbox_handle_test_event($pdo,$event,1,true);
    throw new RuntimeException('Unsettled sale was posted.');
} catch(BcrfRewardError $expected) {
    // Expected: payment remains pending.
}
$after=(int)$pdo->query("SELECT COUNT(*) FROM reward_ledger WHERE source_type='sale' AND source_id='901'")->fetchColumn();
if ($before!==$after) throw new RuntimeException('Rejected event modified ledger.');
// Successful award, lost acknowledgment, and retry use a fresh sale fixture.
$pdo->exec("INSERT INTO sales (id,customer_id,status,subtotal_cents,discount_cents,total_cents)
 VALUES (902,1,'completed',2300,0,2300)");
$pdo->exec("INSERT INTO sale_lines (sale_id,product_type,gross_cents,discount_cents,refunded_net_cents)
 VALUES (902,'retail',2300,0,0)");
$pdo->exec("INSERT INTO payments (sale_id,tender,amount_cents,status) VALUES (902,'cash',2300,'completed')");
$valid=['event_type'=>'sale.completed','source_id'=>902];
$balanceBefore=(int)$pdo->query("SELECT balance_points FROM reward_accounts WHERE household_id=1")->fetchColumn();
$first=bcrf_roost_outbox_handle_test_event($pdo,$valid,1,true);
if ($first['outcome']!=='completed' || $first['replayed'] || $first['ledger_entry_id']<=0)
    throw new RuntimeException('Fresh sale was not awarded.');
$balanceAfter=(int)$pdo->query("SELECT balance_points FROM reward_accounts WHERE household_id=1")->fetchColumn();
if ($balanceAfter-$balanceBefore!==115) throw new RuntimeException('Award points mismatch.');
// No queue acknowledgment is written: retry must identify the committed ledger receipt.
$second=bcrf_roost_outbox_handle_test_event($pdo,$valid,1,true);
if (!$second['replayed'] || $second['ledger_entry_id']!==$first['ledger_entry_id'])
    throw new RuntimeException('Lost acknowledgment replay not recovered.');
if ((int)$pdo->query("SELECT balance_points FROM reward_accounts WHERE household_id=1")->fetchColumn()!==$balanceAfter)
    throw new RuntimeException('Replay changed household balance.');
if ((int)$pdo->query("SELECT COUNT(*) FROM reward_ledger WHERE entry_type='earn_spend' AND source_id='902'")->fetchColumn()!==1)
    throw new RuntimeException('Replay created duplicate earning.');
// Two committed refunds on the same rewarded sale, deliberately delivered out of order.
$lineId=(int)$pdo->query("SELECT id FROM sale_lines WHERE sale_id=902")->fetchColumn();
$pdo->exec("UPDATE sales SET status='partially_refunded' WHERE id=902");
$pdo->exec("INSERT INTO refunds (id,sale_id,net_cents,total_cents) VALUES (9021,902,1000,1000),(9022,902,500,500)");
$pdo->exec("INSERT INTO refund_lines (refund_id,sale_line_id,net_cents) VALUES (9021,$lineId,1000),(9022,$lineId,500)");
$pdo->exec("INSERT INTO refund_payments (refund_id,tender,amount_cents,reference) VALUES (9021,'cash',1000,NULL),(9022,'cash',500,NULL)");
$pdo->exec("UPDATE sale_lines SET refunded_net_cents=1500 WHERE id=$lineId");
$late=['event_type'=>'sale.refunded','source_id'=>9022];
try {
    bcrf_roost_outbox_handle_test_event($pdo,$late,1,true);
    throw new RuntimeException('Out-of-order refund was accepted.');
} catch(BcrfRewardError $expected) {
    if ($expected->getMessage()==='Out-of-order refund was accepted.') throw $expected;
}
$firstRefund=bcrf_roost_outbox_handle_test_event($pdo,['event_type'=>'sale.refunded','source_id'=>9021],1,true);
if ($firstRefund['outcome']!=='completed' || $firstRefund['replayed'])
    throw new RuntimeException('First refund was not processed.');
$secondRefund=bcrf_roost_outbox_handle_test_event($pdo,$late,1,true);
if ($secondRefund['outcome']!=='completed' || $secondRefund['replayed'])
    throw new RuntimeException('Second refund was not processed.');
$balanceRefunded=(int)$pdo->query("SELECT balance_points FROM reward_accounts WHERE household_id=1")->fetchColumn();
if ($balanceAfter-$balanceRefunded!==75) throw new RuntimeException('Refund points reversal mismatch.');
$again=bcrf_roost_outbox_handle_test_event($pdo,$late,1,true);
if (!$again['replayed'] || $again['ledger_entry_id']!==$secondRefund['ledger_entry_id'])
    throw new RuntimeException('Refund retry was not recognized.');
if ((int)$pdo->query("SELECT balance_points FROM reward_accounts WHERE household_id=1")->fetchColumn()!==$balanceRefunded)
    throw new RuntimeException('Refund retry deducted points twice.');
if ((int)$pdo->query("SELECT COUNT(*) FROM reward_refund_processing WHERE refund_id IN (9021,9022)")->fetchColumn()!==2)
    throw new RuntimeException('Missing or duplicate refund receipts.');
echo "Authorized POS handler award, ordered refunds and crash-replay guards passed.\\n";
