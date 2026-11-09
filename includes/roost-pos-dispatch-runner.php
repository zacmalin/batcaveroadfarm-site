<?php
// Bounded internal POS delivery runner. Never scheduled or exposed to HTTP.
// Both this runner and POS enqueue remain disabled until separately approved.
require_once __DIR__.'/roost-pos-outbox-worker.php';
require_once __DIR__.'/roost-pos-outbox-test-handler.php';
require_once __DIR__.'/roost-pos-outbox-policy.php';

/**
 * Process at most one queued POS event with the already tested idempotent
 * ledger handler. Does not bypass the hard-disabled production dispatch gate.
 */
function bcrf_roost_pos_dispatch_once(PDO $pdo, bool $approved=false): string {
    if (!$approved || !bcrf_roost_outbox_dispatch_enabled()) return 'disabled';
    if ($pdo->inTransaction()) throw new LogicException('Dispatch requires autocommit.');
    $claim=bcrf_roost_outbox_claim($pdo);
    if ($claim===null) return 'empty';
    $id=(int)$claim['id'];
    $attempt=(int)$claim['attempts'];
    try {
        $kind=(string)$claim['event_type'];
        if ($kind==='sale.completed') {
            $policyId=bcrf_roost_outbox_pinned_sale_policy($pdo,$id);
        } elseif ($kind==='sale.refunded') {
            // Refund policy must come from the original committed sale award.
            $q=$pdo->prepare("SELECT l.policy_id FROM refunds r
                JOIN reward_ledger l ON l.source_type='sale'
                  AND l.entry_type='earn_spend' AND l.source_id=CAST(r.sale_id AS CHAR)
                WHERE r.id=?");
            $q->execute([(int)$claim['source_id']]);
            $policies=$q->fetchAll(PDO::FETCH_COLUMN);
            if (count($policies)!==1) throw new RuntimeException('Refund original earning unavailable.');
            $policyId=(int)$policies[0];
        } else {
            throw new RuntimeException('Unsupported POS event type.');
        }
        $result=bcrf_roost_outbox_handle_test_event($pdo,$claim,$policyId,true);
        if (($result['outcome']??null)!=='completed')
            throw new RuntimeException('POS event handler did not complete.');
        if (!bcrf_roost_outbox_finish($pdo,$id,$attempt,'completed'))
            throw new RuntimeException('POS completion lease was lost.');
        return 'completed';
    } catch (Throwable $e) {
        // Never store raw exception strings: they can contain sensitive data.
        try { bcrf_roost_outbox_finish($pdo,$id,$attempt,'failed','processing_failed'); }
        catch (Throwable $ignored) {}
        return 'failed';
    }
}
