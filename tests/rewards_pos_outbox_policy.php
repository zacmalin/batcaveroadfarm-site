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
echo "POS policy resolver guards passed.\\n";
