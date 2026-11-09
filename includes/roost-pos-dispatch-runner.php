<?php
// Internal, bounded POS dispatcher. Not exposed to HTTP or a scheduler.
// Hard-disabled until a separately approved activation release.
require_once __DIR__.'/roost-pos-outbox-worker.php';
require_once __DIR__.'/roost-pos-outbox-test-handler.php';
require_once __DIR__.'/roost-pos-outbox-policy.php';

function bcrf_roost_pos_dispatch_once(PDO $pdo): string {
    if (!bcrf_roost_outbox_dispatch_enabled()) return 'disabled';
    if ($pdo->inTransaction()) throw new LogicException('POS dispatch requires autocommit.');
    $claim=bcrf_roost_outbox_claim($pdo);
    if ($claim===null) {
        // Final-attempt crashes otherwise strand processing rows indefinitely.
        // Recover only expired leases, one at a time, under a row lock.
        return bcrf_roost_outbox_expire_exhausted_lease($pdo) !== null
            ? 'dead_lettered' : 'empty';
    }
    return bcrf_roost_pos_dispatch_claimed_event($pdo,$claim);
}

// Internal handler for an already claimed queue row. No public entry point.
// Tests exercise this against disposable MySQL without enabling dispatch.
function bcrf_roost_pos_dispatch_claimed_event(PDO $pdo,array $claim): string {
    if ($pdo->inTransaction()) throw new LogicException('POS claim handling requires autocommit.');
    $id=(int)$claim['id'];
    $attempt=(int)$claim['attempts'];
    try {
        // A queue claim is authoritative only for its exact stored source.
        // Recheck identity before any balance mutation; a stale or substituted
        // event must fail closed rather than award a different transaction.
        $identity=$pdo->prepare("SELECT event_type,source_id,status,attempts
            FROM reward_pos_outbox WHERE id=?");
        $identity->execute([$id]);
        $stored=$identity->fetch(PDO::FETCH_ASSOC);
        if (!$stored || $stored['status']!=='processing' ||
            $stored['event_type']!==$claim['event_type'] ||
            (string)$stored['source_id']!==(string)$claim['source_id'] ||
            (int)$stored['attempts']!==$attempt)
            throw new RuntimeException('POS claim identity mismatch.');
        if ($claim['event_type']==='sale.completed') {
            $policyId=bcrf_roost_outbox_pinned_sale_policy($pdo,$id);
        } elseif ($claim['event_type']==='sale.refunded') {
            $policyId=bcrf_roost_outbox_resolve_policy($pdo,$claim);
        } else {
            throw new RuntimeException('Unsupported POS event type.');
        }
        $result=bcrf_roost_outbox_handle_verified_event($pdo,$claim,$policyId);
        if (($result['outcome']??null)!=='completed')
            throw new RuntimeException('POS ledger handler did not complete.');
        if (!bcrf_roost_outbox_finish($pdo,$id,$attempt,'completed'))
            throw new RuntimeException('POS queue acknowledgement lost.');
        return 'completed';
    } catch (Throwable $e) {
        // Only fixed error codes: never persist raw exception/SQL strings.
        try { bcrf_roost_outbox_finish($pdo,$id,$attempt,'failed','processing_failed'); }
        catch (Throwable $ignored) {}
        return 'failed';
    }
}
