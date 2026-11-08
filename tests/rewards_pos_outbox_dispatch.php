<?php
require_once __DIR__.'/../includes/roost-pos-outbox-dispatch.php';
if (bcrf_roost_outbox_dispatch_enabled()!==false)
    throw new RuntimeException('Dispatch unexpectedly enabled.');
$pdo=new PDO(getenv('BCRF_REWARDS_TEST_DSN'),getenv('BCRF_REWARDS_TEST_USER')?:'',getenv('BCRF_REWARDS_TEST_PASSWORD')?:'');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
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
echo "Inert outbox dispatch guards passed.\n";
