<?php
// admin/migrations/0034_calendar.php — Farm OS Phase 3: master calendar and phone calendar feeds (Nov 12, 2026; 12 › Master calendar).
//   calendar_feeds     one private, read-only feed per staff member for their phone's calendar app. Only a SHA-256 of the
//                      secret is stored; resetting makes a new one and the old link stops working.
//   view_permissions   'calendar' for every staff role (each person only sees the layers their other permissions allow).
// The calendar itself stores nothing: it reads bookings, parties, public events, tasks, orders, interviews and closures
// from their own tables each time. Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS calendar_feeds (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id       INT NOT NULL COMMENT 'users.id (staff)',
        token_hash    CHAR(64) NOT NULL COMMENT 'sha256 of the secret in the feed link',
        with_events   TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = parties and group bookings too, not only my tasks',
        created_at    DATETIME NOT NULL COMMENT 'UTC',
        last_used_at  DATETIME NULL COMMENT 'UTC, last time a calendar app fetched it',
        UNIQUE KEY uq_user (user_id),
        UNIQUE KEY uq_token (token_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    // Only missing rows are added, so a choice made in Permissions is kept.
    $vp = $pdo->prepare("INSERT IGNORE INTO view_permissions (role, view_name, can_access) VALUES (?, 'calendar', 1)");
    foreach (['moderator', 'Shopkeep', 'Staff', 'Media'] as $role) $vp->execute([$role]);
};
