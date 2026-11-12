<?php
// admin/migrations/0040_animals_core.php — Farm OS Phase 5 slice 1: roster, status and movement (Nov 12, 2026;
// 08 › Animal profile, Status and disposition, Movement; includes/animals.php).
//   animals                  + status (active, resting, quarantined, sold, transferred, adopted, deceased; existing
//                              animals start active), sex, birth_estimated, tag, microchip, enclosure_id, status_changed_at.
//                              is_active stays what it was: it is the public-profile switch, not the animal's status.
//   enclosures               pens, barns and pastures, with an optional capacity (a warning, never a block).
//   animal_locations         one row per stay in an enclosure: moving an animal ends the open row and starts a new one.
//   animal_status_history    every status change, with reason, the other party (sold to, adopted by…) and a sale reference.
// Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $has = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };
    $pdo->exec("CREATE TABLE IF NOT EXISTS enclosures (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(80) NOT NULL,
        capacity    SMALLINT UNSIGNED NULL COMMENT 'animals; NULL = no limit. Over it shows a warning only',
        notes       VARCHAR(500) NULL,
        active      TINYINT(1) NOT NULL DEFAULT 1,
        sort_order  SMALLINT NOT NULL DEFAULT 0,
        created_at  DATETIME NOT NULL COMMENT 'UTC',
        UNIQUE KEY uq_name (name)
    ) $cs");
    if (!$has('animals', 'status')) {
        $pdo->exec("ALTER TABLE animals
            ADD COLUMN status            VARCHAR(12) NOT NULL DEFAULT 'active' COMMENT 'active, resting, quarantined, sold, transferred, adopted, deceased (0040)',
            ADD COLUMN sex               VARCHAR(10) NULL COMMENT 'female, male, wether, unknown',
            ADD COLUMN birth_estimated   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = date_of_birth is a guess',
            ADD COLUMN tag               VARCHAR(60) NULL COMMENT 'ear tag or band',
            ADD COLUMN microchip         VARCHAR(60) NULL,
            ADD COLUMN enclosure_id      INT UNSIGNED NULL COMMENT 'where it is now; history in animal_locations',
            ADD COLUMN status_changed_at DATETIME NULL COMMENT 'UTC',
            ADD KEY idx_status (status),
            ADD KEY idx_enclosure (enclosure_id)");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS animal_locations (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        animal_id    INT NOT NULL,
        enclosure_id INT UNSIGNED NOT NULL,
        started_at   DATETIME NOT NULL COMMENT 'UTC',
        ended_at     DATETIME NULL COMMENT 'UTC; NULL = still there',
        moved_by     INT NULL,
        note         VARCHAR(255) NULL,
        KEY idx_animal (animal_id, ended_at),
        KEY idx_enclosure (enclosure_id, ended_at)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS animal_status_history (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        animal_id   INT NOT NULL,
        from_status VARCHAR(12) NULL,
        to_status   VARCHAR(12) NOT NULL,
        reason      VARCHAR(255) NULL,
        other_party VARCHAR(160) NULL COMMENT 'buyer, new home or receiving farm',
        sale_ref    VARCHAR(60) NULL COMMENT 'receipt number or note for a sale',
        changed_by  INT NULL,
        changed_at  DATETIME NOT NULL COMMENT 'UTC',
        KEY idx_animal (animal_id, changed_at)
    ) $cs");
};
