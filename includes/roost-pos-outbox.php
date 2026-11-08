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
