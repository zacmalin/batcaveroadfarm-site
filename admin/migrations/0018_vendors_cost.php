<?php
// admin/migrations/0018_vendors_cost.php — Farm OS Phase 2, slice 2.1: vendors and product cost (Nov 9, 2026; 06 Purchasing & Inventory)
// vendors                 supplier contacts (name, account #, payment terms, notes).
// inventory columns       preferred_vendor_id, vendor_sku, pack_size, avg_cost_cents (moving average; cents).
// product_cost_history    every change to avg_cost_cents (manual edit or, later, receiving).
// Safe to repeat: tables IF NOT EXISTS; columns added only when missing.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $col = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };

    $pdo->exec("CREATE TABLE IF NOT EXISTS vendors (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name            VARCHAR(120) NOT NULL,
        contact_name    VARCHAR(120) NULL,
        email           VARCHAR(190) NULL,
        phone           VARCHAR(40) NULL,
        account_number  VARCHAR(80) NULL,
        payment_terms   VARCHAR(80) NULL COMMENT 'e.g. Net 30, COD',
        notes           TEXT NULL,
        is_active       TINYINT(1) NOT NULL DEFAULT 1,
        created_at      DATETIME NOT NULL COMMENT 'UTC',
        created_by      INT NULL,
        updated_at      DATETIME NULL COMMENT 'UTC',
        updated_by      INT NULL,
        archived_at     DATETIME NULL,
        KEY idx_active (is_active, name),
        KEY idx_name (name)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS product_cost_history (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product_id       INT NOT NULL COMMENT 'inventory.id',
        cost_cents       BIGINT NOT NULL COMMENT 'avg cost after this change',
        previous_cents   BIGINT NULL,
        quantity_on_hand DECIMAL(12,3) NULL COMMENT 'stock when recorded (for moving average context)',
        source           VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual, receive, opening',
        source_id        BIGINT UNSIGNED NULL,
        note             VARCHAR(255) NULL,
        created_at       DATETIME(3) NOT NULL COMMENT 'UTC',
        created_by       INT NULL,
        KEY idx_product (product_id, created_at)
    ) $cs");

    $adds = [
        'preferred_vendor_id' => "BIGINT UNSIGNED NULL COMMENT 'vendors.id'",
        'vendor_sku'          => "VARCHAR(80) NULL COMMENT 'supplier SKU / part number'",
        'pack_size'           => "DECIMAL(12,3) NULL COMMENT 'units per vendor pack (optional)'",
        'avg_cost_cents'      => "BIGINT NULL COMMENT 'moving average unit cost in cents'",
    ];
    foreach ($adds as $c => $def) {
        if (!$col('inventory', $c)) $pdo->exec("ALTER TABLE inventory ADD COLUMN `$c` $def");
    }
};
