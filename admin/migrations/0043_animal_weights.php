<?php
// admin/migrations/0043_animal_weights.php — Farm OS Phase 5 slice 4: weights (Nov 12, 2026; 08 › Weight; includes/animal_weights.php).
//   animal_weights   one weighing: farm date, weight in pounds, optional body condition score (1 to 5, halves allowed),
//                    note, who. Mistakes are voided with a reason, never deleted.
//   site_settings    animal_weight_alert_pct = change between weighings that raises an alert (default 10).
// Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS animal_weights (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        animal_id    INT NOT NULL,
        weighed_on   DATE NOT NULL COMMENT 'farm date',
        weight_lb    DECIMAL(7,2) NOT NULL,
        body_condition DECIMAL(2,1) NULL COMMENT '1 to 5',
        note         VARCHAR(255) NULL,
        created_at   DATETIME NOT NULL COMMENT 'UTC',
        created_by   INT NULL,
        voided_at    DATETIME NULL COMMENT 'UTC',
        voided_by    INT NULL,
        void_reason  VARCHAR(255) NULL,
        KEY idx_animal (animal_id, weighed_on)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    $pdo->exec("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('animal_weight_alert_pct', '10')");
};
