<?php
// admin/migrations/0051_kitchen_versions.php — Farm OS Phase 4 slice 2: recipe versions and recipe → product links (Nov 12, 2026;
// 09 › Recipes (versions), Recipe → product; includes/kitchen_versions.php, admin/kitchen_products.php).
//   recipe_versions    a saved copy of what a label would say for a recipe: its lines with weights, the ingredient list,
//                      the Contains line. fingerprint = what the label depends on, so any change (in the recipe or in an
//                      ingredient's allergens, sub-ingredients or label name) shows up as "changed since version N".
//                      Batches and labels (later slices) point at a version. Never edited or deleted.
//   kitchen_products   which recipe makes a sellable product (inventory.id), how many units one batch makes, shelf life
//                      for the best-by date, and whether NCDA&CS has confirmed it as an allowed home-processor product.
//                      Net quantity comes from the product's size (Products page).
// Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS recipe_versions (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        recipe_id     INT NOT NULL,
        version_no    INT UNSIGNED NOT NULL,
        fingerprint   CHAR(64) NOT NULL,
        statement     TEXT NOT NULL COMMENT 'ingredient list, heaviest first',
        contains_line VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'allergens, empty = none',
        total_g       DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'one batch',
        snapshot      MEDIUMTEXT NOT NULL COMMENT 'JSON: lines and items as resolved',
        note          VARCHAR(255) NULL,
        created_at    DATETIME NOT NULL COMMENT 'UTC',
        created_by    INT NULL,
        UNIQUE KEY uq_recipe_version (recipe_id, version_no),
        KEY idx_fp (recipe_id, fingerprint)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS kitchen_products (
        inventory_id     INT NOT NULL PRIMARY KEY,
        recipe_id        INT NOT NULL,
        units_per_batch  DECIMAL(10,2) NOT NULL,
        shelf_life_days  INT UNSIGNED NULL COMMENT 'best-by = made-on + this',
        approved_type    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'NCDA&CS confirmed this product type is allowed',
        approved_note    VARCHAR(255) NULL,
        updated_at       DATETIME NOT NULL COMMENT 'UTC',
        updated_by       INT NULL,
        KEY idx_recipe (recipe_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
};
