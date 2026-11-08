<?php
require_once __DIR__.'/../includes/roost-pos-outbox-policy.php';
$pdo=new PDO(getenv('BCRF_REWARDS_TEST_DSN'),getenv('BCRF_REWARDS_TEST_USER')?:'',getenv('BCRF_REWARDS_TEST_PASSWORD')?:'');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("UPDATE reward_policies SET status='draft' WHERE id<>1");
$pdo->exec("UPDATE reward_policies SET status='active',effective_at=NULL WHERE id=1");
$sale=['event_type'=>'sale.completed','source_id'=>902];
if (bcrf_roost_outbox_resolve_policy($pdo,$sale)!==1) throw new RuntimeException('Active sale policy missing.');
$refund=['event_type'=>'sale.refunded','source_id'=>9021];
if (bcrf_roost_outbox_resolve_policy($pdo,$refund)!==1) throw new RuntimeException('Refund did not inherit original policy.');
$pdo->exec("UPDATE reward_policies SET status='draft' WHERE id=1");
try { bcrf_roost_outbox_resolve_policy($pdo,$sale); throw new RuntimeException('Missing policy accepted.'); }
catch(RuntimeException $e) { if ($e->getMessage()==='Missing policy accepted.') throw $e; }
if (bcrf_roost_outbox_resolve_policy($pdo,$refund)!==1) throw new RuntimeException('Refund lost historical policy.');
// Refund policy is inherited from the original sale earning, even when
// the earning policy is retired. Never substitute a newly active policy.
$pdo->exec("UPDATE reward_policies SET status='retired' WHERE id=1");
if (bcrf_roost_outbox_resolve_policy($pdo,$refund)!==1)
    throw new RuntimeException('Retired original refund policy was not preserved.');
$pdo->exec("UPDATE reward_policies SET status='active' WHERE id=1");
$pdo->exec("UPDATE reward_policies SET status='active' WHERE id=1");
$pdo->exec("INSERT INTO reward_policies (id,version,config_json,status,effective_at,created_at) VALUES (99,'policy-test-duplicate','{}','active',NULL,UTC_TIMESTAMP(3))");
try { bcrf_roost_outbox_resolve_policy($pdo,$sale); throw new RuntimeException('Ambiguous policy accepted.'); }
catch(RuntimeException $e) { if ($e->getMessage()==='Ambiguous policy accepted.') throw $e; }
$pdo->exec("DELETE FROM reward_policies WHERE id=99");
// A delayed sale may not silently adopt a policy created after its queue entry.
$pdo->exec("INSERT INTO reward_pos_outbox (event_type,source_id,created_at)
 VALUES ('sale.completed',990091,DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY))");
$outboxId=(int)$pdo->lastInsertId();
try {
    bcrf_roost_outbox_assert_sale_policy_not_newer($pdo,$outboxId,1);
    throw new RuntimeException('Newer policy was accepted for old queued sale.');
} catch(RuntimeException $e) {
    if($e->getMessage()==='Newer policy was accepted for old queued sale.') throw $e;
}
$pdo->exec("UPDATE reward_pos_outbox SET created_at=DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 1 DAY) WHERE id=$outboxId");
bcrf_roost_outbox_assert_sale_policy_not_newer($pdo,$outboxId,1);
$pdo->exec("UPDATE reward_policies SET effective_at=DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 2 DAY) WHERE id=1");
try {
    bcrf_roost_outbox_assert_sale_policy_not_newer($pdo,$outboxId,1);
    throw new RuntimeException('Future effective policy was accepted.');
} catch(RuntimeException $e) {
    if($e->getMessage()==='Future effective policy was accepted.') throw $e;
}
$pdo->exec("UPDATE reward_policies SET effective_at=NULL WHERE id=1");
$pdo->exec("DELETE FROM reward_pos_outbox WHERE id=$outboxId");
$pdo->beginTransaction();
require_once __DIR__.'/../includes/roost-pos-outbox.php';
bcrf_roost_pos_outbox_enqueue_pinned_sale($pdo,990092,1);
$pdo->commit();
$pinnedId=(int)$pdo->query("SELECT id FROM reward_pos_outbox WHERE event_type='sale.completed' AND source_id=990092")->fetchColumn();
if(bcrf_roost_outbox_pinned_sale_policy($pdo,$pinnedId)!==1)
    throw new RuntimeException('Pinned sale policy was not recovered.');
if(bcrf_roost_outbox_authorize_sale_policy($pdo,$pinnedId,1)!==1)
    throw new RuntimeException('Matching sale snapshot was rejected.');
try {
    bcrf_roost_outbox_authorize_sale_policy($pdo,$pinnedId,2);
    throw new RuntimeException('Mismatched sale snapshot was accepted.');
} catch(RuntimeException $e) {
    if($e->getMessage()==='Mismatched sale snapshot was accepted.') throw $e;
}
$pdo->exec("UPDATE reward_policies SET status='draft' WHERE id=1");
if(bcrf_roost_outbox_pinned_sale_policy($pdo,$pinnedId)!==1)
    throw new RuntimeException('Retired pinned sale policy was replaced.');
$pdo->exec("UPDATE reward_policies SET status='active' WHERE id=1");
$pdo->exec("UPDATE reward_pos_outbox SET policy_id=NULL WHERE id=$pinnedId");
try {
    bcrf_roost_outbox_pinned_sale_policy($pdo,$pinnedId);
    throw new RuntimeException('Unpinned sale accepted.');
} catch(RuntimeException $e) {
    if($e->getMessage()==='Unpinned sale accepted.') throw $e;
}
try {
    bcrf_roost_outbox_authorize_sale_policy($pdo,$pinnedId,1);
    throw new RuntimeException('Null sale snapshot was authorized.');
} catch(RuntimeException $e) {
    if($e->getMessage()==='Null sale snapshot was authorized.') throw $e;
}
$pdo->exec("DELETE FROM reward_pos_outbox WHERE id=$pinnedId");
echo "POS policy resolver guards passed.\\n";
