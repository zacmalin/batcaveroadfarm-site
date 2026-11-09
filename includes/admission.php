<?php // includes/admission.php
/*
 * Farm OS Phase 1, slice 4a (Nov 6, 2026): admission, check-ins and capacity (07). Tables: admin/migrations/0012_admission.php.
 *
 *   bcrf_occupancy($pdo)                         guests on site now, the limit, level (ok / busy at 80% / full), estimated wait
 *   bcrf_gate_search($pdo, $q)                   a scanned QR (ticket code or pass link), or a name / email / phone
 *   bcrf_ticket_redeem($pdo, $id, $n, $reason)   admit n guests on a ticket (partial redemption allowed)
 *   bcrf_pass_admit($pdo, $id, $n, $reason)      admit a passholder party (also writes the old pass visit log)
 *   bcrf_check_out($pdo, $id)                    one-tap check-out
 *   bcrf_admission_for_sale(...)                 called by includes/sales.php: admission sold at the gate issues a
 *                                                used ticket and checks the party in, inside the sale's transaction
 *   bcrf_tickets_sync_bookings($pdo)             copies online day-ticket orders (still written to bookings until
 *                                                slice 4b) into tickets; runs from the gate screen and migration 0012
 *
 * Capacity (07 › Capacity): guests on site at the same time, not a daily total (Q-01). Each check-in counts from
 * check-in until checked out, or until check-in + visit_length_minutes. At the limit new check-ins are refused;
 * an Owner or Manager can admit anyway with a reason (gate.capacity.override), which is stored and audited.
 * Check-ins take a database lock (GET_LOCK) so two devices can't both take the last space.
 */

if (!function_exists('bcrf_occupancy')) {

if (!class_exists('BcrfSaleError')) { class BcrfSaleError extends RuntimeException {} }

/** Refused because the farm is full; carries the occupancy so the screen can offer an override. */
class BcrfCapacityError extends BcrfSaleError {
    public array $occ;
    public function __construct(string $msg, array $occ) { parent::__construct($msg); $this->occ = $occ; }
}

function bcrf_admission_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
                               AND table_name IN ('ticket_types','tickets','check_ins')")->fetchColumn();
        return $ready = ($n === 3);
    } catch (Throwable $e) { return $ready = false; }
}

function bcrf_adm_setting(PDO $pdo, string $key, int $default): int {
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        try {
            $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
            $st->execute([$key]);
            $v = $st->fetchColumn();
            $cache[$key] = ($v === false || trim((string)$v) === '') ? $default : (int)$v;
        } catch (Throwable $e) { $cache[$key] = $default; }
    }
    return $cache[$key];
}

function bcrf_can_override_capacity(): bool { return in_array((string)($_SESSION['user_role'] ?? ''), ['administrator', 'moderator'], true); }

// ── Ticket codes ────────────────────────────────────────────────────────────
function bcrf_ticket_code(): string {
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O, 1/I
    $s = '';
    for ($i = 0; $i < 10; $i++) $s .= $abc[random_int(0, strlen($abc) - 1)];
    return $s;
}
function bcrf_ticket_code_fmt(string $c): string { return substr($c, 0, 4) . '-' . substr($c, 4, 4) . '-' . substr($c, 8, 2); }
function bcrf_ticket_code_norm(string $s): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s)); }

function bcrf_utc_now(): string { return gmdate('Y-m-d H:i:s'); }
function bcrf_business_date(): string {
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    return (new DateTimeImmutable('now', $tz))->format('Y-m-d');
}
function bcrf_adm_local(?string $utc, string $fmt = 'g:i A'): string {
    if (!$utc) return '';
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format($fmt);
}

// ── Occupancy and capacity ──────────────────────────────────────────────────
/** Open check-ins (on site now), oldest first. */
function bcrf_on_site(PDO $pdo): array {
    return $pdo->query("SELECT c.*, t.purchaser_name, t.public_code, p.holder_name
                        FROM check_ins c
                        LEFT JOIN tickets t ON t.id = c.ticket_id
                        LEFT JOIN passes p ON p.id = c.pass_id
                        WHERE c.checked_out_at IS NULL AND c.expected_out_at > UTC_TIMESTAMP()
                        ORDER BY c.checked_in_at")->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_occupancy(PDO $pdo): array {
    $limit = max(1, bcrf_adm_setting($pdo, 'capacity_limit', 20));
    $rows = bcrf_on_site($pdo);
    $on = array_sum(array_map(fn($r) => (int)$r['guests_counted'], $rows));
    $pct = (int)floor($on * 100 / $limit);
    $outs = array_map(fn($r) => [$r['expected_out_at'], (int)$r['guests_counted']], $rows);
    usort($outs, fn($a, $b) => strcmp($a[0], $b[0]));
    $st = $pdo->prepare("SELECT COALESCE(SUM(party_size), 0), COUNT(*) FROM check_ins WHERE business_date = ?");
    $st->execute([bcrf_business_date()]);
    [$today, $parties] = array_map('intval', $st->fetch(PDO::FETCH_NUM));
    return ['on_site' => $on, 'limit' => $limit, 'free' => max(0, $limit - $on), 'pct' => $pct,
            'level' => $on >= $limit ? 'full' : ($pct >= 80 ? 'busy' : 'ok'), 'outs' => $outs,
            'visitors_today' => $today, 'parties_today' => $parties];
}

/** Minutes until $need spaces are free (0 if they are now). */
function bcrf_wait_minutes(array $occ, int $need): int {
    $free = $occ['limit'] - $occ['on_site'];
    if ($need <= $free) return 0;
    foreach ($occ['outs'] as [$at, $g]) {
        $free += $g;
        if ($free >= $need) return max(1, (int)ceil((strtotime($at . ' UTC') - time()) / 60));
    }
    return -1;   // more than the limit: never fits without an override
}

/** One device at a time adds guests (released at the end of the request or by bcrf_capacity_unlock). */
function bcrf_capacity_lock(PDO $pdo): void {
    if ((int)$pdo->query("SELECT GET_LOCK('bcrf_capacity', 10)")->fetchColumn() !== 1) {
        throw new BcrfSaleError('The gate is busy for a moment. Try again.');
    }
}
function bcrf_capacity_unlock(PDO $pdo): void { try { $pdo->query("SELECT RELEASE_LOCK('bcrf_capacity')"); } catch (Throwable $e) {} }

/**
 * Would $guests fit? Returns [override_reason|null, override_by|null]. Throws BcrfCapacityError when full and
 * there is no override, or when the override isn't allowed for this user. Call with the capacity lock held.
 */
function bcrf_capacity_check(PDO $pdo, int $guests, ?string $override): array {
    if ($guests <= 0) return [null, null];
    $occ = bcrf_occupancy($pdo);
    if ($occ['on_site'] + $guests <= $occ['limit']) return [null, null];
    $override = trim((string)$override);
    $wait = bcrf_wait_minutes($occ, $guests);
    $msg = sprintf('The farm is full: %d of %d on site, and this party is %d.', $occ['on_site'], $occ['limit'], $guests)
         . ($wait > 0 ? sprintf(' About %d minute%s until there is room.', $wait, $wait === 1 ? '' : 's') : '');
    if ($override === '') throw new BcrfCapacityError($msg, $occ + ['wait_minutes' => $wait, 'party' => $guests]);
    if (!bcrf_can_override_capacity()) throw new BcrfCapacityError($msg . ' Only an Owner or Manager can let them in anyway.', $occ + ['wait_minutes' => $wait, 'party' => $guests]);
    return [mb_substr($override, 0, 255), (int)($_SESSION['user_id'] ?? 0) ?: null];
}

/** Insert one check-in. $c: source_type, party_size, guests_counted, ticket_id?, pass_id?, sale_id?, pass_visit_id?, override?, override_by?, note? */
function bcrf_check_in_row(PDO $pdo, array $c): int {
    $mins = max(15, bcrf_adm_setting($pdo, 'visit_length_minutes', 90));
    $pdo->prepare("INSERT INTO check_ins (source_type, ticket_id, pass_id, sale_id, pass_visit_id, party_size, guests_counted, checked_in_at,
                          expected_out_at, business_date, staff_user_id, capacity_override_reason, override_by, note)
                   VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ? MINUTE, ?, ?, ?, ?, ?)")
        ->execute([$c['source_type'], $c['ticket_id'] ?? null, $c['pass_id'] ?? null, $c['sale_id'] ?? null, $c['pass_visit_id'] ?? null,
                   (int)$c['party_size'], (int)$c['guests_counted'], $mins, bcrf_business_date(), (int)($_SESSION['user_id'] ?? 0) ?: null,
                   $c['override'] ?? null, $c['override_by'] ?? null, $c['note'] ?? null]);
    $id = (int)$pdo->lastInsertId();
    if (function_exists('bcrf_event')) bcrf_event('checkin.created', 'check_ins', $id, ['source' => $c['source_type'], 'party' => (int)$c['party_size'],
        'override' => !empty($c['override'])]);
    return $id;
}

function bcrf_adm_audit(PDO $pdo, string $action, string $type, $id, string $label, ?array $after = null): void {
    if (!function_exists('bcrf_audit_event')) return;
    [$t, $u, $name, $ip] = bcrf_audit_actor();
    bcrf_audit_event($pdo, ['actor_type' => $t, 'actor_id' => $u, 'actor_label' => $name, 'ip' => $ip, 'action' => $action,
        'object_type' => $type, 'object_id' => $id, 'object_label' => $label, 'after' => $after]);
}

// ── Online orders still written to bookings: copy into tickets (C-04 bridge until slice 4b) ──────
function bcrf_tickets_sync_bookings(PDO $pdo, int $limit = 200): int {
    $months = max(1, bcrf_adm_setting($pdo, 'ticket_expiry_months', 12));
    $offset = (int)$pdo->query("SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())")->fetchColumn();   // bookings.created_at is database-local
    $rows = $pdo->query("SELECT b.* FROM bookings b JOIN booking_types bt ON bt.id = b.type_id
                         LEFT JOIN tickets k ON k.booking_id = b.id
                         WHERE bt.type_key = 'day_admission' AND k.id IS NULL ORDER BY b.id LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_ASSOC);
    $ins = $pdo->prepare("INSERT IGNORE INTO tickets (public_code, source, booking_id, purchaser_name, purchaser_email, purchaser_phone, breakdown_json,
                                 quantity, quantity_redeemed, status, visit_date, expires_at, amount_cents, created_at)
                          VALUES (?, 'online', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $n = 0;
    foreach ($rows as $b) {
        $qty = max(1, (int)$b['party_size']);
        $status = $b['status'] === 'cancelled' || $b['payment_status'] === 'refunded' ? 'void'
                : ($b['payment_status'] === 'pending' ? 'pending_payment' : ($b['status'] === 'completed' ? 'redeemed' : 'issued'));
        $bd = [];
        foreach ((array)json_decode((string)$b['ticket_breakdown'], true) as $name => $x) $bd[$name] = (int)(is_array($x) ? ($x['qty'] ?? 0) : $x);
        $created = (new DateTimeImmutable($b['created_at'], new DateTimeZone('UTC')))->modify(-$offset . ' seconds');
        $ins->execute([bcrf_ticket_code(), (int)$b['id'], (string)$b['customer_name'], strtolower((string)$b['email']), (string)$b['phone'],
                       $bd ? json_encode($bd, JSON_UNESCAPED_UNICODE) : null, $qty, $status === 'redeemed' ? $qty : 0, $status,
                       (int)($b['date_flexible'] ?? 0) ? null : $b['booking_date'], $created->modify("+{$months} months")->format('Y-m-d H:i:s'),
                       (int)round((float)$b['amount_total'] * 100), $created->format('Y-m-d H:i:s')]);
        $n += $ins->rowCount();
    }
    // Copies whose order was paid or cancelled since
    $pdo->exec("UPDATE tickets k JOIN bookings b ON b.id = k.booking_id SET k.status = 'issued', k.updated_at = UTC_TIMESTAMP()
                WHERE k.status = 'pending_payment' AND b.payment_status IN ('paid','waived') AND b.status <> 'cancelled'");
    $pdo->exec("UPDATE tickets k JOIN bookings b ON b.id = k.booking_id SET k.status = 'void', k.updated_at = UTC_TIMESTAMP()
                WHERE k.status IN ('pending_payment','issued') AND k.quantity_redeemed = 0 AND (b.status = 'cancelled' OR b.payment_status = 'refunded')");
    return $n;
}

// ── Tickets ─────────────────────────────────────────────────────────────────
/** Why this ticket can't be used now, or null. */
function bcrf_ticket_blocker(array $t): ?string {
    if ($t['status'] === 'void') return 'This ticket was cancelled or refunded.';
    if ($t['status'] === 'pending_payment') return 'This order was never paid.';
    if ((int)$t['quantity_redeemed'] >= (int)$t['quantity']) return 'All ' . (int)$t['quantity'] . ' admissions on this ticket have been used.';
    if ($t['expires_at'] && strtotime($t['expires_at'] . ' UTC') < time()) return 'This ticket expired on ' . bcrf_adm_local($t['expires_at'], 'M j, Y') . '.';
    return null;
}

function bcrf_ticket_view(PDO $pdo, array $t): array {
    $st = $pdo->prepare("SELECT checked_in_at, party_size FROM check_ins WHERE ticket_id = ? ORDER BY id");
    $st->execute([$t['id']]);
    return ['kind' => 'ticket', 'id' => (int)$t['id'], 'code' => bcrf_ticket_code_fmt($t['public_code']), 'source' => $t['source'],
            'name' => $t['purchaser_name'], 'email' => $t['purchaser_email'], 'status' => $t['status'],
            'quantity' => (int)$t['quantity'], 'redeemed' => (int)$t['quantity_redeemed'], 'remaining' => max(0, (int)$t['quantity'] - (int)$t['quantity_redeemed']),
            'breakdown' => json_decode((string)$t['breakdown_json'], true) ?: [], 'visit_date' => $t['visit_date'],
            'bought' => bcrf_adm_local($t['created_at'], 'M j, Y'), 'expires' => $t['expires_at'] ? bcrf_adm_local($t['expires_at'], 'M j, Y') : null,
            'blocker' => bcrf_ticket_blocker($t),
            'visits' => array_map(fn($v) => bcrf_adm_local($v['checked_in_at'], 'M j g:i A') . ' (' . (int)$v['party_size'] . ')', $st->fetchAll(PDO::FETCH_ASSOC))];
}

// ── Passes ──────────────────────────────────────────────────────────────────
function bcrf_pass_blocker(array $p): ?string {
    if ($p['status'] === 'pending_payment') return 'This pass was never paid.';
    if (in_array($p['status'], ['cancelled', 'suspended'], true)) return 'This pass is ' . $p['status'] . '.';
    if ($p['status'] === 'expired' || ($p['valid_until'] && $p['valid_until'] < bcrf_business_date())) return 'This pass expired on ' . date('M j, Y', strtotime($p['valid_until'])) . '.';
    if ($p['valid_from'] && $p['valid_from'] > bcrf_business_date()) return 'This pass starts on ' . date('M j, Y', strtotime($p['valid_from'])) . '.';
    if ($p['status'] !== 'active') return 'This pass is not active.';
    return null;
}

function bcrf_pass_view(PDO $pdo, array $p): array {
    $st = $pdo->prepare("SELECT id, checked_in_at, party_size FROM check_ins WHERE pass_id = ? AND checked_out_at IS NULL AND expected_out_at > UTC_TIMESTAMP() LIMIT 1");
    $st->execute([$p['id']]);
    $open = $st->fetch(PDO::FETCH_ASSOC);
    return ['kind' => 'pass', 'id' => (int)$p['id'], 'name' => $p['holder_name'], 'email' => $p['email'], 'type' => $p['label'],
            'max_guests' => max(1, (int)$p['max_guests']), 'valid_until' => $p['valid_until'] ? date('M j, Y', strtotime($p['valid_until'])) : null,
            'status' => $p['status'], 'blocker' => bcrf_pass_blocker($p),
            'on_site' => $open ? 'Checked in at ' . bcrf_adm_local($open['checked_in_at']) . ' (' . (int)$open['party_size'] . ')' : null];
}

/** Find what a scan or a search points at: ['results' => [views...]]. */
function bcrf_gate_search(PDO $pdo, string $q): array {
    $q = trim($q);
    if ($q === '') return [];
    $passSql = "SELECT p.*, t.label, t.max_guests FROM passes p JOIN pass_types t ON t.id = p.pass_type_id";
    // A pass QR is a link to passes/verify.php?token=…
    if (preg_match('/[?&]token=([A-Za-z0-9]{16,64})/', $q, $m) || preg_match('/^[a-f0-9]{48}$/i', $q, $m2)) {
        $tok = $m[1] ?? $q;
        $st = $pdo->prepare("$passSql WHERE p.token = ? LIMIT 1");
        $st->execute([$tok]);
        return ($p = $st->fetch(PDO::FETCH_ASSOC)) ? [bcrf_pass_view($pdo, $p)] : [];
    }
    // A ticket code (also inside a link: …?c=CODE)
    $code = preg_match('/[?&]c=([A-Za-z0-9-]{10,14})/', $q, $m) ? bcrf_ticket_code_norm($m[1]) : bcrf_ticket_code_norm($q);
    if (strlen($code) === 10 && preg_match('/^[A-Z2-9]{10}$/', $code)) {
        $st = $pdo->prepare("SELECT * FROM tickets WHERE public_code = ?");
        $st->execute([$code]);
        if ($t = $st->fetch(PDO::FETCH_ASSOC)) return [bcrf_ticket_view($pdo, $t)];
    }
    if (mb_strlen($q) < 2) return [];
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $digits = preg_replace('/\D/', '', $q);
    $out = [];
    $st = $pdo->prepare("SELECT * FROM tickets WHERE status <> 'void' AND source = 'online' AND (purchaser_name LIKE ? OR purchaser_email LIKE ?"
                        . (strlen($digits) >= 4 ? " OR REPLACE(REPLACE(REPLACE(REPLACE(purchaser_phone,'-',''),' ',''),'(',''),')','') LIKE ?" : '') . ")
                         ORDER BY (quantity_redeemed < quantity) DESC, id DESC LIMIT 15");
    $st->execute(strlen($digits) >= 4 ? [$like, $like, '%' . $digits . '%'] : [$like, $like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) $out[] = bcrf_ticket_view($pdo, $t);
    $st = $pdo->prepare("$passSql WHERE p.holder_name LIKE ? OR p.email LIKE ? ORDER BY (p.status = 'active') DESC, p.id DESC LIMIT 10");
    $st->execute([$like, $like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $out[] = bcrf_pass_view($pdo, $p);
    return $out;
}

/** Admit $n guests on a ticket. Returns the check-in id. */
function bcrf_ticket_redeem(PDO $pdo, int $ticketId, int $n, ?string $override = null): int {
    if ($n <= 0) throw new BcrfSaleError('Choose how many guests are coming in.');
    bcrf_capacity_lock($pdo);
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT * FROM tickets WHERE id = ? FOR UPDATE");
        $st->execute([$ticketId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) throw new BcrfSaleError('Ticket not found.');
        if ($why = bcrf_ticket_blocker($t)) throw new BcrfSaleError($why);
        $left = (int)$t['quantity'] - (int)$t['quantity_redeemed'];
        if ($n > $left) throw new BcrfSaleError("Only {$left} left on this ticket.");
        [$ov, $ovBy] = bcrf_capacity_check($pdo, $n, $override);
        $red = (int)$t['quantity_redeemed'] + $n;
        $pdo->prepare("UPDATE tickets SET quantity_redeemed = ?, status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([$red, $red >= (int)$t['quantity'] ? 'redeemed' : 'partially_redeemed', $ticketId]);
        $cid = bcrf_check_in_row($pdo, ['source_type' => 'ticket', 'ticket_id' => $ticketId, 'party_size' => $n, 'guests_counted' => $n,
                                        'override' => $ov, 'override_by' => $ovBy]);
        if (function_exists('bcrf_event')) bcrf_event('ticket.redeemed', 'tickets', $ticketId, ['guests' => $n, 'left' => $left - $n]);
        // Roost Rewards: today's visit for a member buyer (savepoint; never blocks entry).
        require_once __DIR__ . '/rewards.php';
        bcrf_rewards_after_ticket_redeem($pdo, $t, function_exists('bcrf_farm_now') ? bcrf_farm_now()[1] : date('Y-m-d'));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        bcrf_capacity_unlock($pdo);
    }
    bcrf_adm_audit($pdo, 'redeem', 'tickets', $ticketId, sprintf('Ticket %s: %d in (%s)%s', bcrf_ticket_code_fmt($t['public_code']), $n, $t['purchaser_name'],
        $ov ? ' - capacity override: ' . $ov : ''));
    return $cid;
}

/** Admit a passholder party. Also writes pass_visits so the pass's own pages keep showing visits. */
function bcrf_pass_admit(PDO $pdo, int $passId, int $n, ?string $override = null): int {
    if ($n <= 0) throw new BcrfSaleError('Choose how many guests are coming in.');
    bcrf_capacity_lock($pdo);
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT p.*, t.label, t.max_guests FROM passes p JOIN pass_types t ON t.id = p.pass_type_id WHERE p.id = ? FOR UPDATE");
        $st->execute([$passId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new BcrfSaleError('Pass not found.');
        if ($why = bcrf_pass_blocker($p)) throw new BcrfSaleError($why);
        $max = max(1, (int)$p['max_guests']);
        if ($n > $max) throw new BcrfSaleError("This pass covers up to {$max} guest" . ($max === 1 ? '' : 's') . '. Sell admission for the others.');
        $st = $pdo->prepare("SELECT COUNT(*) FROM check_ins WHERE pass_id = ? AND checked_out_at IS NULL AND expected_out_at > UTC_TIMESTAMP()");
        $st->execute([$passId]);
        if ((int)$st->fetchColumn()) throw new BcrfSaleError('This pass is already checked in. Check that party out first if they left and came back.');
        [$ov, $ovBy] = bcrf_capacity_check($pdo, $n, $override);
        $pdo->prepare("INSERT INTO pass_visits (pass_id, visited_at, guest_count, notes) VALUES (?, NOW(), ?, 'Gate (Farm OS)')")->execute([$passId, $n]);
        $vid = (int)$pdo->lastInsertId();
        $cid = bcrf_check_in_row($pdo, ['source_type' => 'pass', 'pass_id' => $passId, 'pass_visit_id' => $vid, 'party_size' => $n, 'guests_counted' => $n,
                                        'override' => $ov, 'override_by' => $ovBy]);
        if (function_exists('bcrf_event')) bcrf_event('pass.scanned', 'passes', $passId, ['guests' => $n]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        bcrf_capacity_unlock($pdo);
    }
    bcrf_adm_audit($pdo, 'checkin', 'passes', $passId, sprintf('Pass visit: %s (%s), %d in%s', $p['holder_name'], $p['label'], $n, $ov ? ' - capacity override: ' . $ov : ''));
    return $cid;
}

/** Called by passes/log_visit.php after it logs a visit the old way, so it counts toward capacity too (no capacity block there). */
function bcrf_pass_visit_to_check_in(PDO $pdo, int $passId, int $visitId, int $guests): void {
    if (!bcrf_admission_ready($pdo)) return;
    try { bcrf_check_in_row($pdo, ['source_type' => 'pass', 'pass_id' => $passId, 'pass_visit_id' => $visitId, 'party_size' => max(1, $guests),
                                   'guests_counted' => max(1, $guests), 'note' => 'Logged on the pass page']); }
    catch (Throwable $e) { error_log('[admission] pass visit check-in: ' . $e->getMessage()); }
}

function bcrf_check_out(PDO $pdo, int $checkInId): void {
    $pdo->prepare("UPDATE check_ins SET checked_out_at = UTC_TIMESTAMP() WHERE id = ? AND checked_out_at IS NULL")->execute([$checkInId]);
    if (function_exists('bcrf_event')) bcrf_event('checkin.checked_out', 'check_ins', $checkInId);
}

/** On-site parties for the gate screen. */
function bcrf_on_site_view(PDO $pdo): array {
    return array_map(function ($r) {
        $who = $r['source_type'] === 'pass' ? ($r['holder_name'] ?: 'Passholder') : ($r['source_type'] === 'gate_sale' ? 'Walk-in' : ($r['purchaser_name'] ?: 'Ticket'));
        return ['id' => (int)$r['id'], 'who' => $who, 'source' => $r['source_type'], 'party' => (int)$r['party_size'], 'counted' => (int)$r['guests_counted'],
                'in' => bcrf_adm_local($r['checked_in_at']), 'out' => bcrf_adm_local($r['expected_out_at']), 'override' => (string)$r['capacity_override_reason']];
    }, bcrf_on_site($pdo));
}

// ── Admission sold on the register ──────────────────────────────────────────
/** How many guests the admission lines of a priced sale admit, and how many count toward capacity. */
function bcrf_admission_guests(PDO $pdo, array $pricedLines): array {
    $party = 0; $counted = 0; $bd = [];
    $notCounting = [];
    foreach ($pdo->query("SELECT product_id FROM ticket_types WHERE counts_toward_capacity = 0 AND archived_at IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $pid) $notCounting[(int)$pid] = true;
    foreach ($pricedLines as $l) {
        if (($l['product_type'] ?? '') !== 'admission') continue;
        $q = (int)round((float)$l['quantity']);
        $party += $q;
        if (empty($notCounting[(int)$l['product_id']])) $counted += $q;
        $bd[$l['description']] = ($bd[$l['description']] ?? 0) + $q;
    }
    return [$party, $counted, $bd];
}

/** Inside the sale's transaction: a used ticket for the admission bought, and the party's check-in. */
function bcrf_admission_for_sale(PDO $pdo, int $saleId, int $party, int $counted, array $breakdown, int $amountCents, ?string $ov, ?int $ovBy): void {
    $code = bcrf_ticket_code();
    $pdo->prepare("INSERT INTO tickets (public_code, source, sale_id, breakdown_json, quantity, quantity_redeemed, status, amount_cents, created_at, created_by)
                   VALUES (?, 'gate', ?, ?, ?, ?, 'redeemed', ?, UTC_TIMESTAMP(), ?)")
        ->execute([$code, $saleId, json_encode($breakdown, JSON_UNESCAPED_UNICODE), $party, $party, $amountCents, (int)($_SESSION['user_id'] ?? 0) ?: null]);
    $tid = (int)$pdo->lastInsertId();
    bcrf_check_in_row($pdo, ['source_type' => 'gate_sale', 'ticket_id' => $tid, 'sale_id' => $saleId, 'party_size' => $party, 'guests_counted' => $counted,
                             'override' => $ov, 'override_by' => $ovBy]);
}

}
