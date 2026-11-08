<?php
// Read-only, fail-closed policy selection. No activation or event dispatch.
function bcrf_roost_outbox_resolve_policy(PDO $pdo, array $event): int {
    if ($pdo->inTransaction()) throw new LogicException('Policy resolution requires autocommit.');
    $kind=$event['event_type']??null;
    $id=filter_var($event['source_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$id || !in_array($kind,['sale.completed','sale.refunded'],true))
        throw new InvalidArgumentException('Invalid policy resolution event.');
    if ($kind==='sale.refunded') {
        $q=$pdo->prepare("SELECT DISTINCT l.policy_id FROM refunds r
            JOIN reward_ledger l ON l.source_type='sale'
                AND l.entry_type='earn_spend'
                AND l.source_id REGEXP '^[1-9][0-9]*$'
                AND CAST(l.source_id AS UNSIGNED)=r.sale_id
            WHERE r.id=?");
        $q->execute([$id]);
        $policies=$q->fetchAll(PDO::FETCH_COLUMN);
        if (count($policies)!==1) throw new RuntimeException('Refund requires one original sale policy.');
        return (int)$policies[0];
    }
    // No historical backdating: the current active policy must be unique.
    $q=$pdo->query("SELECT id FROM reward_policies WHERE status='active'
        AND (effective_at IS NULL OR effective_at <= UTC_TIMESTAMP(3)) ORDER BY id LIMIT 2");
    $policies=$q->fetchAll(PDO::FETCH_COLUMN);
    if (count($policies)!==1) throw new RuntimeException('Exactly one effective active reward policy required.');
    return (int)$policies[0];
}

// Fail closed for delayed queued sales if the current policy was created or
// became effective after the event was durably recorded. Historical policy
// snapshots are not yet stored in the outbox, so never silently substitute a
// newer policy for an earlier sale.
function bcrf_roost_outbox_assert_sale_policy_not_newer(PDO $pdo, int $outboxId, int $policyId): void {
    if ($pdo->inTransaction()) throw new LogicException('Policy age check requires autocommit.');
    if ($outboxId<=0 || $policyId<=0) throw new InvalidArgumentException('Positive outbox and policy IDs required.');
    $q=$pdo->prepare("SELECT o.created_at AS queued_at,p.created_at AS policy_created_at,
        p.effective_at AS policy_effective_at,p.status AS policy_status
        FROM reward_pos_outbox o JOIN reward_policies p ON p.id=?
        WHERE o.id=? AND o.event_type='sale.completed'");
    $q->execute([$policyId,$outboxId]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['policy_status']!=='active' ||
        $row['policy_created_at']===null ||
        $row['policy_created_at']>$row['queued_at'] ||
        ($row['policy_effective_at']!==null && $row['policy_effective_at']>$row['queued_at']))
        throw new RuntimeException('Queued sale cannot use a newer or inactive reward policy.');
}

// The only valid sale policy for queued dispatch is its immutable snapshot.
// Null/legacy entries require manual reconciliation, never a guessed policy.
function bcrf_roost_outbox_pinned_sale_policy(PDO $pdo, int $outboxId): int {
    if ($pdo->inTransaction()) throw new LogicException('Pinned policy read requires autocommit.');
    if ($outboxId<=0) throw new InvalidArgumentException('Positive outbox ID required.');
    $q=$pdo->prepare("SELECT policy_id FROM reward_pos_outbox
        WHERE id=? AND event_type='sale.completed'");
    $q->execute([$outboxId]);
    $value=$q->fetchColumn();
    if ($value===false || $value===null || (int)$value<=0)
        throw new RuntimeException('Queued sale has no pinned policy; manual reconciliation required.');
    return (int)$value;
}


/**
 * Future production-dispatch authority: resolve a queued sale's immutable
 * policy snapshot and reject caller-supplied substitutions. No side effects.
 * Refunds must instead resolve the original earning's historical policy.
 */
function bcrf_roost_outbox_authorize_sale_policy(PDO $pdo, int $outboxId, int $expectedPolicyId): int {
    if ($expectedPolicyId<=0) throw new InvalidArgumentException('Positive expected policy required.');
    $pinned=bcrf_roost_outbox_pinned_sale_policy($pdo,$outboxId);
    if ($pinned!==$expectedPolicyId)
        throw new RuntimeException('Queued sale policy does not match immutable snapshot.');
    return $pinned;
}
