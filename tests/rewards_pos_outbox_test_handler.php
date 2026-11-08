<?php
require_once __DIR__.'/../includes/roost-pos-outbox-test-handler.php';
$pdo=new PDO(getenv('BCRF_REWARDS_TEST_DSN'),getenv('BCRF_REWARDS_TEST_USER')?:'',getenv('BCRF_REWARDS_TEST_PASSWORD')?:'');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
// Independent unsettled sale: other test suites may already have awarded 901.
$pdo->exec("INSERT INTO sales (id,customer_id,status,subtotal_cents,discount_cents,total_cents)
 VALUES (904,1,'completed',1000,0,1000)");
$pdo->exec("INSERT INTO sale_lines (sale_id,product_type,gross_cents,discount_cents,refunded_net_cents)
 VALUES (904,'retail',1000,0,0)");
$pdo->exec("INSERT INTO payments (sale_id,tender,amount_cents,status) VALUES (904,'cash',1000,'pending')");
$event=['event_type'=>'sale.completed','source_id'=>904];
try {
    bcrf_roost_outbox_handle_test_event($pdo,$event,1);
    throw new RuntimeException('Unauthorized test posting accepted.');
} catch(LogicException $expected) {
    if ($expected->getMessage()==='Unauthorized test posting accepted.') throw $expected;
}
// The sale fixture is intentionally unsettled by prior tests. A test-only
// handler must fail closed and leave the reward ledger unchanged.
$before=(int)$pdo->query("SELECT COUNT(*) FROM reward_ledger WHERE source_type='sale' AND source_id='904'")->fetchColumn();
try {
    bcrf_roost_outbox_handle_test_event($pdo,$event,1,true);
    throw new RuntimeException('Unsettled sale was posted.');
} catch(BcrfRewardError $expected) {
    // Expected: payment remains pending.
}
$after=(int)$pdo->query("SELECT COUNT(*) FROM reward_ledger WHERE source_type='sale' AND source_id='904'")->fetchColumn();
if ($before!==$after) throw new RuntimeException('Rejected event modified ledger.');
// A delayed, genuinely pinned sale may post after policy retirement.
$pdo->exec("INSERT INTO sales (id,customer_id,status,subtotal_cents,discount_cents,total_cents)
 VALUES (905,1,'completed',1200,0,1200)");
$pdo->exec("INSERT INTO sale_lines (sale_id,product_type,gross_cents,discount_cents,refunded_net_cents)
 VALUES (905,'retail',1200,0,0)");
$pdo->exec("INSERT INTO payments (sale_id,tender,amount_cents,status)
 VALUES (905,'cash',1200,'completed')");
require_once __DIR__.'/../includes/roost-pos-outbox.php';
$pdo->beginTransaction();
bcrf_roost_pos_outbox_enqueue_pinned_sale($pdo,905,1);
$pdo->commit();
$pdo->exec("UPDATE reward_policies SET status='draft' WHERE id=1");
// Missing or mismatched snapshots must never authorize retired-policy awards.
$pdo->exec("UPDATE reward_pos_outbox SET policy_id=NULL WHERE event_type='sale.completed' AND source_id=905");
try {
    bcrf_roost_outbox_handle_test_event($pdo,['event_type'=>'sale.completed','source_id'=>905],1,true);
    throw new RuntimeException('Null snapshot authorized retired sale award.');
} catch (BcrfRewardError $expected) {}
$pdo->exec("UPDATE reward_pos_outbox SET policy_id=999 WHERE event_type='sale.completed' AND source_id=905");
try {
    bcrf_roost_outbox_handle_test_event($pdo,['event_type'=>'sale.completed','source_id'=>905],1,true);
    throw new RuntimeException('Mismatched snapshot authorized retired sale award.');
} catch (BcrfRewardError $expected) {}
if ((int)$pdo->query("SELECT COUNT(*) FROM reward_ledger WHERE entry_type='earn_spend' AND source_id='905'")->fetchColumn()!==0)
    throw new RuntimeException('Rejected snapshot modified rewards ledger.');
$pdo->exec("UPDATE reward_pos_outbox SET policy_id=1 WHERE event_type='sale.completed' AND source_id=905");
$delayed=bcrf_roost_outbox_handle_test_event($pdo,['event_type'=>'sale.completed','source_id'=>905],1,true);
if ($delayed['outcome']!=='completed' || $delayed['replayed'])
    throw new RuntimeException('Pinned delayed sale failed after policy retirement.');
$pdo->exec("UPDATE reward_policies SET status='active' WHERE id=1");
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
$pdo->exec("INSERT INTO refunds (id,sale_id,net_cents,total_cents) VALUES (9021,902,1000,1000),(9022,902,499,499)");
$pdo->exec("INSERT INTO refund_lines (refund_id,sale_line_id,net_cents) VALUES (9021,$lineId,1000),(9022,$lineId,499)");
$pdo->exec("INSERT INTO refund_payments (refund_id,tender,amount_cents,reference) VALUES (9021,'cash',1000,NULL),(9022,'cash',499,NULL)");
$pdo->exec("UPDATE sale_lines SET refunded_net_cents=1499 WHERE id=$lineId");
$late=['event_type'=>'sale.refunded','source_id'=>9022];
try {
    bcrf_roost_outbox_handle_test_event($pdo,$late,1,true);
    throw new RuntimeException('Out-of-order refund was accepted.');
} catch(BcrfRewardError $expected) {
    if ($expected->getMessage()==='Out-of-order refund was accepted.') throw $expected;
}
// Retiring the earning policy must not prevent a legitimate refund reversal.
$pdo->exec("UPDATE reward_policies SET status='draft' WHERE id=1");
try {
    bcrf_roost_outbox_handle_test_event($pdo,['event_type'=>'sale.refunded','source_id'=>9021],2,true);
    throw new RuntimeException('Mismatched historical refund policy accepted.');
} catch (BcrfRewardError|RuntimeException $expected) {
    if ($expected->getMessage()==='Mismatched historical refund policy accepted.') throw $expected;
}
// Positive-path inert dispatch preflight, using this test's complete
// committed sale earning and refund fixtures (not another suite's state).
require_once __DIR__.'/../includes/roost-pos-outbox-dispatch.php';
$pdo->beginTransaction();
bcrf_roost_pos_outbox_enqueue($pdo,'sale.refunded',9021);
$pdo->commit();
$refundQueuedId=(int)$pdo->query("SELECT id FROM reward_pos_outbox
 WHERE event_type='sale.refunded' AND source_id=9021")->fetchColumn();
$refundPlan=bcrf_roost_outbox_dispatch_refund_preflight($pdo,
    ['id'=>$refundQueuedId,'event_type'=>'sale.refunded','source_id'=>9021]);
if (($refundPlan['action']??null)!=='posting_required' ||
    (int)($refundPlan['event']['policy_id']??0)!==1 ||
    ($refundPlan['event']['entry_type']??null)!=='refund_reversal')
    throw new RuntimeException('Retired original-policy refund dispatch preflight failed.');
$pdo->exec("DELETE FROM reward_pos_outbox WHERE id=$refundQueuedId");
$firstRefund=bcrf_roost_outbox_handle_test_event($pdo,['event_type'=>'sale.refunded','source_id'=>9021],1,true);
$pdo->exec("UPDATE reward_policies SET status='active' WHERE id=1");
if ($firstRefund['outcome']!=='completed' || $firstRefund['replayed'])
    throw new RuntimeException('First refund was not processed.');
// A queued refund retried after its ledger reversal committed must be
// acknowledged from its durable receipt, never posted a second time.
$pdo->beginTransaction();
bcrf_roost_pos_outbox_enqueue($pdo,'sale.refunded',9021);
$pdo->commit();
$refundReplayOutboxId=(int)$pdo->query("SELECT id FROM reward_pos_outbox
 WHERE event_type='sale.refunded' AND source_id=9021")->fetchColumn();
$replayBalance=(int)$pdo->query("SELECT balance_points FROM reward_accounts WHERE household_id=1")->fetchColumn();
$refundReplayPlan=bcrf_roost_outbox_dispatch_refund_preflight($pdo,
    ['id'=>$refundReplayOutboxId,'event_type'=>'sale.refunded','source_id'=>9021]);
if (($refundReplayPlan['action']??null)!=='already_posted' ||
    (int)($refundReplayPlan['event']['policy_id']??0)!==1 ||
    empty($refundReplayPlan['receipt']['ledger_entry_id']))
    throw new RuntimeException('Committed refund replay receipt not recognized by dispatch preflight.');
if ((int)$pdo->query("SELECT balance_points FROM reward_accounts WHERE household_id=1")->fetchColumn()!==$replayBalance)
    throw new RuntimeException('Read-only refund replay preflight changed household balance.');
// Corrupting the durable receipt must never produce an already-posted
// acknowledgment, even when the ledger reversal itself still exists.
$pdo->exec("UPDATE reward_refund_processing SET points_reversed=points_reversed+1 WHERE refund_id=9021");
try {
    bcrf_roost_outbox_dispatch_refund_preflight($pdo,
        ['id'=>$refundReplayOutboxId,'event_type'=>'sale.refunded','source_id'=>9021]);
    throw new RuntimeException('Corrupted refund receipt was acknowledged.');
} catch (RuntimeException $expected) {
    if ($expected->getMessage()!=='Committed refund ledger receipt mismatch') throw $expected;
} finally {
    $pdo->exec("UPDATE reward_refund_processing SET points_reversed=points_reversed-1 WHERE refund_id=9021");
}
$restored=bcrf_roost_outbox_dispatch_refund_preflight($pdo,
    ['id'=>$refundReplayOutboxId,'event_type'=>'sale.refunded','source_id'=>9021]);
if (($restored['action']??null)!=='already_posted')
    throw new RuntimeException('Restored refund receipt was not recognized.');
$pdo->exec("DELETE FROM reward_pos_outbox WHERE id=$refundReplayOutboxId");
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
// A lost sale acknowledgment can arrive after its sale was refunded.
$afterRefundSale=bcrf_roost_outbox_handle_test_event($pdo,$valid,1,true);
if (!$afterRefundSale['replayed'] || $afterRefundSale['ledger_entry_id']!==$first['ledger_entry_id'])
    throw new RuntimeException('Refunded sale receipt replay failed.');
if ((int)$pdo->query("SELECT balance_points FROM reward_accounts WHERE household_id=1")->fetchColumn()!==$balanceRefunded)
    throw new RuntimeException('Refunded sale replay changed balance.');
if ((int)$pdo->query("SELECT COUNT(*) FROM reward_refund_processing WHERE refund_id IN (9021,9022)")->fetchColumn()!==2)
    throw new RuntimeException('Missing or duplicate refund receipts.');
// From $8.01 remaining to $8.00, one cent crosses no point threshold but requires a receipt.
$pdo->exec("INSERT INTO refunds (id,sale_id,net_cents,total_cents) VALUES (9023,902,1,1)");
$pdo->exec("INSERT INTO refund_lines (refund_id,sale_line_id,net_cents) VALUES (9023,$lineId,1)");
$pdo->exec("INSERT INTO refund_payments (refund_id,tender,amount_cents,reference) VALUES (9023,'cash',1,NULL)");
$pdo->exec("UPDATE sale_lines SET refunded_net_cents=1500 WHERE id=$lineId");
$zero=['event_type'=>'sale.refunded','source_id'=>9023];
$zeroFirst=bcrf_roost_outbox_handle_test_event($pdo,$zero,1,true);
if ($zeroFirst['outcome']!=='completed' || $zeroFirst['replayed'] ||
    $zeroFirst['points_reversed']!==0)
    throw new RuntimeException('Zero-point refund receipt not committed.');
$zeroAgain=bcrf_roost_outbox_handle_test_event($pdo,$zero,1,true);
if ($zeroAgain['outcome']!=='completed' || !$zeroAgain['replayed'] ||
    $zeroAgain['points_reversed']!==0)
    throw new RuntimeException('Zero-point refund retry was not acknowledged.');
if ((int)$pdo->query("SELECT balance_points FROM reward_accounts WHERE household_id=1")->fetchColumn()!==$balanceRefunded)
    throw new RuntimeException('Zero-point refund changed balance.');
if ((int)$pdo->query("SELECT COUNT(*) FROM reward_refund_processing WHERE refund_id=9023 AND points_reversed=0 AND reversal_entry_id IS NULL")->fetchColumn()!==1)
    throw new RuntimeException('Zero-point refund receipt duplicated or missing.');
// The inert queued dispatcher must recognize a durable zero-point receipt
// without attempting to recalculate an already-processed refund.
$pdo->beginTransaction();
bcrf_roost_pos_outbox_enqueue($pdo,'sale.refunded',9023);
$pdo->commit();
$zeroQueuedId=(int)$pdo->query("SELECT id FROM reward_pos_outbox
 WHERE event_type='sale.refunded' AND source_id=9023")->fetchColumn();
$zeroPlan=bcrf_roost_outbox_dispatch_refund_preflight($pdo,
    ['id'=>$zeroQueuedId,'event_type'=>'sale.refunded','source_id'=>9023]);
if (($zeroPlan['action']??null)!=='zero_point_already_processed' ||
    (int)($zeroPlan['refund_id']??0)!==9023 ||
    (int)($zeroPlan['original_earning_entry_id']??0)!==(int)$first['ledger_entry_id'])
    throw new RuntimeException('Zero-point committed refund receipt not recognized.');
if ((int)$pdo->query("SELECT balance_points FROM reward_accounts WHERE household_id=1")->fetchColumn()!==$balanceRefunded)
    throw new RuntimeException('Zero-point queued replay preflight changed balance.');
$pdo->exec("DELETE FROM reward_pos_outbox WHERE id=$zeroQueuedId");
// Queue-level crash-after-commit simulation, still no automatic dispatcher.
// Earlier integration scripts leave queue fixtures behind; isolate this scenario.
$pdo->exec('DELETE FROM reward_pos_outbox');
$pdo->exec("INSERT INTO sales (id,customer_id,status,subtotal_cents,discount_cents,total_cents)
 VALUES (903,1,'completed',2100,0,2100)");
$pdo->exec("INSERT INTO sale_lines (sale_id,product_type,gross_cents,discount_cents,refunded_net_cents)
 VALUES (903,'retail',2100,0,0)");
$pdo->exec("INSERT INTO payments (sale_id,tender,amount_cents,status) VALUES (903,'cash',2100,'completed')");
$queuedSale=['event_type'=>'sale.completed','source_id'=>903];
$queuedPosted=bcrf_roost_outbox_handle_test_event($pdo,$queuedSale,1,true);
if ($queuedPosted['replayed'] || $queuedPosted['outcome']!=='completed')
    throw new RuntimeException('Queue fixture sale was not posted.');
require_once __DIR__.'/../includes/roost-pos-outbox.php';
require_once __DIR__.'/../includes/roost-pos-outbox-worker.php';
$pdo->beginTransaction();
bcrf_roost_pos_outbox_enqueue($pdo,'sale.completed',903);
$pdo->commit();
$claim=bcrf_roost_outbox_claim($pdo);
if (!$claim || $claim['event_type']!=='sale.completed' || (int)$claim['source_id']!==903)
    throw new RuntimeException('Expected sale queue claim missing.');
$recovered=bcrf_roost_outbox_handle_test_event($pdo,$queuedSale,1,true);
if (!$recovered['replayed'] || $recovered['ledger_entry_id']!==$queuedPosted['ledger_entry_id'])
    throw new RuntimeException('Claimed event did not recover committed award.');
// Simulate crash before acknowledgment, then expire lease and reclaim.
$pdo->prepare("UPDATE reward_pos_outbox SET available_at=UTC_TIMESTAMP(3)-INTERVAL 1 SECOND WHERE id=?")->execute([$claim['id']]);
$retry=bcrf_roost_outbox_claim($pdo);
if (!$retry || (int)$retry['id']!==(int)$claim['id'] || (int)$retry['attempts']!==2)
    throw new RuntimeException('Expired queue claim was not recovered.');
if (bcrf_roost_outbox_finish($pdo,(int)$claim['id'],1,'completed'))
    throw new RuntimeException('Stale queue attempt acknowledged.');
$recoveredAgain=bcrf_roost_outbox_handle_test_event($pdo,$queuedSale,1,true);
if (!$recoveredAgain['replayed'] || !bcrf_roost_outbox_finish($pdo,(int)$retry['id'],2,'completed'))
    throw new RuntimeException('Recovered queue event not acknowledged.');
if ((int)$pdo->query("SELECT COUNT(*) FROM reward_ledger WHERE source_type='sale' AND source_id='903'")->fetchColumn()!==1)
    throw new RuntimeException('Queue retry duplicated ledger award.');
echo "POS handler refund and queue crash-recovery guards passed.\\n";
