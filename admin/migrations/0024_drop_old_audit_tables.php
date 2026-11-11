<?php
// admin/migrations/0024_drop_old_audit_tables.php — Farm OS Phase 0 closeout (Nov 11, 2026)
// Removes the two audit tables that audit_events (0003) replaced:
//   audit_log   erp_audit() and staff logins, before Nov 5, 2026
//   site_audit  Site Info changes, before Nov 5, 2026
// 0003 copied every row into audit_events with legacy_source + legacy_id. Since then the code only wrote to
// these tables as a fallback when audit_events was missing, and that fallback is gone (admin/includes/audit.php,
// admin/handlers/site_info_save.php, Nov 11, 2026).
//
// Before dropping anything this:
//   1. copies again any row that isn't in audit_events yet (the same INSERT IGNORE as 0003, so nothing doubles)
//   2. checks every old row now has its copy (by legacy_source + legacy_id) and stops with an error if not
// The nightly database backup still holds the old tables if they are ever needed.
// Safe to repeat: a table that is already gone is skipped.
return function (PDO $pdo): void {
    $exists = fn(string $t) => (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables
                                                 WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($t))->fetchColumn() > 0;
    if (!$exists('audit_events')) throw new RuntimeException('audit_events is missing: run 0003 first.');
    if (!$exists('audit_log') && !$exists('site_audit')) return;   // already done

    // 1. Copy anything written since 0003 (0003 is written to be safe to repeat)
    $copy = require __DIR__ . '/0003_audit_events.php';
    $copy($pdo);

    // 2. Every old row must have its copy
    foreach (['audit_log', 'site_audit'] as $t) {
        if (!$exists($t)) continue;
        $old    = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        $copied = (int)$pdo->query("SELECT COUNT(*) FROM `$t` o JOIN audit_events e
                                     ON e.legacy_source = '$t' AND e.legacy_id = o.id")->fetchColumn();
        if ($copied !== $old) {
            throw new RuntimeException("$t: only $copied of $old rows are in audit_events. Nothing was dropped.");
        }
    }

    // 3. Drop
    foreach (['audit_log', 'site_audit'] as $t) {
        if ($exists($t)) $pdo->exec("DROP TABLE `$t`");
    }
};
