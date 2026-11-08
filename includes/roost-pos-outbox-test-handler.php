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
