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
echo "POS outbox atomicity and deduplication passed.\n";
