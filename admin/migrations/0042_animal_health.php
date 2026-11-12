<?php
// admin/migrations/0042_animal_health.php — Farm OS Phase 5 slice 3: health (Nov 12, 2026; 08 › Health; includes/animal_health.php).
//   animal_medical_records   exams, treatments, injuries, illnesses: date, vet, diagnosis, treatment, cost (cents), follow-up
//                            date and the follow-up task it made. Never deleted; edits are audited.
//   animal_medications       name, dose, route, how often, start and end. While active it makes dose tasks a day or two
//                            ahead; stopping it (or the animal leaving) cancels the doses nobody has done yet.
//   animal_med_doses         one row per dose task made, so each dose is made once.
//   animal_vaccinations      vaccine, date given, by whom, lot, next due, and the task made for the next due date.
//   view_permissions         'animal_health' for Managers (moderator). Owners always have it. Staff see dose tasks only.
// Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS animal_medical_records (
        id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        animal_id        INT NOT NULL,
        record_date      DATE NOT NULL COMMENT 'farm date',
        kind             VARCHAR(12) NOT NULL COMMENT 'exam, treatment, injury, illness, other',
        title            VARCHAR(150) NOT NULL,
        vet_name         VARCHAR(120) NULL,
        diagnosis        TEXT NULL,
        treatment        TEXT NULL,
        cost_cents       BIGINT NULL,
        follow_up_date   DATE NULL,
        follow_up_task_id BIGINT UNSIGNED NULL,
        created_at       DATETIME NOT NULL COMMENT 'UTC',
        created_by       INT NULL,
        updated_at       DATETIME NULL COMMENT 'UTC',
        updated_by       INT NULL,
        KEY idx_animal (animal_id, record_date)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS animal_medications (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        animal_id     INT NOT NULL,
        name          VARCHAR(120) NOT NULL,
        dose          VARCHAR(60) NOT NULL COMMENT 'e.g. 2 ml',
        route         VARCHAR(20) NULL COMMENT 'mouth, injection, topical, eye, ear, other',
        frequency     VARCHAR(12) NOT NULL COMMENT 'daily, twice_daily, every_n, weekly, as_needed',
        interval_days SMALLINT UNSIGNED NULL COMMENT 'every_n only',
        start_date    DATE NOT NULL COMMENT 'farm date',
        end_date      DATE NULL COMMENT 'last day of doses; NULL = until stopped',
        notes         VARCHAR(500) NULL,
        stopped_at    DATETIME NULL COMMENT 'UTC',
        stopped_by    INT NULL,
        stop_reason   VARCHAR(255) NULL,
        created_at    DATETIME NOT NULL COMMENT 'UTC',
        created_by    INT NULL,
        KEY idx_animal (animal_id),
        KEY idx_active (stopped_at, end_date)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS animal_med_doses (
        medication_id INT UNSIGNED NOT NULL,
        dose_date     DATE NOT NULL,
        slot          TINYINT UNSIGNED NOT NULL COMMENT '1 = morning, 2 = evening',
        task_id       BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (medication_id, dose_date, slot),
        KEY idx_task (task_id)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS animal_vaccinations (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        animal_id   INT NOT NULL,
        vaccine     VARCHAR(120) NOT NULL,
        given_on    DATE NOT NULL COMMENT 'farm date',
        given_by    VARCHAR(120) NULL,
        lot         VARCHAR(60) NULL,
        next_due    DATE NULL,
        due_task_id BIGINT UNSIGNED NULL COMMENT 'task made for next_due',
        notes       VARCHAR(500) NULL,
        created_at  DATETIME NOT NULL COMMENT 'UTC',
        created_by  INT NULL,
        KEY idx_animal (animal_id, vaccine, given_on),
        KEY idx_due (next_due)
    ) $cs");
    $pdo->exec("INSERT IGNORE INTO view_permissions (role, view_name, can_access) VALUES ('moderator', 'animal_health', 1)");
};
