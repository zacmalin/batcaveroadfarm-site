<?php
require_once __DIR__.'/../includes/roost-pos-outbox.php';
$dsn=getenv('BCRF_REWARDS_TEST_DSN');
if (!$dsn) { fwrite(STDERR,"Missing disposable test DSN\n"); exit(2); }
$pdo=new PDO($dsn,getenv('BCRF_REWARDS_TEST_USER')?:'',getenv('BCRF_REWARDS_TEST_PASSWORD')?:'');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$migration=require __DIR__.'/../admin/migrations/0019_roost_pos_outbox.php';
$migration($pdo);
$pdo->exec('DELETE FROM reward_pos_outbox');
function rr_check(bool $ok,string $msg):void {if(!$ok) throw new RuntimeException($msg);}
try {bcrf_roost_pos_outbox_enqueue($pdo,'sale.completed',7);throw new RuntimeException('Transaction guard failed');}
catch(LogicException $expected) {}
$pdo->beginTransaction();
bcrf_roost_pos_outbox_enqueue($pdo,'sale.completed',7);
bcrf_roost_pos_outbox_enqueue($pdo,'sale.completed',7);
$pdo->rollBack();
rr_check((int)$pdo->query('SELECT COUNT(*) FROM reward_pos_outbox')->fetchColumn()===0,'Rolled back event persisted.');
$pdo->beginTransaction();
bcrf_roost_pos_outbox_enqueue($pdo,'sale.completed',7);
bcrf_roost_pos_outbox_enqueue($pdo,'sale.completed',7);
bcrf_roost_pos_outbox_enqueue($pdo,'sale.refunded',9);
$pdo->commit();
rr_check((int)$pdo->query('SELECT COUNT(*) FROM reward_pos_outbox')->fetchColumn()===2,'Outbox event deduplication failed.');
$pdo->beginTransaction();
try {bcrf_roost_pos_outbox_enqueue($pdo,'bad',7);throw new RuntimeException('Invalid event accepted');}
catch(InvalidArgumentException $expected) {}
$pdo->rollBack();
// Pinned sale policy is immutable, including across duplicate enqueue attempts.
$pdo->exec("UPDATE reward_policies SET status='active',effective_at=NULL WHERE id=1");
$pdo->beginTransaction();
bcrf_roost_pos_outbox_enqueue_pinned_sale($pdo,990111,1);
bcrf_roost_pos_outbox_enqueue_pinned_sale($pdo,990111,1);
$pdo->commit();
rr_check((int)$pdo->query("SELECT policy_id FROM reward_pos_outbox WHERE event_type='sale.completed' AND source_id=990111")->fetchColumn()===1,
    'Sale policy snapshot was not persisted.');
$pdo->beginTransaction();
try {
    bcrf_roost_pos_outbox_enqueue_pinned_sale($pdo,7,1);
    throw new RuntimeException('Unpinned existing event silently upgraded.');
} catch(RuntimeException $expected) {
    if($expected->getMessage()==='Unpinned existing event silently upgraded.') throw $expected;
}
$pdo->rollBack();
rr_check($pdo->query("SELECT policy_id FROM reward_pos_outbox WHERE event_type='sale.completed' AND source_id=7")->fetchColumn()===null,
    'Existing unpinned event was mutated.');
// A concurrent policy retirement cannot overtake a transaction that
// validated and pinned the sale policy. Use a second connection and a
// short lock timeout; never wait indefinitely in CI.
$other=new PDO($dsn,getenv('BCRF_REWARDS_TEST_USER')?:'',getenv('BCRF_REWARDS_TEST_PASSWORD')?:'');
$other->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$other->exec('SET SESSION innodb_lock_wait_timeout=1');
$pdo->beginTransaction();
bcrf_roost_pos_outbox_enqueue_pinned_sale($pdo,990112,1);
$blocked=false;
try {
    $other->exec("UPDATE reward_policies SET status='retired' WHERE id=1");
} catch(PDOException $e) {
    if (($e->errorInfo[1]??null)!==1205) throw $e;
    $blocked=true;
}
$pdo->rollBack();
rr_check($blocked,'Concurrent retirement was not blocked by pinned policy lock.');
rr_check((int)$pdo->query("SELECT COUNT(*) FROM reward_pos_outbox WHERE source_id=990112")->fetchColumn()===0,
    'Rolled-back concurrency fixture persisted.');
// Once retirement commits, a later sale must not pin that policy.
$other->exec("UPDATE reward_policies SET status='retired' WHERE id=1");
$pdo->beginTransaction();
$rejected=false;
try {
    bcrf_roost_pos_outbox_enqueue_pinned_sale($pdo,990113,1);
} catch(RuntimeException $e) {
    if ($e->getMessage()!=='Cannot pin inactive or future reward policy.') throw $e;
    $rejected=true;
}
$pdo->rollBack();
rr_check($rejected,'Retired policy was accepted for a new sale.');
rr_check((int)$pdo->query("SELECT COUNT(*) FROM reward_pos_outbox WHERE source_id=990113")->fetchColumn()===0,
    'Rejected retired-policy sale left an outbox event.');
$other->exec("UPDATE reward_policies SET status='active' WHERE id=1");
// Reproduce opposing lock order: POS holds a source sale row and then
// needs policy; maintenance holds policy and then needs that sale row.
// MySQL must detect the cycle, not allow both transactions to commit.
$pdo->exec("INSERT INTO sales (id,customer_id,status,subtotal_cents,discount_cents,total_cents)
    VALUES (990114,1,'completed',100,0,100)");
$pdo->beginTransaction();
$pdo->query("SELECT id FROM sales WHERE id=990114 FOR UPDATE")->fetchColumn();
$other->beginTransaction();
$other->query("SELECT id FROM reward_policies WHERE id=1 FOR UPDATE")->fetchColumn();
$other->exec('SET SESSION innodb_lock_wait_timeout=1');
$timedOut=false;
try {
    $other->query("SELECT id FROM sales WHERE id=990114 FOR UPDATE")->fetchColumn();
} catch(PDOException $e) {
    if (($e->errorInfo[1]??null)!==1205) throw $e;
    $timedOut=true;
}
rr_check($timedOut,'Opposite lock-order fixture did not block on sale row.');
$other->rollBack();
$pdo->rollBack();
$pdo->exec('DELETE FROM sales WHERE id=990114');
$pdo->exec("DELETE FROM reward_pos_outbox WHERE source_id=990111");
echo "POS outbox atomicity and deduplication passed.\n";
