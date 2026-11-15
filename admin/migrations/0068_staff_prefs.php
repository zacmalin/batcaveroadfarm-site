<?php
// admin/migrations/0068_staff_prefs.php — per-person settings that follow a staff member to any phone (Nov 15, 2026).
//   staff_prefs   one row per person per setting. First use: 'nav_shortcuts' = the phone bottom-bar shortcuts
//                 (JSON list of menu ids, e.g. ["dashboard","storefront.pos"]). Logic: includes/staff_prefs.php;
//                 saved by admin/nav_prefs.php; read by admin/views/layout/sidebar.php.
// Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_prefs (
        user_id     INT NOT NULL COMMENT 'users.id',
        pref_key    VARCHAR(40) NOT NULL COMMENT 'nav_shortcuts, ...',
        pref_value  TEXT NOT NULL COMMENT 'JSON',
        updated_at  DATETIME NOT NULL COMMENT 'UTC',
        PRIMARY KEY (user_id, pref_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
};
