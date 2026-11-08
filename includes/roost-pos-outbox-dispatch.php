<?php
// Roost Rewards outbox dispatcher preflight. Inert by design: no claim,
// acknowledgment, ledger write, or scheduler. Activation is a separate release gate.
require_once __DIR__.'/roost-pos-outbox-preview.php';
require_once __DIR__.'/roost-pos-outbox-ledger-map.php';
require_once __DIR__.'/roost-pos-outbox-receipt.php';
function bcrf_roost_outbox_dispatch_enabled(): bool { return false; }

/**
 * Evaluate one trusted event without side effects.
 * Caller must supply the policy ID from a separately approved policy resolver.
 * A missing original sale earning is NOT an excuse to skip a refund.
 */
function bcrf_roost_outbox_dispatch_preflight(PDO $pdo, array $event, int $policyId): array {
    if (bcrf_roost_outbox_dispatch_enabled())
        throw new LogicException('POS outbox dispatch activation is not implemented.');
    if ($pdo->inTransaction()) throw new LogicException('Dispatch preflight requires autocommit.');
    if ($policyId <= 0) throw new InvalidArgumentException('Approved policy ID required.');
    $preview=bcrf_roost_outbox_event_preview($pdo,$event);
    $mapped=bcrf_roost_outbox_ledger_event($preview,$policyId);
    if (($mapped['skip']??false)===true) {
        // The trusted zero-point processor must create or replay a receipt
        // before the outbox may be acknowledged; this preflight never does so.
        return ['action'=>'zero_point_receipt_required','refund_id'=>$mapped['refund_id'],
            'original_earning_entry_id'=>$mapped['original_earning_entry_id']];
    }
    $receipt=bcrf_roost_outbox_ledger_receipt($pdo,$mapped);
    return ['action'=>$receipt===null?'posting_required':'already_posted',
        'event'=>$mapped,'receipt'=>$receipt];
}
