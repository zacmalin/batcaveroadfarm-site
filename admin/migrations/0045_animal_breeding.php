<?php
// admin/migrations/0045_animal_breeding.php — Farm OS Phase 5 slice 6: breeding (Nov 12, 2026; 08 › Breeding;
// includes/animal_breeding.php).
//   animals             + dam_id, sire_id (the mother and father, when known and on the roster).
//   animal_breedings    one breeding: dam, sire (on the roster) or an outside sire by name, date, method, expected birth
//                       date, status (bred, confirmed, born, open = not pregnant, lost), outcome date, kids born alive and
//                       lost, notes, and the "due soon" task it made. Never deleted; edits are audited.
// Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $has = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };
    if (!$has('animals', 'dam_id')) {
        $pdo->exec("ALTER TABLE animals
            ADD COLUMN dam_id  INT NULL COMMENT 'mother (animals.id), 0045',
            ADD COLUMN sire_id INT NULL COMMENT 'father (animals.id), 0045',
            ADD KEY idx_dam (dam_id),
            ADD KEY idx_sire (sire_id)");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS animal_breedings (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        dam_id        INT NOT NULL,
        sire_id       INT NULL COMMENT 'on the roster',
        sire_outside  VARCHAR(120) NULL COMMENT 'a sire that is not on the roster',
        bred_on       DATE NOT NULL COMMENT 'farm date',
        method        VARCHAR(12) NOT NULL DEFAULT 'natural' COMMENT 'natural, ai, other',
        expected_on   DATE NULL COMMENT 'expected birth date',
        status        VARCHAR(12) NOT NULL DEFAULT 'bred' COMMENT 'bred, confirmed, born, open, lost',
        outcome_on    DATE NULL,
        born_alive    TINYINT UNSIGNED NULL,
        born_lost     TINYINT UNSIGNED NULL,
        notes         VARCHAR(1000) NULL,
        due_task_id   BIGINT UNSIGNED NULL COMMENT 'task a week before the expected date',
        created_at    DATETIME NOT NULL COMMENT 'UTC',
        created_by    INT NULL,
        updated_at    DATETIME NULL COMMENT 'UTC',
        updated_by    INT NULL,
        KEY idx_dam (dam_id, bred_on),
        KEY idx_sire (sire_id),
        KEY idx_expected (status, expected_on)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
};
