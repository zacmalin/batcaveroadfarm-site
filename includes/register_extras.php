<?php // includes/register_extras.php
/*
 * Farm OS Phase 1, slice 7 (Nov 6, 2026): register extras from 05 › POS.
 *   Held carts   park a cart ("customer stepped away"), recall it later on any device. Needs migration 0014 (sale_holds).
 *   Customers    find a customer to attach to a sale by name, email or phone; or by pass (scan/paste the pass link,
 *                or the holder's name): the pass holder is matched to a customer by email, and an account is created
 *                if there isn't one. Quick-add a new customer (name + email). Needs the customers table (0006).
 * The cart itself is the register's own JSON; prices are always worked out again on the server when the sale is saved.
 */

if (!function_exists('bcrf_hold_save')) {

require_once __DIR__ . '/sales.php';

class BcrfRegisterError extends RuntimeException {}

function bcrf_sale_holds_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try { $ready = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sale_holds'")->fetchColumn() === 1; }
    catch (Throwable $e) { $ready = false; }
    return $ready;
}

// -- Held carts --

/** Park a cart. $state = the register's cart JSON (lines, discPct, exempt, customer). Returns the hold id. */
function bcrf_hold_save(PDO $pdo, ?int $registerId, string $label, array $state, int $totalCents): int {
    if (!bcrf_sale_holds_ready($pdo)) throw new BcrfRegisterError('Holding carts needs migration 0014 (Settings › Database).');
    $lines = array_values(array_filter((array)($state['cart'] ?? []), 'is_array'));
    if (!$lines) throw new BcrfRegisterError('The cart is empty.');
    if (count($lines) > 200) throw new BcrfRegisterError('That cart is too big to hold.');
    $label = mb_substr(trim(preg_replace('/\s+/', ' ', $label)), 0, 80);
    if ($label === '') $label = 'Held ' . bcrf_local_hold_time(gmdate('Y-m-d H:i:s'));
    $keep = ['cart' => $lines, 'discPct' => (float)($state['discPct'] ?? 0), 'exempt' => !empty($state['exempt']),
             'customer' => isset($state['customer']['id']) ? ['id' => (int)$state['customer']['id'], 'name' => mb_substr((string)($state['customer']['name'] ?? ''), 0, 120)] : null];
    $json = json_encode($keep, JSON_UNESCAPED_UNICODE);
    if (strlen($json) > 200000) throw new BcrfRegisterError('That cart is too big to hold.');
    $pdo->prepare("INSERT INTO sale_holds (register_id, label, customer_id, cart_json, line_count, total_cents, status, created_at, created_by)
                   VALUES (?, ?, ?, ?, ?, ?, 'open', UTC_TIMESTAMP(), ?)")
        ->execute([$registerId ?: null, $label, $keep['customer']['id'] ?? null, $json, count($lines), max(0, $totalCents), (int)($_SESSION['user_id'] ?? 0) ?: null]);
    return (int)$pdo->lastInsertId();
}

function bcrf_local_hold_time(string $utc): string {
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('g:i A');
}

/** Open holds, newest first. */
function bcrf_holds_list(PDO $pdo): array {
    if (!bcrf_sale_holds_ready($pdo)) return [];
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $out = [];
    foreach ($pdo->query("SELECT h.id, h.label, h.line_count, h.total_cents, h.created_at, h.created_by, r.name AS register
                          FROM sale_holds h LEFT JOIN registers r ON r.id = h.register_id
                          WHERE h.status = 'open' ORDER BY h.created_at DESC LIMIT 50") as $h) {
        $local = (new DateTimeImmutable($h['created_at'], new DateTimeZone('UTC')))->setTimezone($tz);
        $out[] = ['id' => (int)$h['id'], 'label' => $h['label'], 'lines' => (int)$h['line_count'], 'total_cents' => (int)$h['total_cents'],
                  'when' => ($local->format('Y-m-d') === $today ? '' : $local->format('M j ')) . $local->format('g:i A'),
                  'register' => $h['register'], 'by' => function_exists('bcrf_staff_name') ? bcrf_staff_name($pdo, $h['created_by']) : ''];
    }
    return $out;
}

/** Take a held cart back. Only one device can recall it. Returns the cart JSON. */
function bcrf_hold_recall(PDO $pdo, int $id): array {
    if (!bcrf_sale_holds_ready($pdo)) throw new BcrfRegisterError('Holding carts needs migration 0014.');
    $up = $pdo->prepare("UPDATE sale_holds SET status = 'recalled', closed_at = UTC_TIMESTAMP(), closed_by = ? WHERE id = ? AND status = 'open'");
    $up->execute([(int)($_SESSION['user_id'] ?? 0) ?: null, $id]);
    if ($up->rowCount() !== 1) throw new BcrfRegisterError('That cart was already taken back or removed (maybe on another device).');
    $st = $pdo->prepare("SELECT cart_json FROM sale_holds WHERE id = ?");
    $st->execute([$id]);
    return json_decode((string)$st->fetchColumn(), true) ?: [];
}

/** Throw a held cart away. */
function bcrf_hold_discard(PDO $pdo, int $id): void {
    if (!bcrf_sale_holds_ready($pdo)) throw new BcrfRegisterError('Holding carts needs migration 0014.');
    $up = $pdo->prepare("UPDATE sale_holds SET status = 'discarded', closed_at = UTC_TIMESTAMP(), closed_by = ? WHERE id = ? AND status = 'open'");
    $up->execute([(int)($_SESSION['user_id'] ?? 0) ?: null, $id]);
    if ($up->rowCount() !== 1) throw new BcrfRegisterError('That cart was already taken back or removed.');
}

// -- Customers --

function bcrf_pos_customer_row(array $c, ?array $pass = null): array {
    return ['type' => 'customer', 'id' => (int)$c['id'], 'name' => trim($c['first_name'] . ' ' . $c['last_name']),
            'email' => (string)$c['email'], 'phone' => (string)($c['phone_number'] ?? ''),
            'pass' => $pass ? ['id' => (int)$pass['id'], 'label' => (string)($pass['label'] ?? 'Annual pass'), 'valid_until' => $pass['valid_until'], 'active' => bcrf_pos_pass_active($pass)] : null];
}

function bcrf_pos_pass_active(array $p): bool {
    return ($p['status'] ?? '') === 'active' && ($p['payment_status'] ?? 'paid') === 'paid' && (string)$p['valid_until'] >= date('Y-m-d');
}

/** Search customers (name, email, phone) and pass holders (name, email, or a scanned/pasted pass link or token). Max 12 results. */
function bcrf_pos_customer_search(PDO $pdo, string $q): array {
    if (!bcrf_customers_ready($pdo)) return [];
    $q = trim($q);
    if (mb_strlen($q) < 2) return [];
    $out = []; $seen = [];
    // A pass QR holds .../passes/verify.php?token=...; a token alone is 32+ hex characters.
    $token = null;
    if (preg_match('/[?&]token=([A-Za-z0-9]{16,})/', $q, $m)) $token = $m[1];
    elseif (preg_match('/^[A-Fa-f0-9]{32,}$/', $q)) $token = $q;
    $passesReady = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'passes'")->fetchColumn() === 1;
    $passes = [];
    if ($passesReady) {
        if ($token) {
            $st = $pdo->prepare("SELECT p.*, t.label FROM passes p LEFT JOIN pass_types t ON t.id = p.pass_type_id WHERE p.token = ? LIMIT 1");
            $st->execute([$token]);
        } else {
            $like = '%' . $q . '%';
            $st = $pdo->prepare("SELECT p.*, t.label FROM passes p LEFT JOIN pass_types t ON t.id = p.pass_type_id
                                 WHERE p.holder_name LIKE ? OR p.email LIKE ? ORDER BY p.valid_until DESC LIMIT 8");
            $st->execute([$like, $like]);
        }
        $passes = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $byEmail = [];
    foreach ($passes as $p) $byEmail[strtolower((string)$p['email'])] ??= $p;
    if (!$token) {
        $digits = preg_replace('/\D/', '', $q);
        $like = '%' . $q . '%';
        $st = $pdo->prepare("SELECT id, first_name, last_name, email, phone_number FROM customers
                             WHERE account_status = 'active' AND (CONCAT(first_name, ' ', last_name) LIKE ? OR email LIKE ?
                                   OR (? <> '' AND REGEXP_REPLACE(COALESCE(phone_number, ''), '[^0-9]', '') LIKE ?))
                             ORDER BY last_name, first_name LIMIT 10");
        $st->execute([$like, $like, strlen($digits) >= 4 ? $digits : '', '%' . $digits . '%']);
        foreach ($st as $c) {
            $out[] = bcrf_pos_customer_row($c, $byEmail[strtolower($c['email'])] ?? null);
            $seen[strtolower($c['email'])] = true;
        }
    }
    // Pass holders whose email isn't already listed: find their account by email, or offer to create one
    foreach ($passes as $p) {
        $em = strtolower((string)$p['email']);
        if (isset($seen[$em])) continue;
        $seen[$em] = true;
        $st = $pdo->prepare("SELECT id, first_name, last_name, email, phone_number FROM customers WHERE email = ? AND account_status = 'active'");
        $st->execute([$em]);
        if ($c = $st->fetch(PDO::FETCH_ASSOC)) { $out[] = bcrf_pos_customer_row($c, $p); continue; }
        $out[] = ['type' => 'pass', 'id' => null, 'pass_id' => (int)$p['id'], 'name' => (string)$p['holder_name'], 'email' => $em, 'phone' => (string)($p['phone'] ?? ''),
                  'pass' => ['id' => (int)$p['id'], 'label' => (string)($p['label'] ?? 'Annual pass'), 'valid_until' => $p['valid_until'], 'active' => bcrf_pos_pass_active($p)]];
    }
    return array_slice($out, 0, 12);
}

/** Create a customer (or return the existing one with that email). Returns the customer row for the register. */
function bcrf_pos_customer_create(PDO $pdo, string $first, string $last, string $email, string $phone = ''): array {
    if (!bcrf_customers_ready($pdo)) throw new BcrfRegisterError('Customer accounts are not set up yet.');
    $first = mb_substr(trim(strip_tags($first)), 0, 50);
    $last  = mb_substr(trim(strip_tags($last)), 0, 50);
    $email = strtolower(trim($email));
    $phone = mb_substr(preg_replace('/[^0-9+\-() ]/', '', $phone), 0, 20);
    if ($first === '') throw new BcrfRegisterError('Enter at least a first name.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new BcrfRegisterError('Enter a valid email (it\'s how we find them again).');
    $st = $pdo->prepare("SELECT id, first_name, last_name, email, phone_number, account_status FROM customers WHERE email = ?");
    $st->execute([$email]);
    if ($c = $st->fetch(PDO::FETCH_ASSOC)) {
        if ($c['account_status'] !== 'active') throw new BcrfRegisterError('That email belongs to a disabled account.');
        return bcrf_pos_customer_row($c) + ['existing' => true];
    }
    // No password: they can claim the account later with "Forgot password" on the public site.
    $pdo->prepare("INSERT INTO customers (first_name, last_name, email, phone_number, password, account_status, created_at) VALUES (?, ?, ?, ?, NULL, 'active', NOW())")
        ->execute([$first, $last, $email, $phone ?: null]);
    $id = (int)$pdo->lastInsertId();
    if (function_exists('erp_audit')) erp_audit($pdo, 'create', 'customers', $id, trim("$first $last") . ' (added at the register)');
    return bcrf_pos_customer_row(['id' => $id, 'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone_number' => $phone]) + ['existing' => false];
}

/** A pass holder with no account yet: make one from the pass (name split at the last space). */
function bcrf_pos_customer_from_pass(PDO $pdo, int $passId): array {
    $st = $pdo->prepare("SELECT p.*, t.label FROM passes p LEFT JOIN pass_types t ON t.id = p.pass_type_id WHERE p.id = ?");
    $st->execute([$passId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new BcrfRegisterError('That pass no longer exists.');
    $name = trim((string)$p['holder_name']);
    $cut = mb_strrpos($name, ' ');
    $first = $cut === false ? $name : mb_substr($name, 0, $cut);
    $last  = $cut === false ? '' : mb_substr($name, $cut + 1);
    $c = bcrf_pos_customer_create($pdo, $first ?: 'Pass', $last, (string)$p['email'], (string)($p['phone'] ?? ''));
    $c['pass'] = ['id' => (int)$p['id'], 'label' => (string)($p['label'] ?? 'Annual pass'), 'valid_until' => $p['valid_until'], 'active' => bcrf_pos_pass_active($p)];
    return $c;
}

}
