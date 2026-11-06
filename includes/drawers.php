<?php // includes/drawers.php
/*
 * Farm OS Phase 1, slice 2 (Nov 6, 2026): cash drawers. Tables: admin/migrations/0010_drawers.php.
 *
 *   bcrf_drawer_open($pdo, $registerId, $counts)              count the starting float
 *   bcrf_drawer_move($pdo, $sessionId, $type, $cents, $reason) paid_in | paid_out | drop
 *   bcrf_drawer_close($pdo, $sessionId, $counts, $note)        count by denomination; variance checked
 *   bcrf_drawer_summary($pdo, $sessionId)                      everything the drawer report shows
 *
 * Expected cash = float + cash taken on sales − cash refunds (includes/refunds.php, slice 3) + paid in
 * − paid out − drops. Variance = counted − expected. A variance bigger than the threshold (Settings
 * drawer_variance_threshold_cents, default $5) needs a note and an Owner or Manager (05 › Cash drawer).
 * Closing locks the session row, and so does every sale (includes/sales.php), so no sale can land in a
 * drawer after its expected cash was worked out.
 */

if (!function_exists('bcrf_drawer_open')) {

if (!class_exists('BcrfSaleError')) { class BcrfSaleError extends RuntimeException {} }

/** True once migration 0010 has run (cached per request). */
function bcrf_drawers_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
                               AND table_name IN ('registers','drawer_sessions','cash_movements')")->fetchColumn();
        return $ready = ($n === 3);
    } catch (Throwable $e) { return $ready = false; }
}

/** Bills and coins counted at open and close, in cents, largest first. */
function bcrf_denominations(): array {
    return [10000 => '$100', 5000 => '$50', 2000 => '$20', 1000 => '$10', 500 => '$5', 200 => '$2', 100 => '$1',
            25 => '25¢', 10 => '10¢', 5 => '5¢', 1 => '1¢'];
}

/** Clean a denomination count from the browser: [cents => qty]. Returns [counts, total cents]. */
function bcrf_drawer_count(array $raw): array {
    $out = []; $total = 0;
    foreach (bcrf_denominations() as $d => $label) {
        $q = (int)($raw[$d] ?? $raw[(string)$d] ?? 0);
        if ($q < 0 || $q > 100000) throw new BcrfSaleError('Each count must be between 0 and 100,000.');
        if ($q) { $out[$d] = $q; $total += $d * $q; }
    }
    return [$out, $total];
}

function bcrf_movement_types(): array { return ['paid_in' => 'Paid in', 'paid_out' => 'Paid out', 'drop' => 'Cash drop']; }

/** Can this user approve a variance over the threshold? Owner (administrator) or Manager (moderator), C-14. */
function bcrf_can_approve_variance(): bool {
    return in_array((string)($_SESSION['user_role'] ?? ''), ['administrator', 'moderator'], true);
}

function bcrf_variance_threshold(PDO $pdo): int {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'drawer_variance_threshold_cents'");
        $st->execute();
        $v = $st->fetchColumn();
        return $v === false || $v === '' ? 500 : max(0, (int)$v);
    } catch (Throwable $e) { return 500; }
}

/** Active registers, id => name. */
function bcrf_registers(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query("SELECT id, name FROM registers WHERE archived_at IS NULL ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['id']] = $r['name'];
    }
    return $out;
}

/** The open session on a register, or null. $lock = SELECT … FOR UPDATE (inside a transaction). */
function bcrf_drawer_current(PDO $pdo, int $registerId, bool $lock = false): ?array {
    $st = $pdo->prepare("SELECT * FROM drawer_sessions WHERE register_id = ? AND status = 'open' LIMIT 1" . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$registerId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Display name of a staff member ("Zac M."), cached. */
function bcrf_staff_name(PDO $pdo, $uid): string {
    static $cache = [];
    $uid = (int)$uid;
    if (!$uid) return '';
    if (!isset($cache[$uid])) {
        $st = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        $cache[$uid] = $u ? trim($u['first_name'] . ' ' . mb_substr((string)$u['last_name'], 0, 1) . '.') : '#' . $uid;
    }
    return $cache[$uid];
}

/** UTC 'Y-m-d H:i:s' → farm-local display. */
function bcrf_local_time(?string $utc, string $fmt = 'g:i A'): string {
    if (!$utc) return '';
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format($fmt);
}

/** Expected cash right now for a session (cents). */
function bcrf_drawer_expected(PDO $pdo, array $session): int {
    $sid = (int)$session['id'];
    $st = $pdo->prepare("SELECT COALESCE(SUM(p.amount_cents), 0) FROM payments p JOIN sales s ON s.id = p.sale_id
                         WHERE s.drawer_session_id = ? AND s.status <> 'voided' AND p.tender = 'cash' AND p.status = 'completed'");
    $st->execute([$sid]);
    $cash = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT type, COALESCE(SUM(amount_cents), 0) FROM cash_movements WHERE drawer_session_id = ? GROUP BY type");
    $st->execute([$sid]);
    $mv = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    return (int)$session['opening_float_cents'] + $cash - bcrf_drawer_cash_refunds($pdo, $sid)
         + (int)($mv['paid_in'] ?? 0) - (int)($mv['paid_out'] ?? 0) - (int)($mv['drop'] ?? 0);
}

/** Cash paid out of this drawer for refunds (0 until migration 0011). */
function bcrf_drawer_cash_refunds(PDO $pdo, int $sid): int {
    if (!function_exists('bcrf_refunds_ready') || !bcrf_refunds_ready($pdo)) return 0;
    $st = $pdo->prepare("SELECT COALESCE(SUM(rp.amount_cents), 0) FROM refund_payments rp JOIN refunds r ON r.id = rp.refund_id
                         WHERE r.drawer_session_id = ? AND rp.tender = 'cash'");
    $st->execute([$sid]);
    return (int)$st->fetchColumn();
}

/** Everything the drawer screen and report need. */
function bcrf_drawer_summary(PDO $pdo, int $sessionId): ?array {
    $st = $pdo->prepare("SELECT d.*, r.name AS register_name FROM drawer_sessions d JOIN registers r ON r.id = d.register_id WHERE d.id = ?");
    $st->execute([$sessionId]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) return null;

    $st = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(subtotal_cents),0) sub, COALESCE(SUM(discount_cents),0) disc,
                                COALESCE(SUM(tax_cents),0) tax, COALESCE(SUM(total_cents),0) tot
                         FROM sales WHERE drawer_session_id = ? AND status <> 'voided'");
    $st->execute([$sessionId]);
    $sales = array_map('intval', $st->fetch(PDO::FETCH_ASSOC));

    $st = $pdo->prepare("SELECT p.tender, COUNT(*) n, SUM(p.amount_cents) amt FROM payments p JOIN sales s ON s.id = p.sale_id
                         WHERE s.drawer_session_id = ? AND s.status <> 'voided' AND p.status = 'completed' GROUP BY p.tender");
    $st->execute([$sessionId]);
    $byTender = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $byTender[$r['tender']] = ['count' => (int)$r['n'], 'cents' => (int)$r['amt']];

    $st = $pdo->prepare("SELECT id, type, amount_cents, reason, created_at, created_by FROM cash_movements WHERE drawer_session_id = ? ORDER BY id");
    $st->execute([$sessionId]);
    $moves = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $moves[] = ['type' => $m['type'], 'amount_cents' => (int)$m['amount_cents'], 'reason' => $m['reason'],
                    'time' => bcrf_local_time($m['created_at']), 'by' => bcrf_staff_name($pdo, $m['created_by'])];
    }

    $st = $pdo->prepare("SELECT s.sale_number, p.tender, p.amount_cents, p.payer_handle, p.reference, p.created_at
                         FROM payments p JOIN sales s ON s.id = p.sale_id
                         WHERE s.drawer_session_id = ? AND s.status <> 'voided' AND p.tender IN ('paypal','venmo','cashapp')
                           AND p.reconciled_at IS NULL ORDER BY p.id");
    $st->execute([$sessionId]);
    $p2p = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $p2p[] = ['sale_number' => $p['sale_number'], 'tender' => $p['tender'], 'amount_cents' => (int)$p['amount_cents'],
                  'payer' => (string)$p['payer_handle'], 'reference' => (string)$p['reference'], 'time' => bcrf_local_time($p['created_at'])];
    }

    // Refunds paid from this drawer, and sales rung on it that were voided (slice 3)
    $refunds = []; $voids = [];
    if (function_exists('bcrf_refunds_ready') && bcrf_refunds_ready($pdo)) {
        $st = $pdo->prepare("SELECT r.id, r.refund_number, r.total_cents, r.reason, r.created_at, r.created_by, s.sale_number,
                                    (SELECT GROUP_CONCAT(CONCAT(rp.tender, ':', rp.amount_cents)) FROM refund_payments rp WHERE rp.refund_id = r.id) AS pays
                             FROM refunds r JOIN sales s ON s.id = r.sale_id WHERE r.drawer_session_id = ? ORDER BY r.id");
        $st->execute([$sessionId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pays = [];
            foreach (array_filter(explode(',', (string)$r['pays'])) as $pp) { [$t, $c] = explode(':', $pp); $pays[$t] = ($pays[$t] ?? 0) + (int)$c; }
            $refunds[] = ['refund_number' => $r['refund_number'], 'sale_number' => $r['sale_number'], 'total_cents' => (int)$r['total_cents'],
                          'reason' => $r['reason'], 'time' => bcrf_local_time($r['created_at']), 'by' => bcrf_staff_name($pdo, $r['created_by']), 'payments' => $pays];
        }
        $st = $pdo->prepare("SELECT sale_number, total_cents, void_reason, voided_at, voided_by FROM sales WHERE drawer_session_id = ? AND status = 'voided' ORDER BY id");
        $st->execute([$sessionId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $voids[] = ['sale_number' => $v['sale_number'], 'total_cents' => (int)$v['total_cents'], 'reason' => (string)$v['void_reason'],
                        'time' => bcrf_local_time($v['voided_at']), 'by' => bcrf_staff_name($pdo, $v['voided_by'])];
        }
    }

    $open = $d['status'] === 'open';
    $expected = $open ? bcrf_drawer_expected($pdo, $d) : (int)$d['expected_cash_cents'];
    return [
        'id' => (int)$d['id'], 'register_id' => (int)$d['register_id'], 'register' => $d['register_name'], 'status' => $d['status'],
        'business_date' => $d['business_date'],
        'opened' => bcrf_local_time($d['opened_at'], 'D M j, g:i A'), 'opened_by' => bcrf_staff_name($pdo, $d['opened_by']),
        'closed' => bcrf_local_time($d['closed_at'], 'D M j, g:i A'), 'closed_by' => bcrf_staff_name($pdo, $d['closed_by']),
        'float_cents' => (int)$d['opening_float_cents'],
        'cash_sales_cents' => $byTender['cash']['cents'] ?? 0,
        'paid_in_cents'  => array_sum(array_map(fn($m) => $m['type'] === 'paid_in'  ? $m['amount_cents'] : 0, $moves)),
        'paid_out_cents' => array_sum(array_map(fn($m) => $m['type'] === 'paid_out' ? $m['amount_cents'] : 0, $moves)),
        'drops_cents'    => array_sum(array_map(fn($m) => $m['type'] === 'drop'     ? $m['amount_cents'] : 0, $moves)),
        'refunds_cents' => bcrf_drawer_cash_refunds($pdo, $sessionId),   // cash only (what left the drawer)
        'refunds_total_cents' => array_sum(array_column($refunds, 'total_cents')),
        'refunds' => $refunds, 'voids' => $voids, 'voids_total_cents' => array_sum(array_column($voids, 'total_cents')),
        'expected_cents' => $expected,
        'counted_cents'  => $open ? null : (int)$d['counted_cash_cents'],
        'variance_cents' => $open ? null : (int)$d['variance_cents'],
        'variance_approved_by' => bcrf_staff_name($pdo, $d['variance_approved_by']),
        'close_note' => (string)$d['close_note'],
        'closing_count' => json_decode((string)$d['closing_count_json'], true) ?: [],
        'sales' => $sales, 'by_tender' => $byTender, 'movements' => $moves, 'p2p_unreconciled' => $p2p,
        'threshold_cents' => bcrf_variance_threshold($pdo),
    ];
}

function bcrf_drawer_audit(PDO $pdo, string $action, int $id, string $label, ?array $before = null, ?array $after = null): void {
    if (!function_exists('bcrf_audit_event')) return;
    [$type, $uid, $name, $ip] = bcrf_audit_actor();
    bcrf_audit_event($pdo, ['actor_type' => $type, 'actor_id' => $uid, 'actor_label' => $name, 'ip' => $ip, 'action' => $action,
        'object_type' => 'drawer_sessions', 'object_id' => $id, 'object_label' => $label, 'before' => $before, 'after' => $after]);
}

/** Open a drawer on a register with a counted float. Returns the session id. */
function bcrf_drawer_open(PDO $pdo, int $registerId, array $rawCounts): int {
    $regs = bcrf_registers($pdo);
    if (!isset($regs[$registerId])) throw new BcrfSaleError('Choose a register first.');
    [$counts, $float] = bcrf_drawer_count($rawCounts);
    $uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    $bdate = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    try {
        $pdo->prepare("INSERT INTO drawer_sessions (register_id, status, business_date, opened_at, opened_by, opening_float_cents, opening_count_json)
                       VALUES (?, 'open', ?, UTC_TIMESTAMP(3), ?, ?, ?)")
            ->execute([$registerId, $bdate, $uid, $float, json_encode((object)$counts)]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') throw new BcrfSaleError('The ' . $regs[$registerId] . ' drawer is already open.');
        throw $e;
    }
    $id = (int)$pdo->lastInsertId();
    if (function_exists('bcrf_event')) bcrf_event('drawer.opened', 'drawer_sessions', $id, ['register' => $regs[$registerId], 'float_cents' => $float]);
    bcrf_drawer_audit($pdo, 'open', $id, $regs[$registerId] . ' drawer opened with $' . number_format($float / 100, 2), null, ['float_cents' => $float, 'count' => $counts]);
    return $id;
}

/** Record cash paid in, paid out, or dropped (taken to the safe). */
function bcrf_drawer_move(PDO $pdo, int $sessionId, string $type, int $cents, string $reason): void {
    $types = bcrf_movement_types();
    if (!isset($types[$type])) throw new BcrfSaleError('Unknown cash movement.');
    if ($cents <= 0 || $cents > 10000000) throw new BcrfSaleError('Enter an amount above zero.');
    $reason = mb_substr(trim($reason), 0, 255);
    if ($reason === '') throw new BcrfSaleError('Say what the cash was for.');
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM drawer_sessions WHERE id = ? FOR UPDATE");
        $st->execute([$sessionId]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d || $d['status'] !== 'open') throw new BcrfSaleError('That drawer is closed.');
        if ($type !== 'paid_in' && $cents > bcrf_drawer_expected($pdo, $d)) {
            throw new BcrfSaleError('That is more cash than the drawer should hold (' . '$' . number_format(bcrf_drawer_expected($pdo, $d) / 100, 2) . ').');
        }
        $pdo->prepare("INSERT INTO cash_movements (drawer_session_id, type, amount_cents, reason, created_at, created_by) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(3), ?)")
            ->execute([$sessionId, $type, $cents, $reason, (int)($_SESSION['user_id'] ?? 0) ?: null]);
        if (function_exists('bcrf_event')) bcrf_event('drawer.cash_moved', 'drawer_sessions', $sessionId, ['type' => $type, 'amount_cents' => $cents]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    bcrf_drawer_audit($pdo, $type, $sessionId, $types[$type] . ' $' . number_format($cents / 100, 2) . ': ' . $reason);
}

/**
 * Close a drawer. Returns the summary. A variance over the threshold needs a note, and an Owner or
 * Manager; anyone else gets a message to fetch one (nothing is saved).
 */
function bcrf_drawer_close(PDO $pdo, int $sessionId, array $rawCounts, string $note): array {
    [$counts, $counted] = bcrf_drawer_count($rawCounts);
    $note = mb_substr(trim($note), 0, 500);
    $uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
    $threshold = bcrf_variance_threshold($pdo);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM drawer_sessions WHERE id = ? FOR UPDATE");
        $st->execute([$sessionId]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if (!$d || $d['status'] !== 'open') throw new BcrfSaleError('That drawer is already closed.');
        $expected = bcrf_drawer_expected($pdo, $d);
        $variance = $counted - $expected;
        $over = abs($variance) > $threshold;
        if ($over && $note === '') {
            throw new BcrfSaleError(sprintf('The count is %s by $%s. Add a note saying why.', $variance < 0 ? 'short' : 'over', number_format(abs($variance) / 100, 2)));
        }
        if ($over && !bcrf_can_approve_variance()) {
            throw new BcrfSaleError(sprintf('The count is %s by $%s, more than $%s. An Owner or Manager has to close this drawer.',
                $variance < 0 ? 'short' : 'over', number_format(abs($variance) / 100, 2), number_format($threshold / 100, 2)));
        }
        $pdo->prepare("UPDATE drawer_sessions SET status = 'closed', closed_at = UTC_TIMESTAMP(3), closed_by = ?, counted_cash_cents = ?,
                              closing_count_json = ?, expected_cash_cents = ?, variance_cents = ?, variance_approved_by = ?, close_note = ?
                       WHERE id = ?")
            ->execute([$uid, $counted, json_encode((object)$counts), $expected, $variance, $over ? $uid : null, $note ?: null, $sessionId]);
        if (function_exists('bcrf_event')) bcrf_event('drawer.closed', 'drawer_sessions', $sessionId,
            ['expected_cents' => $expected, 'counted_cents' => $counted, 'variance_cents' => $variance]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    bcrf_drawer_audit($pdo, 'close', $sessionId, sprintf('Drawer closed: counted $%s, expected $%s, %s $%s', number_format($counted / 100, 2),
        number_format($expected / 100, 2), $variance < 0 ? 'short' : ($variance > 0 ? 'over' : 'even'), number_format(abs($variance) / 100, 2)),
        null, ['counted_cents' => $counted, 'expected_cents' => $expected, 'variance_cents' => $variance, 'count' => $counts, 'note' => $note]);
    return bcrf_drawer_summary($pdo, $sessionId);
}

}
