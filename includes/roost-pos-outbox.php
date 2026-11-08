<?php
// Trusted internal POS outbox writer. Must be called INSIDE the source
// transaction; a rollback then removes the event as well as the source.
// No processor or scheduler is activated by this file.
function bcrf_roost_pos_outbox_enqueue(PDO $pdo, string $kind, int $sourceId): void {
    if (!in_array($kind, ['sale.completed','sale.refunded'], true) || $sourceId <= 0) {
        throw new InvalidArgumentException('Invalid Roost POS event.');
    }
    if (!$pdo->inTransaction()) {
        throw new LogicException('Outbox enqueue must share the POS transaction.');
    }
    $sql = "INSERT INTO reward_pos_outbox (event_type, source_id)
        VALUES (?, ?) ON DUPLICATE KEY UPDATE id = id";
    $st = $pdo->prepare($sql);
    $st->execute([$kind,$sourceId]);
}

/**
 * Future activation path: atomically pin a validated sale policy at enqueue.
 * Duplicate events must retain their first policy; never silently repin.
 * The disabled POS adapter returns before invoking this path.
 */
function bcrf_roost_pos_outbox_enqueue_pinned_sale(PDO $pdo, int $saleId, int $policyId): void {
    if (!$pdo->inTransaction()) throw new LogicException('Pinned enqueue requires source transaction.');
    if ($saleId<=0 || $policyId<=0) throw new InvalidArgumentException('Positive sale and policy IDs required.');
    $policy=$pdo->prepare("SELECT id FROM reward_policies
        WHERE id=? AND status='active'
          AND (effective_at IS NULL OR effective_at <= UTC_TIMESTAMP(3))
        FOR UPDATE");
    $policy->execute([$policyId]);
    if (!$policy->fetchColumn()) throw new RuntimeException('Cannot pin inactive or future reward policy.');
    $st=$pdo->prepare("INSERT INTO reward_pos_outbox (event_type,source_id,policy_id)
        VALUES ('sale.completed',?,?) ON DUPLICATE KEY UPDATE id=id");
    $st->execute([$saleId,$policyId]);
    $read=$pdo->prepare("SELECT policy_id FROM reward_pos_outbox
        WHERE event_type='sale.completed' AND source_id=? FOR UPDATE");
    $read->execute([$saleId]);
    if ((int)$read->fetchColumn()!==$policyId)
        throw new RuntimeException('Sale event already exists with missing or different pinned policy.');
}
