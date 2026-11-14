<?php
// admin/migrations/0064_staff_access_extras.php — per-person extra access (Nov 14, 2026).
//   staff_access_extras   one row per person per page or table an Owner gave them on top of their role
//                         (kind 'view' = a page, can_view only; kind 'table' = a table, can_view / can_edit)
// Logic: includes/staff_extras.php (checked by hasViewAccess() / hasTableAccess()); edited on the Permissions page, By person tab.
// Adds only; safe to repeat.
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_access_extras (
        id          INT NOT NULL AUTO_INCREMENT,
        user_id     INT NOT NULL COMMENT 'users.id',
        kind        VARCHAR(10) NOT NULL COMMENT 'view or table',
        item_key    VARCHAR(64) NOT NULL COMMENT 'view name or table name, as on the Permissions page',
        can_view    TINYINT(1) NOT NULL DEFAULT 1,
        can_edit    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'tables only',
        granted_by  INT NULL COMMENT 'users.id of the Owner who ticked it',
        granted_at  DATETIME NOT NULL COMMENT 'UTC',
        PRIMARY KEY (id),
        UNIQUE KEY uq_staff_access_extras (user_id, kind, item_key),
        KEY ix_staff_access_extras_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
};
