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
    // Receipt-first replay: an already-posted refund cannot be re-previewed
    // as a new reversal because cumulative prior reversals include itself.
    // Match immutable provenance before acknowledging a committed receipt.
    $receipt=$pdo->prepare("SELECT p.household_id,p.original_earning_entry_id,p.points_reversed,
            p.reversal_entry_id,r.sale_id,e.household_id AS earned_household,
            e.policy_id AS earned_policy,e.source_id AS earned_sale,
            e.entry_type AS earned_type,e.source_type AS earned_source,
            l.id AS ledger_id,l.household_id AS ledger_household,
            l.policy_id AS ledger_policy,l.related_entry_id,l.points_delta,
            l.entry_type AS ledger_type,l.source_type AS ledger_source,
            l.source_id AS ledger_refund,l.idempotency_key
        FROM reward_refund_processing p
        LEFT JOIN refunds r ON r.id=p.refund_id
        LEFT JOIN reward_ledger e ON e.id=p.original_earning_entry_id
        LEFT JOIN reward_ledger l ON l.id=p.reversal_entry_id
        WHERE p.refund_id=?");
    $receipt->execute([(int)$sourceId]);
    $rows=$receipt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)>1) throw new RuntimeException('Ambiguous committed refund receipt.');
    if ($rows) {
        $saved=$rows[0];
        if ((int)$saved['household_id']!==(int)$saved['earned_household'] ||
            (int)$saved['earned_policy']!==$policy ||
            $saved['earned_type']!=='earn_spend' || $saved['earned_source']!=='sale' ||
            (string)$saved['earned_sale']!==(string)$saved['sale_id'])
            throw new RuntimeException('Committed refund provenance mismatch.');
        if ((int)$saved['points_reversed']===0 && $saved['reversal_entry_id']===null)
            return ['action'=>'zero_point_already_processed','refund_id'=>(int)$sourceId,
                'original_earning_entry_id'=>(int)$saved['original_earning_entry_id']];
        if ((int)$saved['points_reversed']<=0 ||
            (int)$saved['reversal_entry_id']<=0 ||
            (int)$saved['ledger_id']!==(int)$saved['reversal_entry_id'] ||
            (int)$saved['ledger_household']!==(int)$saved['household_id'] ||
            (int)$saved['ledger_policy']!==$policy ||
            (int)$saved['related_entry_id']!==(int)$saved['original_earning_entry_id'] ||
            (int)$saved['points_delta']!==-(int)$saved['points_reversed'] ||
            $saved['ledger_type']!=='refund_reversal' || $saved['ledger_source']!=='refund' ||
            (string)$saved['ledger_refund']!==(string)$sourceId ||
            $saved['idempotency_key']!=='roost:pos:refund:'.$sourceId)
            throw new RuntimeException('Committed refund ledger receipt mismatch.');
        return ['action'=>'already_posted',
            'event'=>['policy_id'=>$policy,'entry_type'=>'refund_reversal',
                'source_type'=>'refund','source_id'=>(string)$sourceId],
            'receipt'=>['ledger_entry_id'=>(int)$saved['ledger_id'],'replayed'=>true]];
    }
    return bcrf_roost_outbox_dispatch_preflight($pdo,$event,$policy);
}
