<?php
// Explicitly disabled adapter for POS transaction integration.
// The live sale/refund paths must not call this until a separate approved
// release has applied migration 0019 and enabled the durable outbox.
require_once __DIR__.'/roost-pos-outbox.php';
function bcrf_roost_pos_enqueue_enabled(): bool { return false; }
function bcrf_roost_pos_enqueue_if_enabled(PDO $pdo, string $kind, int $sourceId): bool {
    if (!in_array($kind,['sale.completed','sale.refunded'],true) || $sourceId<=0)
        throw new InvalidArgumentException('Invalid Roost POS source event.');
    if (!$pdo->inTransaction())
        throw new LogicException('POS outbox integration requires source transaction.');
    if (!bcrf_roost_pos_enqueue_enabled()) return false;
    if ($kind==='sale.completed') {
        // Resolve and pin within the original POS transaction, never at
        // delayed dispatch time. Activation remains separately disabled.
        $q=$pdo->query("SELECT id FROM reward_policies WHERE status='active'
            AND (effective_at IS NULL OR effective_at<=UTC_TIMESTAMP(3))
            ORDER BY id LIMIT 2");
        $policies=$q->fetchAll(PDO::FETCH_COLUMN);
        if (count($policies)!==1)
            throw new RuntimeException('Exactly one effective sale policy required at enqueue.');
        bcrf_roost_pos_outbox_enqueue_pinned_sale($pdo,$sourceId,(int)$policies[0]);
    } else {
        bcrf_roost_pos_outbox_enqueue($pdo,$kind,$sourceId);
    }
    return true;
}
