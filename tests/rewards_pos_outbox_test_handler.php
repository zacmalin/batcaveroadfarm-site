<?php
require_once __DIR__.'/../includes/roost-pos-outbox-test-handler.php';
$pdo=new PDO(getenv('BCRF_REWARDS_TEST_DSN'),getenv('BCRF_REWARDS_TEST_USER')?:'',getenv('BCRF_REWARDS_TEST_PASSWORD')?:'');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$event=['event_type'=>'sale.completed','source_id'=>901];
try {
    bcrf_roost_outbox_handle_test_event($pdo,$event,1);
    throw new RuntimeException('Unauthorized test posting accepted.');
} catch(LogicException $expected) {
    if ($expected->getMessage()==='Unauthorized test posting accepted.') throw $expected;
}
// The sale fixture is intentionally unsettled by prior tests. A test-only
// handler must fail closed and leave the reward ledger unchanged.
$before=(int)$pdo->query("SELECT COUNT(*) FROM reward_ledger WHERE source_type='sale' AND source_id='901'")->fetchColumn();
try {
    bcrf_roost_outbox_handle_test_event($pdo,$event,1,true);
    throw new RuntimeException('Unsettled sale was posted.');
} catch(BcrfRewardError $expected) {
    // Expected: payment remains pending.
}
$after=(int)$pdo->query("SELECT COUNT(*) FROM reward_ledger WHERE source_type='sale' AND source_id='901'")->fetchColumn();
if ($before!==$after) throw new RuntimeException('Rejected event modified ledger.');
echo "Explicitly authorized POS test handler guards passed.\n";
