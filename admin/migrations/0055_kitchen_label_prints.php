<?php
// admin/migrations/0055_kitchen_label_prints.php — Farm OS Phase 4 slice 5: label printing (Nov 13, 2026; 09 › Labels › Printing;
// includes/kitchen_print.php, admin/kitchen_label_print.php).
//   kitchen_label_prints  one row per print run: the batch, the approved label printed, how many, the starting sheet
//                         position and the label stock used. Never deleted.
//   Printing settings (label stock, type sizes, nudge, logo) live in site_settings 'kitchen_label_print' (JSON), written
//   the first time they're saved; until then the defaults apply (PLS451 3″×3″, 6 per sheet; 6 pt body, 10 pt net quantity).
// Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS kitchen_label_prints (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        batch_id    INT UNSIGNED NOT NULL,
        label_id    INT UNSIGNED NOT NULL,
        copies      SMALLINT UNSIGNED NOT NULL,
        start_pos   TINYINT UNSIGNED NOT NULL DEFAULT 1,
        stock       VARCHAR(120) NOT NULL DEFAULT '',
        created_at  DATETIME NOT NULL COMMENT 'UTC',
        created_by  INT NULL,
        KEY idx_batch (batch_id),
        KEY idx_label (label_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
};
