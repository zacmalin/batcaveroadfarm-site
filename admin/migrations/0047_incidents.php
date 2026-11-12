<?php
// admin/migrations/0047_incidents.php — Farm OS Phase 5 slice 8: incidents (Nov 12, 2026; 08 › Incidents; includes/incidents.php).
//   incidents          what happened, when (UTC) and where; type, severity, what was done, staff, witnesses, follow-up.
//                      Open until a manager closes it. Never deleted.
//   incident_animals   animals involved.
//   incident_people    visitors involved, with contact details and the customer, waiver, check-in, booking or party
//                      found for them when the incident was saved.
//   incident_photos    photos (stored outside the website folder, like task photos).
//   incident_edits     every change after the report was saved: field, old and new value, who and when.
//   view_permissions   'incidents' for Managers (moderator). Owners always have it. Any staff member can report one.
// Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS incidents (
        id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        happened_at       DATETIME NOT NULL COMMENT 'UTC',
        type              VARCHAR(16) NOT NULL COMMENT 'visitor_injury, animal_injury, escape, property, other',
        injury            VARCHAR(10) NULL COMMENT 'visitor injuries: bite, scratch, fall, other',
        severity          VARCHAR(10) NOT NULL COMMENT 'minor, moderate, serious',
        location          VARCHAR(120) NOT NULL,
        description       TEXT NOT NULL,
        action_taken      TEXT NULL COMMENT 'first aid or other action taken',
        staff_involved    VARCHAR(255) NULL,
        witnesses         TEXT NULL,
        follow_up         TEXT NULL,
        status            VARCHAR(8) NOT NULL DEFAULT 'open' COMMENT 'open, closed',
        closed_at         DATETIME NULL COMMENT 'UTC',
        closed_by         INT NULL,
        close_note        VARCHAR(500) NULL,
        task_id           INT NULL COMMENT 'follow-up task',
        owner_notified_at DATETIME NULL COMMENT 'UTC',
        created_at        DATETIME NOT NULL COMMENT 'UTC',
        created_by        INT NULL,
        updated_at        DATETIME NULL COMMENT 'UTC',
        updated_by        INT NULL,
        KEY idx_happened (happened_at),
        KEY idx_status (status, happened_at),
        KEY idx_creator (created_by)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS incident_animals (
        incident_id INT UNSIGNED NOT NULL,
        animal_id   INT NOT NULL,
        PRIMARY KEY (incident_id, animal_id),
        KEY idx_animal (animal_id)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS incident_people (
        id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        incident_id      INT UNSIGNED NOT NULL,
        name             VARCHAR(120) NOT NULL,
        phone            VARCHAR(30) NULL,
        email            VARCHAR(190) NULL,
        note             VARCHAR(255) NULL COMMENT 'e.g. hurt, parent of, age',
        customer_id      INT NULL,
        waiver_id        INT UNSIGNED NULL,
        check_in_id      BIGINT UNSIGNED NULL,
        booking_id       INT NULL,
        event_inquiry_id INT NULL,
        created_at       DATETIME NOT NULL COMMENT 'UTC',
        created_by       INT NULL,
        KEY idx_incident (incident_id),
        KEY idx_email (email)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS incident_photos (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        incident_id INT UNSIGNED NOT NULL,
        file        VARCHAR(80) NOT NULL,
        width       INT NOT NULL,
        height      INT NOT NULL,
        bytes       INT NOT NULL,
        created_at  DATETIME NOT NULL COMMENT 'UTC',
        created_by  INT NULL,
        KEY idx_incident (incident_id)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS incident_edits (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        incident_id INT UNSIGNED NOT NULL,
        field       VARCHAR(40) NOT NULL,
        old_value   TEXT NULL,
        new_value   TEXT NULL,
        edited_at   DATETIME NOT NULL COMMENT 'UTC',
        edited_by   INT NULL,
        KEY idx_incident (incident_id, id)
    ) $cs");
    try { $pdo->exec("INSERT IGNORE INTO view_permissions (role, view_name, can_access) VALUES ('moderator', 'incidents', 1)"); }
    catch (Throwable $e) { /* no permissions table yet */ }
};
