<?php
// admin/migrations/0053_kitchen_batches.php — Farm OS Phase 4 slice 3: production batches and lot codes (Nov 13, 2026;
// 09 › Production batches; includes/kitchen_batches.php, admin/kitchen_batches.php).
//   kitchen_batches            one production run: lot code (yymmdd-SKU-n), product, recipe and the saved version it was made
//                              from, batches, units made, made-on and best-by dates, where the finished goods went, whether
//                              stock was added / ingredients taken, notes; voided_* when a batch is voided (never deleted).
//   kitchen_batch_ingredients  every recipe line's grams for that batch (for tracing), and how much of a stocked product
//                              was taken out of stock, from which location.
//   ingredients.inventory_id   the stocked product an ingredient comes out of (e.g. sugar → "Sugar 4 lb bag").
// Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS kitchen_batches (
        id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        lot_code           VARCHAR(40) NOT NULL,
        inventory_id       INT NOT NULL,
        recipe_id          INT NOT NULL,
        recipe_version_id  INT UNSIGNED NOT NULL,
        batches            DECIMAL(10,2) NOT NULL,
        units_made         DECIMAL(12,2) NOT NULL,
        made_on            DATE NOT NULL COMMENT 'farm date',
        best_by            DATE NULL,
        location_id        BIGINT NULL COMMENT 'where the finished goods went',
        stock_added        TINYINT(1) NOT NULL DEFAULT 0,
        ingredients_taken  TINYINT(1) NOT NULL DEFAULT 0,
        notes              VARCHAR(500) NULL,
        created_at         DATETIME NOT NULL COMMENT 'UTC',
        created_by         INT NULL,
        voided_at          DATETIME NULL COMMENT 'UTC',
        voided_by          INT NULL,
        void_reason        VARCHAR(255) NULL,
        UNIQUE KEY uq_lot (lot_code),
        KEY idx_product (inventory_id, made_on),
        KEY idx_made (made_on)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS kitchen_batch_ingredients (
        id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        batch_id          INT UNSIGNED NOT NULL,
        ingredient_id     INT NULL,
        linked_recipe_id  INT NULL,
        name              VARCHAR(150) NOT NULL,
        grams             DECIMAL(12,2) NULL,
        inventory_id      INT NULL COMMENT 'stocked product it comes out of',
        qty_taken         DECIMAL(12,3) NULL COMMENT 'taken out of stock, in that product''s units',
        location_id       BIGINT NULL,
        KEY idx_batch (batch_id),
        KEY idx_ingredient (ingredient_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    if (!$pdo->query("SHOW COLUMNS FROM ingredients LIKE 'inventory_id'")->fetch()) {
        $pdo->exec("ALTER TABLE ingredients ADD COLUMN inventory_id INT NULL COMMENT 'stocked product it comes out of (kitchen batches)'");
    }
};
