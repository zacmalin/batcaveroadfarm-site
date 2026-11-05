<?php
// admin/migrations/0003_audit_events.php — Farm OS Phase 0, C-06 (Nov 5, 2026)
// One audit table for everything (04 — Core Data Model: audit_events). Rows are never changed after
// they're written. Times are UTC. before/after are named before_json/after_json because BEFORE and
// AFTER are reserved words in MySQL.
//
// Copies every row from the two old tables:
//   audit_log  (erp_audit(), staff logins)  created_at is a TIMESTAMP, read here as UTC
//   site_audit (Site Info changes)          changed_at is a DATETIME in the database's own time zone,
//                                           converted to UTC
// Each copied row keeps legacy_source + legacy_id, so running this again copies nothing twice.
// The old tables are left in place (read-only from now on); a later migration removes them.
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_events (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        occurred_at  DATETIME(3)  NOT NULL COMMENT 'UTC',
        actor_type   VARCHAR(20)  NOT NULL DEFAULT 'staff' COMMENT 'staff, system, agent, customer, anonymous',
        actor_id     BIGINT UNSIGNED NULL,
        actor_label  VARCHAR(120) NOT NULL DEFAULT '',
        device_id    BIGINT UNSIGNED NULL,
        ip           VARCHAR(45)  NULL,
        action       VARCHAR(40)  NOT NULL,
        object_type  VARCHAR(100) NOT NULL,
        object_id    VARCHAR(64)  NULL,
        object_label VARCHAR(255) NOT NULL DEFAULT '',
        before_json  JSON NULL,
        after_json   JSON NULL,
        legacy_source VARCHAR(20) NULL COMMENT 'audit_log or site_audit for copied rows',
        legacy_id    INT UNSIGNED NULL,
        KEY idx_occurred (occurred_at),
        KEY idx_object (object_type, object_id),
        KEY idx_actor (actor_type, actor_id),
        KEY idx_action (action),
        UNIQUE KEY uq_legacy (legacy_source, legacy_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $exists = fn(string $t) => (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables
                                                 WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($t))->fetchColumn() > 0;

    // The database's own time zone, and its current offset as a fallback if named zones aren't loaded.
    $dbTz      = (string)$pdo->query("SELECT @@session.time_zone")->fetchColumn();
    $offsetMin = (int)$pdo->query("SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW())")->fetchColumn();

    if ($exists('audit_log')) {
        $pdo->exec("SET time_zone = '+00:00'");   // TIMESTAMP columns now read as UTC
        try {
            $pdo->exec("INSERT IGNORE INTO audit_events
                (occurred_at, actor_type, actor_id, actor_label, ip, action, object_type, object_id, object_label, legacy_source, legacy_id)
                SELECT COALESCE(created_at, UTC_TIMESTAMP()),
                       CASE WHEN user_name LIKE '%(AI:%' THEN 'agent'
                            WHEN user_id IS NULL AND table_name = 'staff_login' THEN 'anonymous'
                            WHEN user_id IS NULL THEN 'system'
                            ELSE 'staff' END,
                       user_id, user_name, ip_address, action, table_name,
                       CAST(record_id AS CHAR), record_label, 'audit_log', id
                FROM audit_log");
        } finally {
            $pdo->exec("SET time_zone = " . $pdo->quote($dbTz));
        }
    }

    if ($exists('site_audit')) {
        $st = $pdo->prepare("INSERT IGNORE INTO audit_events
            (occurred_at, actor_type, actor_id, actor_label, action, object_type, object_id, object_label,
             before_json, after_json, legacy_source, legacy_id)
            SELECT COALESCE(CONVERT_TZ(changed_at, ?, '+00:00'), DATE_SUB(changed_at, INTERVAL ? MINUTE)),
                   IF(admin_user_id IS NULL, 'system', 'staff'),
                   admin_user_id, admin_label, 'update', 'site_info', NULL,
                   LEFT(CONCAT(area, ': ', change_key), 255),
                   JSON_OBJECT(change_key, old_value), JSON_OBJECT(change_key, new_value),
                   'site_audit', id
            FROM site_audit");
        $st->execute([$dbTz, $offsetMin]);
    }
};
