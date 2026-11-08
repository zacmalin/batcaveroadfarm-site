<?php
// Roost Rewards POS lifecycle gate. Disabled until explicitly approved.
// This file deliberately performs NO ledger writes or automatic awards.
function bcrf_roost_pos_integration_enabled(): bool {
    return false;
}

/**
 * Read-only post-commit eligibility signal for trusted POS callers.
 * Never run before commit, and never turn a successful sale/refund into an error.
 * Processing and durable retries must be implemented and approved separately.
 */
function bcrf_roost_pos_postcommit_signal(PDO $pdo, string $kind, int $sourceId): array {
    if (!in_array($kind, ['sale.completed', 'sale.refunded'], true) || $sourceId <= 0) {
        throw new InvalidArgumentException('Invalid Roost Rewards lifecycle signal.');
    }
    if ($pdo->inTransaction()) {
        throw new LogicException('Roost Rewards lifecycle signal requires committed transaction.');
    }
    if (!bcrf_roost_pos_integration_enabled()) {
        return ['enabled'=>false,'processed'=>false,'kind'=>$kind,'source_id'=>$sourceId];
    }
    // Fail closed: no processing implementation is approved or wired yet.
    throw new LogicException('Roost Rewards lifecycle processing is not implemented.');
}
