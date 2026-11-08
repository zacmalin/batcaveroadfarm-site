<?php
require_once __DIR__.'/../includes/roost-pos-outbox-receipt.php';
$pdo=new PDO(getenv('BCRF_REWARDS_TEST_DSN'),getenv('BCRF_REWARDS_TEST_USER')?:'',getenv('BCRF_REWARDS_TEST_PASSWORD')?:'');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$event=['household_id'=>1,'customer_id'=>1,'policy_id'=>1,'entry_type'=>'earn_spend',
    'points_delta'=>100,'source_type'=>'sale','source_id'=>'901',
    'idempotency_key'=>'roost:pos:sale:901'];
if (bcrf_roost_outbox_ledger_receipt($pdo,$event)!==null)
    throw new RuntimeException('Unposted event incorrectly acknowledged.');
$pdo->exec("INSERT INTO reward_ledger
(household_id,customer_id,policy_id,entry_type,points_delta,source_type,source_id,idempotency_key,created_at)
VALUES (1,1,1,'earn_spend',100,'sale','901','roost:pos:sale:901',UTC_TIMESTAMP(3))");
$receipt=bcrf_roost_outbox_ledger_receipt($pdo,$event);
if (!$receipt || !$receipt['replayed'] || $receipt['ledger_entry_id']<=0)
    throw new RuntimeException('Committed receipt not recognized.');
$bad=$event;$bad['points_delta']=101;
try {bcrf_roost_outbox_ledger_receipt($pdo,$bad);throw new RuntimeException('Mismatched receipt accepted.');}
catch(RuntimeException $expected) {if ($expected->getMessage()==='Mismatched receipt accepted.') throw $expected;}
$pdo->beginTransaction();
try {bcrf_roost_outbox_ledger_receipt($pdo,$event);throw new RuntimeException('Open transaction accepted.');}
catch(LogicException $expected) {$pdo->rollBack();}
echo "Outbox ledger receipt recovery guards passed.\n";
