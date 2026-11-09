<?php
// admin/migrations/0019_receives.php — Farm OS Phase 2, slice 2.2: receiving (Nov 9, 2026; 06 Purchasing & Inventory)
// receives / receive_lines  one delivery (vendor + invoice ref) with costed lines that update stock and moving average.
// Safe to repeat: tables IF NOT EXISTS.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    $pdo->exec("CREATE TABLE IF NOT EXISTS receives (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        vendor_id       BIGINT UNSIGNED NULL COMMENT 'vendors.id',
        location_id     BIGINT UNSIGNED NOT NULL COMMENT 'locations.id where stock lands',
        invoice_ref     VARCHAR(80) NULL COMMENT 'supplier invoice or packing slip #',
        note            VARCHAR(255) NULL,
        business_date   DATE NOT NULL COMMENT 'farm-local date',
        created_at      DATETIME(3) NOT NULL COMMENT 'UTC',
        created_by      INT NULL,
        KEY idx_bdate (business_date),
        KEY idx_vendor (vendor_id),
        KEY idx_location (location_id)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS receive_lines (
        id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        receive_id        BIGINT UNSIGNED NOT NULL,
        line_no           SMALLINT UNSIGNED NOT NULL,
        product_id        INT NOT NULL COMMENT 'inventory.id',
        quantity          DECIMAL(12,3) NOT NULL,
        unit_cost_cents   BIGINT NOT NULL COMMENT 'cost per unit for this line',
        line_cost_cents   BIGINT NOT NULL COMMENT 'quantity x unit cost, rounded',
        note              VARCHAR(255) NULL,
        created_at        DATETIME(3) NOT NULL COMMENT 'UTC',
        KEY idx_receive (receive_id),
        KEY idx_product (product_id),
        CONSTRAINT fk_rl_receive FOREIGN KEY (receive_id) REFERENCES receives (id)
    ) $cs");
};
