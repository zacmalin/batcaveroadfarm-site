<?php
// admin/migrations/0054_kitchen_labels.php — Farm OS Phase 4 slice 4: label drafts and approval (Nov 13, 2026; 09 › Labels;
// includes/kitchen_labels.php, admin/kitchen_labels.php).
//   kitchen_labels            a label's text for a product, from one recipe version: product name, ingredient statement,
//                             Contains line, net quantity, Made by, required statement. status draft → approved or rejected;
//                             approved/draft → superseded when the recipe, size, Site Info or statement changes. Never deleted.
//   kitchen_batches.label_id  the approved label a batch was made under.
//   site_settings             kitchen_label_statement (blank until set on Kitchen › Labels).
//   "Approve kitchen labels" is the view permission kitchen_labels_approve: Owners always; no other role until ticked.
// Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS kitchen_labels (
        id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        inventory_id       INT NOT NULL,
        recipe_version_id  INT UNSIGNED NOT NULL,
        product_name       VARCHAR(150) NOT NULL,
        statement          TEXT NOT NULL COMMENT 'ingredients, heaviest first',
        contains_line      VARCHAR(500) NOT NULL DEFAULT '',
        net_qty            VARCHAR(100) NOT NULL DEFAULT '',
        made_by            VARCHAR(300) NOT NULL DEFAULT '',
        extra              VARCHAR(500) NULL COMMENT 'required statement',
        status             ENUM('draft','approved','rejected','superseded') NOT NULL DEFAULT 'draft',
        created_at         DATETIME NOT NULL COMMENT 'UTC',
        created_by         INT NULL,
        decided_at         DATETIME NULL COMMENT 'UTC, approved or rejected',
        decided_by         INT NULL,
        decision_note      VARCHAR(255) NULL,
        superseded_at      DATETIME NULL COMMENT 'UTC',
        superseded_note    VARCHAR(255) NULL,
        KEY idx_product (inventory_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    if ($pdo->query("SHOW TABLES LIKE 'kitchen_batches'")->fetch() && !$pdo->query("SHOW COLUMNS FROM kitchen_batches LIKE 'label_id'")->fetch()) {
        $pdo->exec("ALTER TABLE kitchen_batches ADD COLUMN label_id INT UNSIGNED NULL COMMENT 'approved label it was made under' AFTER recipe_version_id");
    }
    $pdo->exec("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('kitchen_label_statement', '')");
};
