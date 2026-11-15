<?php
// admin/migrations/0069_renewals.php — Renewals: things the farm has to renew or that run out on a date (Nov 15, 2026).
//   renewals          domain, hosting, insurance policy, licenses and permits, subscriptions, account tokens... One row per
//                     thing, with the next date it renews and how often it comes back (repeat_months; 0 = only once).
//                     Logic: includes/renewals.php; screen: admin/renewals.php; on the calendar as "Renewals & due dates".
//                     Deleting only hides a row (deleted_at); it can be restored. Money in integer cents, times in UTC.
//   view_permissions  'renewals' for Managers (moderator). Owners always have it.
// Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS renewals (
        id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        title             VARCHAR(150) NOT NULL,
        category          VARCHAR(20) NOT NULL DEFAULT 'other' COMMENT 'website, insurance, license, subscription, account, vehicle, membership, other',
        renews_on         DATE NOT NULL COMMENT 'next renewal or expiry date (farm date)',
        repeat_months     SMALLINT UNSIGNED NOT NULL DEFAULT 12 COMMENT '0 = only once; 1 monthly, 3 quarterly, 12 yearly, 36 every 3 years',
        remind_days       SMALLINT UNSIGNED NOT NULL DEFAULT 30 COMMENT 'bell, email and a task this many days before',
        auto_renews       TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = renews by itself; the reminder is a check that it did',
        cost_cents        INT NULL COMMENT 'what it costs each time, in cents',
        provider          VARCHAR(120) NULL COMMENT 'who it is with (registrar, insurer, county...)',
        account_ref       VARCHAR(120) NULL COMMENT 'account or policy number; never a password',
        link_url          VARCHAR(255) NULL COMMENT 'where to renew',
        vendor_id         INT UNSIGNED NULL COMMENT 'vendors.id, optional',
        assignee_user_id  INT NULL COMMENT 'users.id who handles it; NULL = assignee_role',
        assignee_role     VARCHAR(20) NULL DEFAULT 'administrator' COMMENT 'administrator (Owners) or moderator (Managers)',
        notes             TEXT NULL,
        last_renewed_on   DATE NULL,
        task_id           BIGINT UNSIGNED NULL COMMENT 'tasks.id of the open Renew task for renews_on',
        finished_at       DATETIME NULL COMMENT 'UTC; a one-time item that is dealt with',
        created_at        DATETIME NOT NULL COMMENT 'UTC',
        created_by        INT NULL,
        updated_at        DATETIME NULL COMMENT 'UTC',
        updated_by        INT NULL,
        deleted_at        DATETIME NULL COMMENT 'UTC; hidden, can be restored',
        deleted_by        INT NULL,
        KEY idx_due (deleted_at, finished_at, renews_on),
        KEY idx_vendor (vendor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    $pdo->exec("INSERT IGNORE INTO view_permissions (role, view_name, can_access) VALUES ('moderator', 'renewals', 1)");
};
