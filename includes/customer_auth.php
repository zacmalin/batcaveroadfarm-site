<?php // includes/customer_auth.php
/*
 * Customer accounts (Farm OS Phase 0, C-02, Nov 2026). Public sign-ups live in the customers table; the
 * users table is staff only. The two are separate sign-ins: the public site sets only customer_* session
 * values, the console only user_id / user_role, so nothing on the public side can ever grant console access.
 * A staff member who also uses the public site has a linked customer account (customers.staff_user_id).
 *
 * Until migration 0006_customer_accounts runs, these read and write the old place (users with
 * role = 'user'), so nothing is lost in between.
 *
 * Password resets: password_resets.realm says which table a row's user_id points at ('customer' or
 * 'staff'; staff invites are purpose 'invite'). See account/forgot.php and account/reset.php.
 */

if (!function_exists('bcrf_customers_ready')) {

/** True once the customers table exists (cached per request). */
function bcrf_customers_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        return $ready = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'customers'")->fetchColumn() > 0;
    } catch (Throwable $e) { return $ready = false; }
}

/** The table customer accounts are in, and the WHERE condition that limits it to customers. */
function bcrf_customer_table(PDO $pdo): string { return bcrf_customers_ready($pdo) ? 'customers' : 'users'; }
function bcrf_customer_scope(PDO $pdo): string { return bcrf_customers_ready($pdo) ? '1=1' : "role = 'user'"; }

function bcrf_customer_find_by_email(PDO $pdo, string $email): ?array {
    $t = bcrf_customer_table($pdo);
    $st = $pdo->prepare("SELECT * FROM {$t} WHERE email = ? AND " . bcrf_customer_scope($pdo) . " LIMIT 1");
    $st->execute([strtolower(trim($email))]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function bcrf_customer_find(PDO $pdo, int $id): ?array {
    $t = bcrf_customer_table($pdo);
    $st = $pdo->prepare("SELECT * FROM {$t} WHERE id = ? AND " . bcrf_customer_scope($pdo) . " LIMIT 1");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Any account (customer, or staff in the old shared table) already using this email? */
function bcrf_customer_email_taken(PDO $pdo, string $email): bool {
    $t = bcrf_customer_table($pdo);   // old table: staff emails are taken too (users.email is unique)
    $st = $pdo->prepare("SELECT 1 FROM {$t} WHERE email = ? LIMIT 1");
    $st->execute([strtolower(trim($email))]);
    return (bool)$st->fetchColumn();
}

/** Create a customer account; returns its id. */
function bcrf_customer_create(PDO $pdo, string $first, string $last, string $email, ?string $phone, string $passwordHash): int {
    if (bcrf_customers_ready($pdo)) {
        $pdo->prepare("INSERT INTO customers (first_name, last_name, email, phone_number, password, created_at) VALUES (?, ?, ?, ?, ?, NOW())")
            ->execute([$first, $last, strtolower(trim($email)), $phone, $passwordHash]);
    } else {
        $pdo->prepare("INSERT INTO users (first_name, last_name, email, phone_number, password, role) VALUES (?, ?, ?, ?, ?, 'user')")
            ->execute([$first, $last, strtolower(trim($email)), $phone, $passwordHash]);
    }
    return (int)$pdo->lastInsertId();
}

function bcrf_customer_update(PDO $pdo, int $id, array $set): void {
    $t = bcrf_customer_table($pdo);
    $cols = []; $vals = [];
    foreach ($set as $k => $v) {
        if (!preg_match('/^[a-z_]+$/', $k)) continue;
        $cols[] = "`{$k}` = " . ($v === 'NOW()' ? 'NOW()' : '?');
        if ($v !== 'NOW()') $vals[] = $v;
    }
    if (!$cols) return;
    $vals[] = $id;
    $pdo->prepare("UPDATE {$t} SET " . implode(', ', $cols) . " WHERE id = ? AND " . bcrf_customer_scope($pdo))->execute($vals);
}

/** A customer account that's switched off can't sign in. */
function bcrf_customer_active(array $c): bool {
    return ($c['account_status'] ?? 'active') !== 'disabled';
}

/** password_resets has the realm column (added by migration 0006). */
function bcrf_resets_have_realm(PDO $pdo): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        return $has = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'password_resets' AND column_name = 'realm'")->fetchColumn() > 0;
    } catch (Throwable $e) { return $has = false; }
}

}
