<?php
// admin/migrations/0022_consignor_settlements.php — Farm OS Phase 2: consignor settlements (Nov 9, 2026)
// Settlements group sold consigned thrift items and record the payout to the consignor.
//   consignor_settlements       header (one payout batch per consignor)
//   consignor_settlement_lines  one row per thrift item included
//   inventory.settlement_id     marks a thrift item as settled (quick filter)
// Safe to repeat: tables IF NOT EXISTS; column added only when missing.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $col = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };

    $pdo->exec("CREATE TABLE IF NOT EXISTS consignor_settlements (
        id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        consignor_customer_id  INT NOT NULL COMMENT 'customers.id',
        status                 VARCHAR(12) NOT NULL DEFAULT 'paid' COMMENT 'paid (recorded when created)',
        items_count            INT UNSIGNED NOT NULL DEFAULT 0,
        sale_cents             BIGINT NOT NULL DEFAULT 0 COMMENT 'sum of sale net (gross - discount) for items',
        consignor_cents        BIGINT NOT NULL DEFAULT 0 COMMENT 'sum owed to consignor',
        farm_cents             BIGINT NOT NULL DEFAULT 0 COMMENT 'sale_cents - consignor_cents',
        paid_at                DATETIME(3) NOT NULL COMMENT 'UTC when recorded',
        paid_by                INT NULL,
        note                   VARCHAR(255) NULL,
        created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
        created_by             INT NULL,
        KEY idx_consignor (consignor_customer_id, paid_at),
        KEY idx_paid (paid_at)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS consignor_settlement_lines (
        id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        settlement_id     BIGINT UNSIGNED NOT NULL,
        product_id        INT NOT NULL COMMENT 'inventory.id thrift item',
        sale_id           BIGINT UNSIGNED NULL,
        sale_line_id      BIGINT UNSIGNED NULL,
        description       VARCHAR(255) NOT NULL,
        sku               VARCHAR(40) NULL,
        sale_net_cents    BIGINT NOT NULL COMMENT 'gross - discount on that sale line',
        share_bp          INT UNSIGNED NOT NULL COMMENT 'snapshot of consignor_share_bp',
        consignor_cents   BIGINT NOT NULL COMMENT 'share of sale_net',
        created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_settlement (settlement_id),
        KEY idx_product (product_id),
        CONSTRAINT fk_csl_settlement FOREIGN KEY (settlement_id) REFERENCES consignor_settlements (id)
    ) $cs");

    if (!$col('inventory', 'settlement_id')) {
        $pdo->exec("ALTER TABLE inventory ADD COLUMN settlement_id BIGINT UNSIGNED NULL COMMENT 'consignor_settlements.id when paid out'");
    }
};
