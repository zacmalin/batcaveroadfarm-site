<?php
// admin/migrations/0006_customer_accounts.php — Farm OS Phase 0, C-02 (Nov 5, 2026)
// Splits public accounts out of users (which becomes staff only) into customers:
//   1. customers table. Public accounts (users.role = 'user') are copied with the SAME id, so recipe
//      comments, ratings, email verifications and password resets keep pointing at the right person.
//   2. Every staff member gets a linked customer account (staff_user_id, same email and password) so they can
//      still sign in on the public site; their comments and ratings move to it. Their ids start above every
//      users id at the time of the split, so moving those rows can't touch a public account's.
//   3. password_resets.realm: 'staff' for staff rows (invites and staff resets), 'customer' for the rest.
//   4. Email verifications for staff accounts are removed (customers only).
// The old role = 'user' rows stay in users for now (nothing reads them any more); a later migration
// removes them once the split has run for a week.
// Safe to repeat: every step checks what's already there.
return function (PDO $pdo): void {
    $col = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };

    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        id                       INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        first_name               VARCHAR(50)  NOT NULL,
        last_name                VARCHAR(50)  NOT NULL,
        email                    VARCHAR(100) NOT NULL,
        email_verified_at        DATETIME NULL,
        phone_number             VARCHAR(20)  NULL,
        password                 VARCHAR(255) NULL,
        comment_restricted_until DATE NULL,
        account_status           ENUM('active','disabled') NOT NULL DEFAULT 'active',
        staff_user_id            INT NULL COMMENT 'users.id when this person is also staff (reporting link only)',
        migrated_from_user_id    INT NULL COMMENT 'users.id this account was copied from (0006)',
        created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_email (email),
        UNIQUE KEY uq_migrated (migrated_from_user_id),
        KEY idx_staff (staff_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

    // 1. Public accounts, same ids
    $pdo->exec("INSERT IGNORE INTO customers
            (id, first_name, last_name, email, email_verified_at, phone_number, password, comment_restricted_until, account_status, migrated_from_user_id)
        SELECT id, first_name, last_name, LOWER(email), email_verified_at, phone_number, password, comment_restricted_until,
               IF(account_status = 'disabled', 'disabled', 'active'), id
        FROM users WHERE role = 'user'");

    // 2. Linked customer accounts for staff, numbered above every users id
    $next = (int)$pdo->query("SELECT GREATEST(COALESCE((SELECT MAX(id) FROM users), 0), COALESCE((SELECT MAX(id) FROM customers), 0)) + 1")->fetchColumn();
    $pdo->exec("ALTER TABLE customers AUTO_INCREMENT = " . max(1, $next));
    $pdo->exec("INSERT IGNORE INTO customers
            (first_name, last_name, email, email_verified_at, phone_number, password, account_status, staff_user_id, migrated_from_user_id)
        SELECT u.first_name, u.last_name, LOWER(u.email), COALESCE(u.email_verified_at, NOW()), u.phone_number, u.password,
               IF(u.account_status = 'disabled', 'disabled', 'active'), u.id, u.id
        FROM users u
        WHERE u.role <> 'user'
          AND NOT EXISTS (SELECT 1 FROM customers c WHERE c.migrated_from_user_id = u.id OR c.email = LOWER(u.email))");
    foreach (['recipe_comments', 'recipe_ratings'] as $t) {
        if ($col($t, 'user_id')) {
            $pdo->exec("UPDATE {$t} x JOIN customers c ON c.staff_user_id = x.user_id SET x.user_id = c.id
                        WHERE x.user_id IN (SELECT id FROM (SELECT id FROM users WHERE role <> 'user') s)");
        }
    }

    // 3. Which table a password reset points at
    if (!$col('password_resets', 'realm')) {
        $pdo->exec("ALTER TABLE password_resets ADD COLUMN realm VARCHAR(10) NOT NULL DEFAULT 'customer' COMMENT 'customer = customers.id, staff = users.id'");
    }
    $pdo->exec("UPDATE password_resets SET realm = 'staff'
                WHERE " . ($col('password_resets', 'purpose') ? "purpose = 'invite' OR " : '') . "
                      user_id IN (SELECT id FROM (SELECT id FROM users WHERE role <> 'user') s)");

    // 4. Email verification is for customer accounts only
    $pdo->exec("DELETE FROM email_verifications WHERE user_id IN (SELECT id FROM (SELECT id FROM users WHERE role <> 'user') s)");
};
