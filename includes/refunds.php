<?php // includes/refunds.php
/*
 * Farm OS Phase 1, slice 3 (Nov 6, 2026): refunds and voids. Tables: admin/migrations/0011_refunds.php.
 *
 *   bcrf_sale_detail($pdo, $saleId)          a sale with line ids, what's left to refund, refunds, and whether it can be voided
 *   bcrf_refund_quote($pdo, $saleId, $lines)  what refunding those lines gives back, and a suggested split by tender
 *   bcrf_refund_complete($pdo, $input)        save a refund (idempotent, like sales)
 *   bcrf_sale_void($pdo, $saleId, $reason)     cancel today's sale before its drawer closes
 *
 * Rules (05 › Refunds and voids, 03 › Refunds and voids):
 *   - A reason is required for every refund and void.
 *   - Refund by line, all or part of a line. A partial line gives back its share of what was actually paid
 *     (after the receipt discount, plus its tax); the last part of a line gives back exactly what is left, so
 *     a line refunded in pieces always adds up to what it cost.
 *   - Money goes back by the original tender by default; cash is always allowed and comes out of the open
 *     drawer on this register. PayPal / Venmo / Cash App refunds are sent from the app first, then recorded
 *     with the refund's reference. No tender gives back more than was paid with it.
 *   - Each line is either put back into stock or written off (damaged, eaten, not stocked).
 *   - refund_approval_threshold_cents (0 = off): refunds above it need an Owner or Manager.
 *   - Voids: same business date, the sale's drawer still open, nothing refunded yet. Stock comes back,
 *     the sale drops out of the drawer's expected cash. Money taken by app is sent back in the app.
 *   - Slice 4a: admission sold at the gate was used on the spot, so refunding it needs an Owner or Manager
 *     (05: redeemed tickets can't be refunded without a Manager). A void cancels its ticket and checks the party out.
 */

if (!function_exists('bcrf_refund_complete')) {

if (!class_exists('BcrfSaleError')) { class BcrfSaleError extends RuntimeException {} }

function bcrf_refunds_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
                               AND table_name IN ('refunds','refund_lines','refund_payments')")->fetchColumn();
        return $ready = ($n === 3);
    } catch (Throwable $e) { return $ready = false; }
}

function bcrf_refund_threshold(PDO $pdo): int {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'refund_approval_threshold_cents'");
        $st->execute();
        return max(0, (int)$st->fetchColumn());
    } catch (Throwable $e) { return 0; }
}

function bcrf_is_approver(): bool { return in_array((string)($_SESSION['user_role'] ?? ''), ['administrator', 'moderator'], true); }

/** Sale id from a receipt number like R-2026-000012 (also accepts just "12" for this year). */
function bcrf_sale_id_by_number(PDO $pdo, string $q): ?int {
    $q = strtoupper(trim($q));
    if (preg_match('/^\d{1,6}$/', $q)) {
        [, , $year] = bcrf_farm_now();
        $q = sprintf('R-%d-%06d', $year, (int)$q);
    }
    $st = $pdo->prepare("SELECT id FROM sales WHERE sale_number = ?");
    $st->execute([$q]);
    return ($id = (int)$st->fetchColumn()) ? $id : null;
}

/** Why this sale can't be voided right now, or null if it can. */
function bcrf_void_blocker(PDO $pdo, array $s): ?string {
    if ($s['status'] === 'voided') return 'This sale is already voided.';
    if ((int)$s['refunded_cents'] > 0 || $s['status'] !== 'completed') return 'Part of this sale was refunded, so it can\'t be voided. Refund the rest instead.';
    [, $today] = bcrf_farm_now();
    if ($s['business_date'] !== $today) return 'Only today\'s sales can be voided. Refund it instead.';
    if ($s['drawer_session_id']) {
        $st = $pdo->prepare("SELECT status FROM drawer_sessions WHERE id = ?");
        $st->execute([$s['drawer_session_id']]);
        if ($st->fetchColumn() !== 'open') return 'The drawer this sale was rung on is closed. Refund it instead.';
    }
    return null;
}

/** A sale for the Sales screen: bcrf_sale_get() plus line ids, refund state, refunds, and void status. */
function bcrf_sale_detail(PDO $pdo, int $saleId): ?array {
    $s = bcrf_sale_get($pdo, $saleId);
    if (!$s) return null;
    $st = $pdo->prepare("SELECT sl.id, sl.line_no, sl.product_id, sl.description, sl.quantity, sl.unit, sl.gross_cents, sl.discount_cents,
                                sl.tax_cents, sl.line_total_cents, sl.refunded_quantity, sl.refunded_net_cents, sl.refunded_tax_cents,
                                sl.category_label, i.track_inventory
                         FROM sale_lines sl LEFT JOIN inventory i ON i.id = sl.product_id WHERE sl.sale_id = ? ORDER BY sl.line_no");
    $st->execute([$saleId]);
    $s['lines'] = array_map(function ($l) {
        $q = (float)$l['quantity']; $rq = (float)$l['refunded_quantity'];
        return ['id' => (int)$l['id'], 'line_no' => (int)$l['line_no'], 'product_id' => $l['product_id'] ? (int)$l['product_id'] : null,
                'description' => $l['description'], 'quantity' => $l['quantity'], 'unit' => $l['unit'], 'unit_price_cents' => null,
                'gross_cents' => (int)$l['gross_cents'], 'discount_cents' => (int)$l['discount_cents'], 'tax_cents' => (int)$l['tax_cents'],
                'line_total_cents' => (int)$l['line_total_cents'], 'category_label' => $l['category_label'],
                'refunded_quantity' => $rq, 'remaining_quantity' => round($q - $rq, 3),
                'remaining_cents' => (int)$l['line_total_cents'] - (int)$l['refunded_net_cents'] - (int)$l['refunded_tax_cents'],
                'stockable' => $l['product_id'] && (int)$l['track_inventory'] === 1];
    }, $st->fetchAll(PDO::FETCH_ASSOC));
    // unit price back for the receipt
    $st = $pdo->prepare("SELECT id, unit_price_cents, tax_rate_bp FROM sale_lines WHERE sale_id = ?");
    $st->execute([$saleId]);
    $up = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $up[(int)$r['id']] = $r;
    foreach ($s['lines'] as &$l) { $l['unit_price_cents'] = (int)$up[$l['id']]['unit_price_cents']; $l['tax_rate_bp'] = (int)$up[$l['id']]['tax_rate_bp']; }
    unset($l);

    $s['refunds'] = [];
    $st = $pdo->prepare("SELECT id, refund_number, total_cents, reason, created_at, created_by FROM refunds WHERE sale_id = ? ORDER BY id");
    $st->execute([$saleId]);
    $pp = $pdo->prepare("SELECT tender, amount_cents, reference FROM refund_payments WHERE refund_id = ? ORDER BY id");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pp->execute([$r['id']]);
        $s['refunds'][] = ['refund_number' => $r['refund_number'], 'total_cents' => (int)$r['total_cents'], 'reason' => $r['reason'],
                           'when' => bcrf_local_time($r['created_at'], 'n/j g:i A'), 'by' => bcrf_staff_name($pdo, $r['created_by']),
                           'payments' => $pp->fetchAll(PDO::FETCH_ASSOC)];
    }
    $s['refunded_cents'] = (int)$s['refunded_cents'];
    $s['refundable_cents'] = $s['status'] === 'voided' ? 0 : $s['total_cents'] - $s['refunded_cents'];
    $s['void_blocker'] = bcrf_void_blocker($pdo, $s);
    $s['voided_local'] = $s['voided_at'] ? bcrf_local_time($s['voided_at'], 'n/j g:i A') : null;
    $s['voided_by_name'] = bcrf_staff_name($pdo, $s['voided_by']);
    $s['tender_left'] = bcrf_refund_tender_left($pdo, $saleId);
    return $s;
}

/** What can still go back per original tender: tender => cents. */
function bcrf_refund_tender_left(PDO $pdo, int $saleId): array {
    $st = $pdo->prepare("SELECT tender, SUM(amount_cents) FROM payments WHERE sale_id = ? AND status = 'completed' GROUP BY tender");
    $st->execute([$saleId]);
    $left = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    $st = $pdo->prepare("SELECT rp.tender, SUM(rp.amount_cents) FROM refund_payments rp JOIN refunds r ON r.id = rp.refund_id WHERE r.sale_id = ? GROUP BY rp.tender");
    $st->execute([$saleId]);
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $t => $c) $left[$t] = ($left[$t] ?? 0) - (int)$c;
    return $left;
}

/**
 * Price a refund. $want: [['sale_line_id' => id, 'qty' => n, 'restock' => bool], ...]. $lineRows: sale_lines rows (id => row).
 * Returns ['lines' => [...], 'net' => c, 'tax' => c, 'total' => c].
 */
function bcrf_refund_price(array $want, array $lineRows): array {
    $out = []; $net = 0; $tax = 0;
    foreach ($want as $w) {
        $id = (int)($w['sale_line_id'] ?? 0);
        if (!isset($lineRows[$id])) throw new BcrfSaleError('An item to refund isn\'t on this sale.');
        $l = $lineRows[$id];
        $qMilli    = (int)round((float)$w['qty'] * 1000);
        $lineMilli = (int)round((float)$l['quantity'] * 1000);
        $leftMilli = $lineMilli - (int)round((float)$l['refunded_quantity'] * 1000);
        if ($qMilli <= 0) continue;
        if ($qMilli > $leftMilli) throw new BcrfSaleError('"' . $l['description'] . '": only ' . ($leftMilli / 1000) . ' left to refund.');
        $lineNet = (int)$l['gross_cents'] - (int)$l['discount_cents'];
        if ($qMilli === $leftMilli) {   // the rest of the line: exactly what is left, so the pieces add up
            $n = $lineNet - (int)$l['refunded_net_cents'];
            $t = (int)$l['tax_cents'] - (int)$l['refunded_tax_cents'];
        } else {
            $n = bcrf_muldiv($lineNet, $qMilli, $lineMilli);
            $t = bcrf_muldiv((int)$l['tax_cents'], $qMilli, $lineMilli);
        }
        $stock = !empty($w['restock']) && $l['product_id'] && (int)($l['track_inventory'] ?? 0) === 1;
        $out[] = ['sale_line_id' => $id, 'product_id' => $l['product_id'] ? (int)$l['product_id'] : null, 'description' => $l['description'],
                  'quantity' => $qMilli / 1000, 'net_cents' => $n, 'tax_cents' => $t, 'total_cents' => $n + $t, 'restock' => $stock];
        $net += $n; $tax += $t;
    }
    if (!$out) throw new BcrfSaleError('Choose at least one item to refund.');
    return ['lines' => $out, 'net' => $net, 'tax' => $tax, 'total' => $net + $tax];
}

/** Suggested way to give $total back: original tenders, largest first; cash for anything left. */
function bcrf_refund_suggest(array $tenderLeft, int $total): array {
    arsort($tenderLeft);
    $out = []; $rest = $total;
    foreach ($tenderLeft as $t => $c) {
        if ($rest <= 0 || $c <= 0) continue;
        $a = min($c, $rest);
        $out[] = ['tender' => $t, 'amount_cents' => $a];
        $rest -= $a;
    }
    if ($rest > 0) $out[] = ['tender' => 'cash', 'amount_cents' => $rest];
    return $out;
}

function bcrf_sale_lines_locked(PDO $pdo, int $saleId, bool $lock): array {
    $st = $pdo->prepare("SELECT sl.*, i.track_inventory FROM sale_lines sl LEFT JOIN inventory i ON i.id = sl.product_id
                         WHERE sl.sale_id = ?" . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$saleId]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[(int)$r['id']] = $r;
    return $rows;
}

/** Quote for the refund screen (no writes). */
function bcrf_refund_quote(PDO $pdo, int $saleId, array $want): array {
    $s = bcrf_sale_get($pdo, $saleId);
    if (!$s) throw new BcrfSaleError('Sale not found.');
    if ($s['status'] === 'voided') throw new BcrfSaleError('This sale was voided; there is nothing to refund.');
    $q = bcrf_refund_price($want, bcrf_sale_lines_locked($pdo, $saleId, false));
    $left = bcrf_refund_tender_left($pdo, $saleId);
    $th = bcrf_refund_threshold($pdo);
    return $q + ['suggested' => bcrf_refund_suggest($left, $q['total']), 'tender_left' => $left,
                 'needs_approval' => $th > 0 && $q['total'] > $th, 'can_approve' => bcrf_is_approver(), 'threshold_cents' => $th];
}

/** Save a refund. Input: idempotency_key, sale_id, register_id, reason, lines[], payments[]. Returns ['replayed', 'refund_number', 'sale' => detail]. */
function bcrf_refund_complete(PDO $pdo, array $in): array {
    if (!bcrf_refunds_ready($pdo)) throw new BcrfSaleError('Refunds are waiting for migration 0011 (Settings › Database).');
    $key = (string)($in['idempotency_key'] ?? '');
    if (!preg_match('/^[A-Za-z0-9-]{16,64}$/', $key)) throw new BcrfSaleError('This refund is missing its save code. Reload and try again.');
    $find = function () use ($pdo, $key) {
        $st = $pdo->prepare("SELECT sale_id, refund_number FROM refunds WHERE idempotency_key = ?");
        $st->execute([$key]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    if ($r = $find()) return ['replayed' => true, 'refund_number' => $r['refund_number'], 'sale' => bcrf_sale_detail($pdo, (int)$r['sale_id'])];

    $saleId = (int)($in['sale_id'] ?? 0);
    $regId  = (int)($in['register_id'] ?? 0);
    $reason = mb_substr(trim((string)($in['reason'] ?? '')), 0, 255);
    if ($reason === '') throw new BcrfSaleError('Give a reason for the refund.');
    $tenders = bcrf_tenders();
    $pays = [];
    foreach ((array)($in['payments'] ?? []) as $p) {
        $t = strtolower((string)($p['tender'] ?? ''));
        $a = (int)($p['amount_cents'] ?? 0);
        if (!isset($tenders[$t])) throw new BcrfSaleError('Unknown refund method.');
        if ($a <= 0) continue;
        $ref = mb_substr(trim((string)($p['reference'] ?? '')), 0, 80) ?: null;
        if (bcrf_tender_is_p2p($t) && !$ref) throw new BcrfSaleError('Send the ' . $tenders[$t] . ' refund from the app first, then enter the last characters of its transaction ID.');
        $pays[] = ['tender' => $t, 'amount_cents' => $a, 'reference' => $ref];
    }
    if (!$pays) throw new BcrfSaleError('Say how the money goes back.');
    $uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
    [, $bdate, $year] = bcrf_farm_now();

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM sales WHERE id = ? FOR UPDATE");
        $st->execute([$saleId]);
        $sale = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sale) throw new BcrfSaleError('Sale not found.');
        if ($sale['status'] === 'voided') throw new BcrfSaleError('This sale was voided; there is nothing to refund.');
        $lines = bcrf_sale_lines_locked($pdo, $saleId, true);
        $q = bcrf_refund_price((array)($in['lines'] ?? []), $lines);

        $sum = array_sum(array_column($pays, 'amount_cents'));
        if ($sum !== $q['total']) throw new BcrfSaleError(sprintf('The refund is $%s but the money going back adds up to $%s.', number_format($q['total'] / 100, 2), number_format($sum / 100, 2)));
        $left = bcrf_refund_tender_left($pdo, $saleId);
        $byT = [];
        foreach ($pays as $p) $byT[$p['tender']] = ($byT[$p['tender']] ?? 0) + $p['amount_cents'];
        foreach ($byT as $t => $c) {
            if ($t !== 'cash' && $c > ($left[$t] ?? 0)) {
                throw new BcrfSaleError(sprintf('Only $%s was paid by %s and not yet refunded. Give the rest back in cash.', number_format(max(0, $left[$t] ?? 0) / 100, 2), $tenders[$t]));
            }
        }
        $th = bcrf_refund_threshold($pdo);
        $approved = null;
        foreach ($q['lines'] as $ql) {
            if (($lines[$ql['sale_line_id']]['product_type'] ?? '') === 'admission') {
                if (!bcrf_is_approver()) throw new BcrfSaleError('"' . $ql['description'] . '" was used at the gate. An Owner or Manager has to refund admission.');
                $approved = $uid;
            }
        }
        if ($th > 0 && $q['total'] > $th) {
            if (!bcrf_is_approver()) throw new BcrfSaleError(sprintf('Refunds over $%s need an Owner or Manager.', number_format($th / 100, 2)));
            $approved = $uid;
        }
        $drawerId = null;
        if ($regId && function_exists('bcrf_drawer_current')) {
            $d = bcrf_drawer_current($pdo, $regId, true);
            $drawerId = $d ? (int)$d['id'] : null;
        }
        if (!empty($byT['cash']) && !$drawerId) throw new BcrfSaleError('Cash refunds come out of the drawer: open the drawer on this register first.');

        $number = sprintf('RF-%d-%06d', $year, bcrf_next_number($pdo, 'refund', $year));
        try {
            $pdo->prepare("INSERT INTO refunds (refund_number, sale_id, register_id, drawer_session_id, business_date, net_cents, tax_cents, total_cents,
                                                reason, approved_by, idempotency_key, created_at, created_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(3), ?)")
                ->execute([$number, $saleId, $regId ?: null, $drawerId, $bdate, $q['net'], $q['tax'], $q['total'], $reason, $approved, $key, $uid]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_idem')) {
                $pdo->rollBack();
                if ($r = $find()) return ['replayed' => true, 'refund_number' => $r['refund_number'], 'sale' => bcrf_sale_detail($pdo, (int)$r['sale_id'])];
            }
            throw $e;
        }
        $rid = (int)$pdo->lastInsertId();
        $insL = $pdo->prepare("INSERT INTO refund_lines (refund_id, sale_line_id, product_id, quantity, net_cents, tax_cents, total_cents, restock) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $updL = $pdo->prepare("UPDATE sale_lines SET refunded_quantity = refunded_quantity + ?, refunded_net_cents = refunded_net_cents + ?, refunded_tax_cents = refunded_tax_cents + ? WHERE id = ?");
        $getStock = $pdo->prepare("SELECT stock_quantity FROM inventory WHERE id = ? FOR UPDATE");
        $setStock = $pdo->prepare("UPDATE inventory SET stock_quantity = ? WHERE id = ?");
        $ledger = $pdo->prepare("INSERT INTO inventory_adjustments (product_id, quantity_change, quantity_before, quantity_after, reason, source_type, source_id, note, created_at, created_by)
                                 VALUES (?, ?, ?, ?, 'refund_restock', 'refund', ?, ?, UTC_TIMESTAMP(3), ?)");
        foreach ($q['lines'] as $l) {
            $insL->execute([$rid, $l['sale_line_id'], $l['product_id'], $l['quantity'], $l['net_cents'], $l['tax_cents'], $l['total_cents'], $l['restock'] ? 1 : 0]);
            $updL->execute([$l['quantity'], $l['net_cents'], $l['tax_cents'], $l['sale_line_id']]);
            if ($l['restock']) {
                $getStock->execute([$l['product_id']]);
                $before = round((float)$getStock->fetchColumn(), 3);
                $after = round($before + $l['quantity'], 3);
                $setStock->execute([$after, $l['product_id']]);
                $ledger->execute([$l['product_id'], $l['quantity'], $before, $after, $rid, 'Refund ' . $number, $uid]);
            }
        }
        $insP = $pdo->prepare("INSERT INTO refund_payments (refund_id, tender, amount_cents, reference, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(3))");
        foreach ($pays as $p) $insP->execute([$rid, $p['tender'], $p['amount_cents'], $p['reference']]);

        $st = $pdo->prepare("SELECT SUM(quantity > refunded_quantity) FROM sale_lines WHERE sale_id = ?");
        $st->execute([$saleId]);
        $status = (int)$st->fetchColumn() === 0 ? 'refunded' : 'partially_refunded';
        $pdo->prepare("UPDATE sales SET refunded_cents = refunded_cents + ?, status = ?, updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")
            ->execute([$q['total'], $status, $uid, $saleId]);
        if (function_exists('bcrf_event')) bcrf_event('sale.refunded', 'sales', $saleId, ['refund_number' => $number, 'total_cents' => $q['total'], 'tenders' => array_keys($byT)]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    if (function_exists('bcrf_audit_event')) {
        [$type, $u, $name, $ip] = bcrf_audit_actor();
        bcrf_audit_event($pdo, ['actor_type' => $type, 'actor_id' => $u, 'actor_label' => $name, 'ip' => $ip, 'action' => 'refund',
            'object_type' => 'sales', 'object_id' => $saleId, 'object_label' => sprintf('Refund %s on %s - $%s: %s', $number, $sale['sale_number'], number_format($q['total'] / 100, 2), $reason),
            'after' => ['refund_number' => $number, 'lines' => $q['lines'], 'payments' => $pays, 'approved_by' => $approved]]);
    }
    return ['replayed' => false, 'refund_number' => $number, 'sale' => bcrf_sale_detail($pdo, $saleId)];
}

/** Void today's sale. Doing it twice is harmless (the second time just returns the voided sale). */
function bcrf_sale_void(PDO $pdo, int $saleId, string $reason): array {
    if (!bcrf_refunds_ready($pdo)) throw new BcrfSaleError('Voids are waiting for migration 0011 (Settings › Database).');
    $reason = mb_substr(trim($reason), 0, 255);
    if ($reason === '') throw new BcrfSaleError('Give a reason for the void.');
    $uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM sales WHERE id = ? FOR UPDATE");
        $st->execute([$saleId]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) throw new BcrfSaleError('Sale not found.');
        if ($s['status'] === 'voided') { $pdo->rollBack(); return bcrf_sale_detail($pdo, $saleId); }
        if ($s['drawer_session_id']) $pdo->prepare("SELECT id FROM drawer_sessions WHERE id = ? FOR UPDATE")->execute([$s['drawer_session_id']]);   // hold off a close
        if ($why = bcrf_void_blocker($pdo, $s)) throw new BcrfSaleError($why);

        $getStock = $pdo->prepare("SELECT stock_quantity FROM inventory WHERE id = ? FOR UPDATE");
        $setStock = $pdo->prepare("UPDATE inventory SET stock_quantity = ? WHERE id = ?");
        $ledger = $pdo->prepare("INSERT INTO inventory_adjustments (product_id, quantity_change, quantity_before, quantity_after, reason, source_type, source_id, note, created_at, created_by)
                                 VALUES (?, ?, ?, ?, 'void', 'sale', ?, ?, UTC_TIMESTAMP(3), ?)");
        foreach (bcrf_sale_lines_locked($pdo, $saleId, true) as $l) {
            if (!$l['product_id'] || (int)$l['track_inventory'] !== 1) continue;
            $getStock->execute([$l['product_id']]);
            $before = round((float)$getStock->fetchColumn(), 3);
            $after = round($before + (float)$l['quantity'], 3);
            $setStock->execute([$after, $l['product_id']]);
            $ledger->execute([$l['product_id'], (float)$l['quantity'], $before, $after, $saleId, 'Void ' . $s['sale_number'], $uid]);
        }
        $pdo->prepare("UPDATE sales SET status = 'voided', voided_at = UTC_TIMESTAMP(3), voided_by = ?, void_reason = ?, updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")
            ->execute([$uid, $reason, $uid, $saleId]);
        $pdo->prepare("UPDATE payments SET status = 'voided' WHERE sale_id = ?")->execute([$saleId]);
        if (function_exists('bcrf_admission_ready') && bcrf_admission_ready($pdo)) {   // admission sold on this sale: cancel the ticket, the party leaves the count
            $pdo->prepare("UPDATE tickets SET status = 'void', updated_at = UTC_TIMESTAMP() WHERE sale_id = ?")->execute([$saleId]);
            $pdo->prepare("UPDATE check_ins SET checked_out_at = UTC_TIMESTAMP(), note = 'Sale voided' WHERE sale_id = ? AND checked_out_at IS NULL")->execute([$saleId]);
        }
        if ($s['legacy_receipt_id']) {   // Sales Log / dashboard: show it as voided and stop counting it
            $pdo->prepare("UPDATE pos_receipts SET payment_method = 'VOIDED', subtotal = 0, discount = 0, tax = 0, total = 0 WHERE id = ?")->execute([$s['legacy_receipt_id']]);
        }
        if (function_exists('bcrf_event')) bcrf_event('sale.voided', 'sales', $saleId, ['sale_number' => $s['sale_number'], 'total_cents' => (int)$s['total_cents']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    if (function_exists('bcrf_audit_event')) {
        [$type, $u, $name, $ip] = bcrf_audit_actor();
        bcrf_audit_event($pdo, ['actor_type' => $type, 'actor_id' => $u, 'actor_label' => $name, 'ip' => $ip, 'action' => 'void',
            'object_type' => 'sales', 'object_id' => $saleId, 'object_label' => sprintf('Voided %s - $%s: %s', $s['sale_number'], number_format($s['total_cents'] / 100, 2), $reason),
            'before' => ['status' => $s['status']], 'after' => ['status' => 'voided', 'reason' => $reason]]);
    }
    return bcrf_sale_detail($pdo, $saleId);
}

/** Recent sales for the Sales screen: today's on this register (or a receipt number search). */
function bcrf_sales_list(PDO $pdo, int $registerId, string $q = ''): array {
    if ($q !== '') {
        $id = bcrf_sale_id_by_number($pdo, $q);
        $ids = $id ? [$id] : [];
    } else {
        [, $today] = bcrf_farm_now();
        $st = $pdo->prepare("SELECT id FROM sales WHERE business_date = ? AND (register_id = ? OR ? = 0) ORDER BY id DESC LIMIT 100");
        $st->execute([$today, $registerId, $registerId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT s.id, s.sale_number, s.status, s.total_cents, s.refunded_cents, s.completed_at,
                                (SELECT GROUP_CONCAT(DISTINCT p.tender) FROM payments p WHERE p.sale_id = s.id) AS tenders,
                                (SELECT COUNT(*) FROM sale_lines l WHERE l.sale_id = s.id) AS line_count
                         FROM sales s WHERE s.id IN ($in) ORDER BY s.id DESC");
    $st->execute($ids);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'sale_number' => $r['sale_number'], 'status' => $r['status'],
        'total_cents' => (int)$r['total_cents'], 'refunded_cents' => (int)$r['refunded_cents'], 'time' => bcrf_local_time($r['completed_at']),
        'tenders' => (string)$r['tenders'], 'lines' => (int)$r['line_count']], $st->fetchAll(PDO::FETCH_ASSOC));
}

}
