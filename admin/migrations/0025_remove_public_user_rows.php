<?php
// admin/migrations/0025_remove_public_user_rows.php — Farm OS Phase 0 closeout, C-02 (Nov 11, 2026)
// 0006 (Nov 5, 2026) copied every public account (users.role = 'user') into customers with the SAME id and
// left the old rows in users. Nothing reads them any more: sign-in, comments, ratings, resets and email
// verification all use customers. This removes them, so users holds staff only.
//
// Only deletes a role = 'user' row when ALL of these hold:
//   - customers has its copy (customers.migrated_from_user_id = users.id, not a staff-linked account)
//   - no time card points at it (time_cards.user_id has ON DELETE CASCADE, so it would take the card with it)
//   - no staff employment record points at it
// Rows that fail a check are left in place and listed in the PHP error log, so they can be looked at by hand.
// The nightly database backup still holds the rows if they are ever needed.
// Safe to repeat: the second run finds nothing to delete.
return function (PDO $pdo): void {
    $exists = fn(string $t) => (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables
                                                 WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($t))->fetchColumn() > 0;
    if (!$exists('customers')) throw new RuntimeException('customers is missing: run 0006 first.');

    $blockers = ["NOT EXISTS (SELECT 1 FROM customers c WHERE c.migrated_from_user_id = u.id AND c.staff_user_id IS NULL)"];
    if ($exists('time_cards'))      $blockers[] = "EXISTS (SELECT 1 FROM time_cards t WHERE t.user_id = u.id)";
    if ($exists('user_employment')) $blockers[] = "EXISTS (SELECT 1 FROM user_employment e WHERE e.user_id = u.id)";
    $blocked = '(' . implode(' OR ', $blockers) . ')';

    $kept = $pdo->query("SELECT u.id FROM users u WHERE u.role = 'user' AND $blocked ORDER BY u.id")->fetchAll(PDO::FETCH_COLUMN);
    if ($kept) {
        error_log('0025_remove_public_user_rows: kept ' . count($kept) . ' role=user row(s) that failed a safety check (no customers copy, '
                . 'or a time card / employment record points at it): users.id ' . implode(', ', array_slice($kept, 0, 50))
                . (count($kept) > 50 ? ', …' : ''));
    }

    $pdo->exec("DELETE u FROM users u WHERE u.role = 'user' AND NOT $blocked");
};
