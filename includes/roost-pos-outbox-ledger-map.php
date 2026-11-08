<?php
// Trusted mapping from a validated, read-only POS preview to a ledger event.
// No mutation, worker dispatch, or policy activation happens in this file.
require_once __DIR__.'/roost-pos-outbox-preview.php';
function bcrf_roost_outbox_ledger_event(array $preview, int $policyId): array {
    if ($policyId <= 0) throw new InvalidArgumentException('Approved policy ID required.');
    $kind=$preview['kind']??null;
    if ($kind==='sale.completed') {
        $id=filter_var($preview['sale_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $household=filter_var($preview['household_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $customer=filter_var($preview['customer_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $points=filter_var($preview['points']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$id || !$household || !$customer || !$points) throw new InvalidArgumentException('Invalid sale award preview.');
        return ['household_id'=>$household,'customer_id'=>$customer,'policy_id'=>$policyId,
            'entry_type'=>'earn_spend','points_delta'=>$points,'source_type'=>'sale',
            'source_id'=>(string)$id,'idempotency_key'=>'roost:pos:sale:'.$id];
    }
    if ($kind==='sale.refunded') {
        $id=filter_var($preview['refund_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $household=filter_var($preview['household_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $original=filter_var($preview['original_earning_entry_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $due=filter_var($preview['points_to_reverse']??null,FILTER_VALIDATE_INT);
        if (!$id || !$household || !$original || $due===false || $due<0)
            throw new InvalidArgumentException('Invalid refund reversal preview.');
        if ($due===0) return ['skip'=>true,'reason'=>'zero_point_refund_requires_receipt',
            'refund_id'=>$id,'original_earning_entry_id'=>$original];
        return ['household_id'=>$household,'policy_id'=>$policyId,
            'entry_type'=>'refund_reversal','points_delta'=>-$due,'source_type'=>'refund',
            'source_id'=>(string)$id,'related_entry_id'=>$original,
            'idempotency_key'=>'roost:pos:refund:'.$id];
    }
    throw new InvalidArgumentException('Unsupported POS preview kind.');
}
