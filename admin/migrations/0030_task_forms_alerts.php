<?php
// admin/migrations/0030_task_forms_alerts.php — Farm OS Phase 3 slice 2: completion questions, photos, the morning
// list and late-task emails (Nov 11, 2026; 12 › Task templates and completion forms, Recurring tasks, Notifications)
//   task_recurrences, task_templates  + form_json (questions asked when the task is finished), photo_rule (none, optional,
//                                       required), followup_unticked (steps left unticked become follow-up tasks)
//   tasks                             + the same three, copied when the task is made, + answers_json, followup_of,
//                                       late_notified_at, escalated_at (each alert goes out once)
//   task_photos                       photos taken when finishing a task; files live outside the website folder
//   task_user_prefs                   per person: morning list on/off, late-task emails on/off, last list sent
//   site_settings                     task_late_after_min 30 (email whoever it's for), task_escalate_after_min 120
//                                       (email the managers); only added when missing
// Safe to repeat.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $has = function (string $table, string $col) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$table, $col]);
        return (int)$st->fetchColumn() > 0;
    };
    $add = function (string $table, string $col, string $def) use ($pdo, $has): void {
        if (!$has($table, $col)) $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
    };

    foreach (['task_recurrences', 'task_templates', 'tasks'] as $t) {
        $add($t, 'form_json', "TEXT NULL COMMENT 'questions asked on finishing: JSON [{k, q, type yesno|number|text|choice, options[], unit, required, followup no|yes}]'");
        $add($t, 'photo_rule', "VARCHAR(10) NOT NULL DEFAULT 'none' COMMENT 'none, optional, required'");
        $add($t, 'followup_unticked', "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'finishing with steps unticked makes a follow-up task for each'");
    }
    $add('tasks', 'answers_json', "TEXT NULL COMMENT 'answers to form_json, {k: value}'");
    $add('tasks', 'followup_of', "BIGINT UNSIGNED NULL COMMENT 'the task whose answer or unticked step made this one'");
    $add('tasks', 'late_notified_at', "DATETIME NULL COMMENT 'UTC: late email sent to whoever it is for'");
    $add('tasks', 'escalated_at', "DATETIME NULL COMMENT 'UTC: late email sent to the managers'");
    $idx = $pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'tasks' AND index_name = 'idx_followup'")->fetchColumn();
    if (!(int)$idx) $pdo->exec("ALTER TABLE tasks ADD KEY idx_followup (followup_of)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS task_photos (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        task_id     BIGINT UNSIGNED NOT NULL,
        file        VARCHAR(80) NOT NULL COMMENT 'YYYY/MM/random.webp under bcrf-uploads/tasks',
        width       SMALLINT UNSIGNED NOT NULL,
        height      SMALLINT UNSIGNED NOT NULL,
        bytes       INT UNSIGNED NOT NULL,
        created_at  DATETIME NOT NULL COMMENT 'UTC',
        created_by  INT NULL,
        KEY idx_task (task_id)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS task_user_prefs (
        user_id          INT NOT NULL PRIMARY KEY,
        digest           TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'morning list email',
        late_alerts      TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'late-task emails',
        last_digest_date DATE NULL COMMENT 'farm date of the last morning list sent',
        updated_at       DATETIME NULL
    ) $cs");

    $st = $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
    try {
        $st->execute(['task_late_after_min', '30']);
        $st->execute(['task_escalate_after_min', '120']);
    } catch (Throwable $e) { /* site_settings missing: code defaults apply */ }
};
