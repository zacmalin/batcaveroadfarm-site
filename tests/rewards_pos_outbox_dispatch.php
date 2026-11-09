<?php
require_once __DIR__.'/../includes/roost-pos-outbox-dispatch.php';
require_once __DIR__.'/../includes/roost-pos-dispatch-runner.php';
if (bcrf_roost_outbox_dispatch_enabled()!==false)
    throw new RuntimeException('Dispatch unexpectedly enabled.');
$pdo=new PDO(getenv('BCRF_REWARDS_TEST_DSN'),getenv('BCRF_REWARDS_TEST_USER')?:'',getenv('BCRF_REWARDS_TEST_PASSWORD')?:'');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
if (bcrf_roost_pos_dispatch_once($pdo)!=='disabled')
    throw new RuntimeException('Production POS dispatcher unexpectedly activated.');
$pdo->beginTransaction();
try {
    bcrf_roost_outbox_dispatch_preflight($pdo,['event_type'=>'sale.completed','source_id'=>1],1);
    throw new RuntimeException('Dispatch accepted open transaction.');
} catch(LogicException $expected) {
    if ($expected->getMessage()==='Dispatch accepted open transaction.') throw $expected;
    $pdo->rollBack();
}
try {
    bcrf_roost_outbox_dispatch_preflight($pdo,['event_type'=>'sale.completed','source_id'=>1],0);
    throw new RuntimeException('Dispatch accepted missing policy.');
} catch(InvalidArgumentException $expected) {
    if ($expected->getMessage()==='Dispatch accepted missing policy.') throw $expected;
}
// Queued-sale dispatch must verify the actual outbox row identity before
// deriving its immutable policy; caller-provided event source is untrusted.
$pdo->beginTransaction();
require_once __DIR__.'/../includes/roost-pos-outbox.php';
bcrf_roost_pos_outbox_enqueue_pinned_sale($pdo,990221,1);
$pdo->commit();
$queuedId=(int)$pdo->query("SELECT id FROM reward_pos_outbox WHERE event_type='sale.completed' AND source_id=990221")->fetchColumn();
try {
    bcrf_roost_outbox_dispatch_pinned_sale_preflight($pdo,['id'=>$queuedId,'event_type'=>'sale.completed','source_id'=>990222]);
    throw new RuntimeException('Mismatched queued sale identity accepted.');
} catch(RuntimeException $e) {
    if($e->getMessage()==='Mismatched queued sale identity accepted.') throw $e;
}
// A legacy/unpinned queued sale must fail before any posting preview.
$pdo->exec("UPDATE reward_pos_outbox SET policy_id=NULL WHERE id=$queuedId");
try {
    bcrf_roost_outbox_dispatch_pinned_sale_preflight($pdo,['id'=>$queuedId,'event_type'=>'sale.completed','source_id'=>990221]);
    throw new RuntimeException('Missing queued policy snapshot accepted.');
} catch(RuntimeException $e) {
    if($e->getMessage()==='Missing queued policy snapshot accepted.') throw $e;
    if($e->getMessage()!=='Queued sale has no pinned policy; manual reconciliation required.') throw $e;
}
$pdo->exec("DELETE FROM reward_pos_outbox WHERE id=$queuedId");
// Refund preflight must reject substituted outbox source identities.
$pdo->beginTransaction();
bcrf_roost_pos_outbox_enqueue($pdo,'sale.refunded',9021);
$pdo->commit();
$refundOutboxId=(int)$pdo->query("SELECT id FROM reward_pos_outbox WHERE event_type='sale.refunded' AND source_id=9021")->fetchColumn();
try {
    bcrf_roost_outbox_dispatch_refund_preflight($pdo,['id'=>$refundOutboxId,'event_type'=>'sale.refunded','source_id'=>9022]);
    throw new RuntimeException('Mismatched queued refund identity accepted.');
} catch(RuntimeException $e) {
    if($e->getMessage()==='Mismatched queued refund identity accepted.') throw $e;
    if($e->getMessage()!=='Queued refund source does not match outbox identity.') throw $e;
}
// Missing original earning must fail closed; no active or retired policy
// may be guessed for an orphaned refund. Historical-policy resolution is
// tested separately with its dedicated original-earning fixture.
$pdo->exec("UPDATE reward_policies SET status='retired' WHERE id=1");
try {
    try {
        bcrf_roost_outbox_dispatch_refund_preflight($pdo,
            ['id'=>$refundOutboxId,'event_type'=>'sale.refunded','source_id'=>9021]);
        throw new RuntimeException('Orphaned queued refund was accepted.');
    } catch(RuntimeException $e) {
        if ($e->getMessage()!=='Refund requires one original sale policy.') throw $e;
    }
} finally {
    $pdo->exec("UPDATE reward_policies SET status='active' WHERE id=1");
}
$pdo->exec("DELETE FROM reward_pos_outbox WHERE id=$refundOutboxId");
echo "Inert outbox dispatch guards passed.\n";
