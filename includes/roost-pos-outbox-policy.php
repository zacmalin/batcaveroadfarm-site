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
                AND l.entry_type='earn_spend' AND l.source_id=CAST(r.sale_id AS CHAR)
            WHERE r.id=?");
        $q->execute([$id]);
        $policies=$q->fetchAll(PDO::FETCH_COLUMN);
        if (count($policies)!==1) throw new RuntimeException('Refund requires one original sale policy.');
        return (int)$policies[0];
    }
    // No historical backdating: the current active policy must be unique.
    // Future effective policies and drafts cannot qualify.
    $q=$pdo->query("SELECT id FROM reward_policies WHERE status='active'
        AND (effective_at IS NULL OR effective_at <= UTC_TIMESTAMP(3)) ORDER BY id LIMIT 2");
    $policies=$q->fetchAll(PDO::FETCH_COLUMN);
    if (count($policies)!==1) throw new RuntimeException('Exactly one effective active reward policy required.');
    return (int)$policies[0];
}
