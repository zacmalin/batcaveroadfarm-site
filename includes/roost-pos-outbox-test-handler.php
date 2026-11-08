<?php
// Trusted, explicitly opt-in, single-event handler for controlled tests only.
// No route, scheduler, queue claim, or automatic POS integration calls this.
// Posting is revalidated under locks by bcrf_reward_post(); the preview alone
// is never trusted as authority to change a balance.
require_once __DIR__.'/roost-pos-outbox-dispatch.php';
require_once __DIR__.'/rewards.php';
require_once __DIR__.'/roost-refund-processing.php';
function bcrf_roost_outbox_handle_test_event(PDO $pdo, array $event, int $policyId, bool $explicitTestAuthorization=false): array {
    if (!$explicitTestAuthorization) throw new LogicException('Explicit test authorization required.');
    if ($pdo->inTransaction()) throw new LogicException('Handler requires autocommit.');
    // A committed refund cannot pass the ordinary pending-refund preview:
    // its cumulative points have already been reversed. Verify the durable
    // receipt and source provenance before considering it acknowledged.
    if (($event['event_type']??null)==='sale.refunded') {
        $refundId=filter_var($event['source_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$refundId) throw new InvalidArgumentException('Invalid refund ID.');
        $zero=$pdo->prepare("SELECT p.household_id,p.original_earning_entry_id,p.points_reversed,
            p.reversal_entry_id,e.source_id AS original_sale_id,e.household_id AS earned_household_id,
            r.sale_id FROM reward_refund_processing p
            JOIN reward_ledger e ON e.id=p.original_earning_entry_id
              AND e.entry_type='earn_spend' AND e.source_type='sale'
            JOIN refunds r ON r.id=p.refund_id
            WHERE p.refund_id=?");
        $zero->execute([$refundId]);
        $zeroReceipt=$zero->fetch(PDO::FETCH_ASSOC);
        if ($zeroReceipt && (int)$zeroReceipt['points_reversed']===0) {
            if ($zeroReceipt['reversal_entry_id']!==null ||
                (int)$zeroReceipt['household_id']!==(int)$zeroReceipt['earned_household_id'] ||
                (string)$zeroReceipt['original_sale_id']!==(string)$zeroReceipt['sale_id'])
                throw new RuntimeException('Zero-point refund receipt provenance mismatch.');
            return ['outcome'=>'completed','replayed'=>true,'points_reversed'=>0];
        }
        $st=$pdo->prepare("SELECT l.id,l.household_id,l.policy_id,l.points_delta,l.related_entry_id,
            l.customer_id,l.source_type,l.source_id,l.entry_type,l.idempotency_key,
            r.sale_id, e.source_id AS original_sale_id
            FROM reward_ledger l
            JOIN reward_ledger e ON e.id=l.related_entry_id AND e.entry_type='earn_spend'
            JOIN refunds r ON r.id=?
            WHERE l.idempotency_key=? AND l.source_type='refund' AND l.source_id=?");
        $st->execute([$refundId,'roost:pos:refund:'.$refundId,(string)$refundId]);
        $committed=$st->fetch(PDO::FETCH_ASSOC);
        if ($committed) {
            if ((int)$committed['policy_id']!==$policyId ||
                (string)$committed['original_sale_id']!==(string)$committed['sale_id'])
                throw new RuntimeException('Refund receipt provenance or policy mismatch.');
            $mapped=['household_id'=>(int)$committed['household_id'],
                'customer_id'=>$committed['customer_id'],
                'policy_id'=>$policyId,'entry_type'=>'refund_reversal',
                'points_delta'=>(int)$committed['points_delta'],
                'source_type'=>'refund','source_id'=>(string)$refundId,
                'related_entry_id'=>(int)$committed['related_entry_id'],
                'idempotency_key'=>'roost:pos:refund:'.$refundId];
            $receipt=bcrf_roost_outbox_ledger_receipt($pdo,$mapped);
            if (!$receipt) throw new RuntimeException('Refund ledger receipt missing.');
            return ['outcome'=>'completed','replayed'=>true,'ledger_entry_id'=>$receipt['ledger_entry_id']];
        }
    }
    $plan=bcrf_roost_outbox_dispatch_preflight($pdo,$event,$policyId);
    if ($plan['action']==='already_posted')
        return ['outcome'=>'completed','replayed'=>true,'ledger_entry_id'=>$plan['receipt']['ledger_entry_id']];
    if ($plan['action']==='zero_point_receipt_required') {
        $result=bcrf_roost_record_zero_point_refund($pdo,$plan['refund_id'],$plan['original_earning_entry_id']);
        return ['outcome'=>'completed','replayed'=>$result['replayed'],'points_reversed'=>0];
    }
    if ($plan['action']!=='posting_required') throw new LogicException('Unknown dispatch action.');
    try {
        $result=bcrf_reward_post($pdo,$plan['event']);
        return ['outcome'=>'completed','replayed'=>$result['replayed'],'ledger_entry_id'=>$result['id']];
    } catch (PDOException $e) {
        // A competing first insert may have committed before this worker.
        // Only an EXACT matching receipt permits acknowledgment on retry.
        $receipt=bcrf_roost_outbox_ledger_receipt($pdo,$plan['event']);
        if ($receipt!==null)
            return ['outcome'=>'completed','replayed'=>true,'ledger_entry_id'=>$receipt['ledger_entry_id']];
        throw $e;
    }
}
