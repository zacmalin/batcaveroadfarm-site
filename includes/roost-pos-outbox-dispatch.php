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


/**
 * Production-boundary preflight for queued sales only. The event must carry
 * its actual outbox row ID; policy comes from the immutable snapshot.
 * The legacy test-only preflight remains inert and separately authorized.
 */
function bcrf_roost_outbox_dispatch_pinned_sale_preflight(PDO $pdo, array $event): array {
    if (($event['event_type']??null)!=='sale.completed')
        throw new InvalidArgumentException('Pinned sale preflight requires sale event.');
    $id=filter_var($event['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $sourceId=filter_var($event['source_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$id || !$sourceId) throw new InvalidArgumentException('Queued sale identity required.');
    if ($pdo->inTransaction()) throw new LogicException('Pinned preflight requires autocommit.');
    $st=$pdo->prepare("SELECT source_id FROM reward_pos_outbox WHERE id=? AND event_type='sale.completed'");
    $st->execute([(int)$id]);
    if ((string)$st->fetchColumn()!==(string)$sourceId)
        throw new RuntimeException('Queued sale source does not match outbox identity.');
    require_once __DIR__.'/roost-pos-outbox-policy.php';
    $policy=bcrf_roost_outbox_pinned_sale_policy($pdo,(int)$id);
    return bcrf_roost_outbox_dispatch_preflight($pdo,$event,$policy);
}


/**
 * Inert queued-refund preflight: bind the outbox identity to the refund and
 * derive policy from the original sale earning, never the active policy.
 */
function bcrf_roost_outbox_dispatch_refund_preflight(PDO $pdo, array $event): array {
    if (($event['event_type']??null)!=='sale.refunded')
        throw new InvalidArgumentException('Refund preflight requires refund event.');
    $id=filter_var($event['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $sourceId=filter_var($event['source_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$id || !$sourceId) throw new InvalidArgumentException('Queued refund identity required.');
    if ($pdo->inTransaction()) throw new LogicException('Refund preflight requires autocommit.');
    $st=$pdo->prepare("SELECT source_id FROM reward_pos_outbox WHERE id=? AND event_type='sale.refunded'");
    $st->execute([(int)$id]);
    if ((string)$st->fetchColumn()!==(string)$sourceId)
        throw new RuntimeException('Queued refund source does not match outbox identity.');
    require_once __DIR__.'/roost-pos-outbox-policy.php';
    $policy=bcrf_roost_outbox_resolve_policy($pdo,$event);
    return bcrf_roost_outbox_dispatch_preflight($pdo,$event,$policy);
}
