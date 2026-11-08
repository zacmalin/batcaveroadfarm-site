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
echo "Inert outbox dispatch guards passed.\n";
