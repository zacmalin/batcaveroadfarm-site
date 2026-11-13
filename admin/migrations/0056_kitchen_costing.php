<?php
// admin/migrations/0056_kitchen_costing.php — Farm OS Phase 4 slice 6: costing and the margin flag (Nov 13, 2026; 09 › Costing;
// includes/kitchen_costing.php, admin/kitchen_costing.php).
//   ingredients        cost_cents, cost_qty, cost_unit_id: a manual cost ("$4.29 for 5 lb") for ingredients with no linked
//                      stock product (or one with no average cost yet); cost_updated_at/_by.
//   kitchen_packaging  jars, lids, labels per kitchen product: a stock product (its average cost) or a manual cost each,
//                      and how many go on one unit.
//   kitchen_batches    cost_cents, unit_cost_cents: what the batch cost when it was recorded (null if a cost was missing).
//   site_settings      kitchen_margin_alert = 40 (percent).
// Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $cols = [
        'cost_cents'      => "BIGINT NULL COMMENT 'manual cost: price paid, cents'",
        'cost_qty'        => "DECIMAL(10,3) NULL COMMENT 'manual cost: for this much'",
        'cost_unit_id'    => "INT NULL COMMENT 'manual cost: of this unit (measurement_units)'",
        'cost_updated_at' => "DATETIME NULL COMMENT 'UTC'",
        'cost_updated_by' => "INT NULL",
    ];
    foreach ($cols as $c => $def) {
        if (!$pdo->query("SHOW COLUMNS FROM ingredients LIKE " . $pdo->quote($c))->fetch()) $pdo->exec("ALTER TABLE ingredients ADD COLUMN `$c` $def");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS kitchen_packaging (
        id                      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        inventory_id            INT NOT NULL COMMENT 'the kitchen product',
        name                    VARCHAR(100) NOT NULL,
        packaging_inventory_id  INT NULL COMMENT 'stock product it comes from (average cost)',
        cost_cents              BIGINT NULL COMMENT 'manual cost each, when not from stock',
        qty_per_unit            DECIMAL(10,3) NOT NULL DEFAULT 1.000,
        created_at              DATETIME NOT NULL COMMENT 'UTC',
        KEY idx_product (inventory_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    if ($pdo->query("SHOW TABLES LIKE 'kitchen_batches'")->fetch()) {
        if (!$pdo->query("SHOW COLUMNS FROM kitchen_batches LIKE 'cost_cents'")->fetch())
            $pdo->exec("ALTER TABLE kitchen_batches ADD COLUMN cost_cents BIGINT NULL COMMENT 'batch cost when recorded' AFTER units_made, ADD COLUMN unit_cost_cents BIGINT NULL COMMENT 'cost per unit when recorded' AFTER cost_cents");
    }
    $pdo->exec("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('kitchen_margin_alert', '40')");
};
