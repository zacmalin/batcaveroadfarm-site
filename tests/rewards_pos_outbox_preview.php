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
echo "POS event preview input guards passed.\n";
