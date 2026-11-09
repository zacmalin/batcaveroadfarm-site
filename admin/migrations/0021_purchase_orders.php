<?php
// admin/migrations/0021_purchase_orders.php — Farm OS Phase 2, slice 2.4: PO-lite (Nov 9, 2026; 06 Purchasing)
// purchase_orders / po_lines  planned orders against a vendor; receive can attach to a PO and fill qty_received.
// receives.po_id optional link from an existing receive back to the order.
// Safe to repeat: tables IF NOT EXISTS; column added only when missing.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $col = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };

    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_orders (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        vendor_id       BIGINT UNSIGNED NOT NULL COMMENT 'vendors.id',
        status          VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft, ordered, partial, received, cancelled',
        po_ref          VARCHAR(80) NULL COMMENT 'our or vendor reference #',
        expected_date   DATE NULL COMMENT 'farm-local expected delivery',
        note            VARCHAR(255) NULL,
        ordered_at      DATETIME NULL COMMENT 'UTC when marked ordered',
        closed_at       DATETIME NULL COMMENT 'UTC when fully received or cancelled',
        created_at      DATETIME(3) NOT NULL COMMENT 'UTC',
        created_by      INT NULL,
        updated_at      DATETIME NULL COMMENT 'UTC',
        updated_by      INT NULL,
        KEY idx_status (status, expected_date),
        KEY idx_vendor (vendor_id)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS po_lines (
        id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        po_id               BIGINT UNSIGNED NOT NULL,
        line_no             SMALLINT UNSIGNED NOT NULL,
        product_id          INT NOT NULL COMMENT 'inventory.id',
        quantity_ordered    DECIMAL(12,3) NOT NULL,
        quantity_received   DECIMAL(12,3) NOT NULL DEFAULT 0,
        unit_cost_cents     BIGINT NULL COMMENT 'expected unit cost; used as receive default',
        note                VARCHAR(255) NULL,
        created_at          DATETIME(3) NOT NULL COMMENT 'UTC',
        KEY idx_po (po_id),
        KEY idx_product (product_id),
        CONSTRAINT fk_pol_po FOREIGN KEY (po_id) REFERENCES purchase_orders (id)
    ) $cs");

    if ($col('receives', 'id') && !$col('receives', 'po_id')) {
        $pdo->exec("ALTER TABLE receives ADD COLUMN po_id BIGINT UNSIGNED NULL COMMENT 'purchase_orders.id when received against a PO' AFTER vendor_id");
        $pdo->exec("ALTER TABLE receives ADD KEY idx_po (po_id)");
    }
};
