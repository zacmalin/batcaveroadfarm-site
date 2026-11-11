<?php
// admin/migrations/0029_tasks.php — Farm OS Phase 3 slice 1: tasks and chores (Nov 11, 2026; 04 › Tasks)
//   tasks             one row per job to do: one-off, or one day of a chore. Checklist kept on the task.
//   task_templates    reusable checklists for one-off jobs ("Vet visit prep")
//   task_recurrences  chores: what to do, who, and when (daily, every N days, weekdays, monthly; open days only; season)
//   view_permissions  'tasks' (see and do tasks) for moderator, Shopkeep, Staff; 'tasks_manage' (chores, checklists,
//                     everyone's tasks) for moderator. Administrators always pass. Only inserted when missing.
//   Seeds four chores (only once): Morning feed & water, Opening checklist, Closing checklist, Evening feed & lock-up.
// Due dates are farm dates (due_date + due_time, farm time zone) so "Tuesday's feed" stays Tuesday; stamps are UTC.
// Safe to repeat.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    $pdo->exec("CREATE TABLE IF NOT EXISTS task_templates (
        id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        title            VARCHAR(150) NOT NULL,
        description      TEXT NULL,
        checklist_json   TEXT NULL COMMENT 'JSON array of strings',
        priority         VARCHAR(10) NOT NULL DEFAULT 'normal' COMMENT 'low, normal, high, urgent',
        assignee_user_id INT NULL,
        assignee_role    VARCHAR(50) NULL COMMENT 'NULL with no user = anyone on the farm',
        active           TINYINT(1) NOT NULL DEFAULT 1,
        created_at       DATETIME NOT NULL COMMENT 'UTC',
        created_by       INT NULL,
        updated_at       DATETIME NULL
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS task_recurrences (
        id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        seed_key          VARCHAR(40) NULL COMMENT 'starter chores from this migration',
        title             VARCHAR(150) NOT NULL,
        description       TEXT NULL,
        checklist_json    TEXT NULL COMMENT 'JSON array of strings, copied onto each day''s task',
        priority          VARCHAR(10) NOT NULL DEFAULT 'normal',
        assignee_user_id  INT NULL,
        assignee_role     VARCHAR(50) NULL,
        freq              VARCHAR(10) NOT NULL DEFAULT 'daily' COMMENT 'daily, every_n, weekly, monthly',
        interval_n        SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'every N days (every_n) or N weeks (weekly)',
        weekdays          VARCHAR(20) NULL COMMENT 'weekly: 0=Sun..6=Sat, comma separated',
        month_day         TINYINT UNSIGNED NULL COMMENT 'monthly: 1-31 (last day when the month is shorter)',
        open_days_only    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'skip days the farm is closed (Hours, booking blackouts)',
        season_start      CHAR(5) NULL COMMENT 'MM-DD, with season_end: only inside this window (may wrap the new year)',
        season_end        CHAR(5) NULL,
        due_anchor        VARCHAR(10) NOT NULL DEFAULT 'time' COMMENT 'time (due_time), opening, closing (that day''s hours)',
        due_time          TIME NULL,
        start_date        DATE NOT NULL,
        end_date          DATE NULL,
        horizon_days      TINYINT UNSIGNED NOT NULL DEFAULT 7 COMMENT 'days ahead to create',
        generated_through DATE NULL,
        active            TINYINT(1) NOT NULL DEFAULT 1,
        created_at        DATETIME NOT NULL,
        created_by        INT NULL,
        updated_at        DATETIME NULL,
        UNIQUE KEY uq_seed (seed_key),
        KEY idx_active (active)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        title            VARCHAR(150) NOT NULL,
        description      TEXT NULL,
        checklist_json   TEXT NULL COMMENT 'JSON array of {t, done, by, at}',
        priority         VARCHAR(10) NOT NULL DEFAULT 'normal' COMMENT 'low, normal, high, urgent',
        status           VARCHAR(12) NOT NULL DEFAULT 'open' COMMENT 'open, in_progress, done, skipped, cancelled',
        due_date         DATE NULL COMMENT 'farm date',
        due_time         TIME NULL COMMENT 'farm time',
        assignee_user_id INT NULL,
        assignee_role    VARCHAR(50) NULL COMMENT 'NULL with no user = anyone on the farm',
        related_type     VARCHAR(40) NULL COMMENT 'e.g. animal, booking, inventory',
        related_id       VARCHAR(64) NULL,
        template_id      INT UNSIGNED NULL,
        recurrence_id    INT UNSIGNED NULL,
        occurrence_date  DATE NULL COMMENT 'which day of the chore this is',
        completion_note  VARCHAR(500) NULL,
        completed_at     DATETIME NULL COMMENT 'UTC',
        completed_by     INT NULL,
        created_at       DATETIME NOT NULL COMMENT 'UTC',
        created_by       INT NULL,
        updated_at       DATETIME NULL COMMENT 'UTC; NULL = nobody has touched it (chore days can be rebuilt)',
        updated_by       INT NULL,
        UNIQUE KEY uq_occurrence (recurrence_id, occurrence_date),
        KEY idx_due (status, due_date),
        KEY idx_user (assignee_user_id, status),
        KEY idx_related (related_type, related_id)
    ) $cs");

    // Who can open Tasks. Only missing rows are added, so a choice made in Permissions is kept.
    $vp = $pdo->prepare("INSERT IGNORE INTO view_permissions (role, view_name, can_access) VALUES (?, ?, ?)");
    foreach ([['moderator', 'tasks', 1], ['Shopkeep', 'tasks', 1], ['Staff', 'tasks', 1], ['Media', 'tasks', 0],
              ['moderator', 'tasks_manage', 1], ['Shopkeep', 'tasks_manage', 0], ['Staff', 'tasks_manage', 0], ['Media', 'tasks_manage', 0]] as $r) {
        $vp->execute($r);
    }

    // Starter chores (Zac, Nov 11: all four, anyone on the farm). Edit the checklists on Tasks › Chores.
    $tz = 'America/New_York';
    try {
        $v = trim((string)$pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'timezone'")->fetchColumn());
        if ($v !== '') { new DateTimeZone($v); $tz = $v; }
    } catch (Throwable $e) { /* keep Eastern */ }
    $start = (new DateTimeImmutable('tomorrow', new DateTimeZone($tz)))->format('Y-m-d');   // so today's 7:30 feed isn't late the moment this runs
    $seed = [
        ['morning_feed', 'Morning feed & water', 'high', 0, 'time', '07:30:00', [
            'Head count: everyone up, alert and eating',
            'Fresh water: goats, sheep and donkey',
            'Feed goats and sheep',
            'Feed the pigs (and water)',
            'Let the birds out; feed and water chickens, ducks and turkeys',
            'Feed the peacocks',
            'Feed and water the rabbits',
            'Collect eggs',
            'Anyone limping, off feed or acting odd? Say who in the note',
        ]],
        ['opening', 'Opening checklist', 'high', 1, 'opening', null, [
            'Walk the grounds: gates, fences and paths safe',
            'Hand-wash station stocked (soap, water, towels)',
            'Visitor feed stocked',
            'Restrooms checked and stocked',
            'Register on; drawer counted and opened',
            'Signs out and the entrance gate open',
        ]],
        ['closing', 'Closing checklist', 'normal', 1, 'closing', null, [
            'All visitors out; entrance gate closed',
            'Register closed and drawer counted',
            'Hand-wash station and restrooms cleaned and restocked',
            'Trash emptied',
            'Walk the pens: every gate latched',
            'Signs in',
        ]],
        ['evening_feed', 'Evening feed & lock-up', 'high', 0, 'time', '17:30:00', [
            'Feed goats, sheep and donkey',
            'Feed the pigs',
            'Feed the rabbits',
            'Top off all water',
            'Birds in and coops shut: chickens, ducks, turkeys, peacocks',
            'Collect any eggs',
            'Head count; every gate latched and locked',
        ]],
    ];
    $ins = $pdo->prepare("INSERT IGNORE INTO task_recurrences
        (seed_key, title, checklist_json, priority, freq, interval_n, open_days_only, due_anchor, due_time, start_date, horizon_days, active, created_at)
        VALUES (?, ?, ?, ?, 'daily', 1, ?, ?, ?, ?, 7, 1, UTC_TIMESTAMP())");
    foreach ($seed as [$key, $title, $prio, $open, $anchor, $time, $list]) {
        $ins->execute([$key, $title, json_encode($list, JSON_UNESCAPED_UNICODE), $prio, $open, $anchor, $time, $start]);
    }
};
