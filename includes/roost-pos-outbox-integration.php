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
    bcrf_roost_pos_outbox_enqueue($pdo,$kind,$sourceId);
    return true;
}
