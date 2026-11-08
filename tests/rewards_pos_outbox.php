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
$pdo->exec("DELETE FROM reward_pos_outbox WHERE source_id=990111");
echo "POS outbox atomicity and deduplication passed.\n";
