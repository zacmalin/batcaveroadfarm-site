<?php
require_once __DIR__.'/../includes/roost-pos-outbox-ledger-map.php';
function check(bool $v,string $m):void {if(!$v)throw new RuntimeException($m);}
$sale=['kind'=>'sale.completed','sale_id'=>91,'household_id'=>4,'customer_id'=>8,'points'=>17];
$a=bcrf_roost_outbox_ledger_event($sale,3);
$b=bcrf_roost_outbox_ledger_event($sale,3);
check($a===$b && $a['idempotency_key']==='roost:pos:sale:91' && $a['points_delta']===17,'Sale mapping not stable.');
$refund=['kind'=>'sale.refunded','refund_id'=>52,'household_id'=>4,'original_earning_entry_id'=>13,'points_to_reverse'=>6];
$r=bcrf_roost_outbox_ledger_event($refund,3);
check($r['idempotency_key']==='roost:pos:refund:52' && $r['points_delta']===-6 && $r['related_entry_id']===13,'Refund mapping incorrect.');
$refund['points_to_reverse']=0;
$zero=bcrf_roost_outbox_ledger_event($refund,3);
check(($zero['skip']??false)===true && $zero['reason']==='zero_point_refund_requires_receipt','Zero-point refund not gated.');
foreach ([[$sale,0],[['kind'=>'sale.completed','sale_id'=>91,'household_id'=>4,'customer_id'=>8,'points'=>0],3],[['kind'=>'sale.refunded','refund_id'=>52,'household_id'=>4,'original_earning_entry_id'=>13,'points_to_reverse'=>-1],3]] as [$event,$policy]) {
    try {bcrf_roost_outbox_ledger_event($event,$policy);throw new RuntimeException('Invalid mapping accepted.');}
    catch(InvalidArgumentException $expected) {}
}
echo "POS ledger mapping guards passed.\n";
