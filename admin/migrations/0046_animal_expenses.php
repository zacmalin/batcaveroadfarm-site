<?php
// admin/migrations/0046_animal_expenses.php — Farm OS Phase 5 slice 7: expenses (Nov 12, 2026; 08 › Expenses; includes/animal_expenses.php).
//   animal_expenses     a cost that isn't already recorded elsewhere: supplies, bedding, farrier, a vet bill with no medical
//                       record, buying an animal, equipment. For one animal, a whole species, or all animals. Amount in cents.
//                       Never deleted; voided with a reason.
//   view_permissions    'animal_expenses' for Managers (moderator). Owners always have it. Staff don't see costs.
// Vet costs (animal_medical_records.cost_cents) and feed (animal_care_logs.feed_cost_cents) are read where they already are.
// Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS animal_expenses (
        id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        spent_on          DATE NOT NULL COMMENT 'farm date',
        category          VARCHAR(12) NOT NULL COMMENT 'supplies, bedding, farrier, vet, purchase, equipment, other',
        description       VARCHAR(200) NOT NULL,
        vendor            VARCHAR(120) NULL,
        amount_cents      BIGINT NOT NULL,
        animal_id         INT NULL COMMENT 'one animal',
        classification_id INT NULL COMMENT 'a whole species (when animal_id is empty); both empty = all animals',
        created_at        DATETIME NOT NULL COMMENT 'UTC',
        created_by        INT NULL,
        voided_at         DATETIME NULL COMMENT 'UTC',
        voided_by         INT NULL,
        void_reason       VARCHAR(255) NULL,
        KEY idx_spent (spent_on),
        KEY idx_animal (animal_id, spent_on),
        KEY idx_species (classification_id, spent_on)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    try { $pdo->exec("INSERT IGNORE INTO view_permissions (role, view_name, can_access) VALUES ('moderator', 'animal_expenses', 1)"); }
    catch (Throwable $e) { /* no permissions table yet */ }
};
