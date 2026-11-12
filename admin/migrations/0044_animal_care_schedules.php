<?php
// admin/migrations/0044_animal_care_schedules.php — Farm OS Phase 5 slice 5: care schedules (Nov 12, 2026; 08 › Reference data,
// Tasks that create records; includes/animal_schedules.php).
//   animal_care_schedules      a species' routine: which care type, how often (days), the first due date for animals never
//                              done, and a note for whoever does it ("goats: hoof trim every 8 weeks"). One per species and type.
//   animal_care_schedule_tasks the task made for one animal and one due date, so each is made once. Finishing that task logs
//                              the care for the animal, which sets the next due date.
// Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS animal_care_schedules (
        id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        classification_id INT NOT NULL COMMENT 'the species (classifications.id)',
        care_type_id      INT UNSIGNED NOT NULL,
        every_days        SMALLINT UNSIGNED NOT NULL,
        start_date        DATE NOT NULL COMMENT 'farm date: when animals never done are first due',
        note              VARCHAR(500) NULL COMMENT 'goes on each task',
        active            TINYINT(1) NOT NULL DEFAULT 1,
        created_at        DATETIME NOT NULL COMMENT 'UTC',
        created_by        INT NULL,
        updated_at        DATETIME NULL COMMENT 'UTC',
        updated_by        INT NULL,
        UNIQUE KEY uq_species_type (classification_id, care_type_id)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS animal_care_schedule_tasks (
        schedule_id INT UNSIGNED NOT NULL,
        animal_id   INT NOT NULL,
        due_date    DATE NOT NULL,
        task_id     BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (schedule_id, animal_id, due_date),
        UNIQUE KEY uq_task (task_id)
    ) $cs");
};
