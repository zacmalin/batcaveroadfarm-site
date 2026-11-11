<?php // includes/gift_cards.php
/*
 * Farm OS Phase 2: gift cards (Nov 11, 2026; 05 › Gift cards, Q-08). Tables: admin/migrations/0028_gift_cards.php.
 *
 * Owner decisions (Nov 11, 2026):
 *   - Sold on the register (staff type the amount, $5–$500 a card) and online ($25 / $50 / $100 or $10–$500).
 *   - Spent on the register only for now (tender "Gift card"). Online checkout redemption can come later.
 *   - On the register the buyer gets a printed card (browser print) that can also be emailed. Online cards are emailed.
 *   - No expiry (05). No tax: the Gift Card product uses the Exempt tax category (05 › Tax; tax is due when it's spent).
 *   - Gift cards can't be bought with gift cards, can't be discounted, and don't earn Roost Rewards points.
 *
 * The balance is a ledger (gift_card_txns: issue, redeem, refund_credit, refund_void, void, adjust, reverse);
 * gift_cards.balance_cents is kept equal to its sum. Every change locks the card row first.
 *
 * Codes: 16 characters from ABCDEFGHJKLMNPQRSTUVWXYZ23456789 (no 0/O, 1/I), shown XXXX-XXXX-XXXX-XXXX.
 * Whoever has the code can spend it (like cash), so card pages are noindex and staff see only the last 4 in lists.
 * The QR on a card holds /gift-cards/card.php?g=CODE; the register accepts the code or that link.
 *
 *   Register:  bcrf_gc_sale_check(), bcrf_gc_issue_for_sale(), bcrf_gc_redeem() (called from includes/sales.php)
 *   Voids:     bcrf_gc_void_blocker(), bcrf_gc_after_void()          (includes/refunds.php)
 *   Refunds:   bcrf_gc_refund_lines(), bcrf_gc_refund_credit()       (includes/refunds.php)
 *   Online:    bcrf_gc_online_create(), bcrf_gc_online_checkout(), bcrf_gc_online_mark_paid(), bcrf_gc_online_check_stripe()
 *   Admin:     bcrf_gc_list(), bcrf_gc_liability(), bcrf_gc_history(), bcrf_gc_adjust(), bcrf_gc_void(), bcrf_gc_issue_manual()
 */

if (!function_exists('bcrf_gift_cards_ready')) {

if (!class_exists('BcrfSaleError')) { class BcrfSaleError extends RuntimeException {} }
class BcrfGiftCardError extends RuntimeException {}   // message staff or buyers can read

define('BCRF_GC_POS_MIN_CENTS', 500);       // $5 a card on the register
define('BCRF_GC_ONLINE_MIN_CENTS', 1000);   // $10 online
define('BCRF_GC_MAX_CENTS', 50000);         // $500
define('BCRF_GC_ONLINE_PRESETS', [2500, 5000, 10000]);

function bcrf_gift_cards_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('gift_cards','gift_card_txns')")->fetchColumn();
        $c = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'gift_card_id'")->fetchColumn();
        return $ready = ($n === 2 && $c === 1);
    } catch (Throwable $e) { return $ready = false; }
}

// -- Codes --

function bcrf_gc_code(): string {
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 16; $i++) $s .= $abc[random_int(0, 31)];
    return $s;
}
function bcrf_gc_fmt(string $c): string { return implode('-', str_split($c, 4)); }
function bcrf_gc_last4(string $c): string { return substr($c, -4); }
function bcrf_gc_masked(string $c): string { return '····' . bcrf_gc_last4($c); }
/** Typed or scanned code → 16 characters, or '' if it can't be one. Accepts the card link (…?g=CODE). */
function bcrf_gc_norm(string $s): string {
    $s = trim($s);
    if (preg_match('/[?&]g=([A-Za-z0-9-]+)/', $s, $m)) $s = $m[1];
    $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s));
    return preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ2-9]{16}$/', $s) ? $s : '';
}
function bcrf_gc_url(string $code): string {
    if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/farm.php';
    return bcrf_farm_url('/gift-cards/card.php?g=' . rawurlencode($code));
}
function bcrf_gc_money(int $c): string { return ($c < 0 ? '-$' : '$') . number_format(abs($c) / 100, 2); }

// -- Reading --

function bcrf_gc_by_code(PDO $pdo, string $code, bool $lock = false): ?array {
    $code = bcrf_gc_norm($code);
    if ($code === '') return null;
    $st = $pdo->prepare("SELECT * FROM gift_cards WHERE code = ?" . ($lock ? " FOR UPDATE" : ""));
    $st->execute([$code]);
    return bcrf_gc_row($st->fetch(PDO::FETCH_ASSOC) ?: null);
}
function bcrf_gc_get(PDO $pdo, int $id, bool $lock = false): ?array {
    $st = $pdo->prepare("SELECT * FROM gift_cards WHERE id = ?" . ($lock ? " FOR UPDATE" : ""));
    $st->execute([$id]);
    return bcrf_gc_row($st->fetch(PDO::FETCH_ASSOC) ?: null);
}
function bcrf_gc_row(?array $r): ?array {
    if (!$r) return null;
    foreach (['id', 'initial_cents', 'balance_cents'] as $k) $r[$k] = (int)$r[$k];
    $r['code_fmt'] = bcrf_gc_fmt($r['code']);
    $r['last4'] = bcrf_gc_last4($r['code']);
    return $r;
}

// -- The ledger --

/** Add one ledger row and move the balance. The card must already be locked (FOR UPDATE) inside a transaction. */
function bcrf_gc_post(PDO $pdo, array $card, string $type, int $amount, array $ref = [], ?string $note = null, ?int $uid = null): int {
    $st = $pdo->prepare("SELECT balance_cents FROM gift_cards WHERE id = ?");
    $st->execute([$card['id']]);
    $bal = (int)$st->fetchColumn() + $amount;
    if ($bal < 0) throw new BcrfGiftCardError('That would take the gift card below $0.00.');
    $pdo->prepare("INSERT INTO gift_card_txns (gift_card_id, type, amount_cents, balance_after_cents, sale_id, refund_id, payment_id, note, created_at, created_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(3), ?)")
        ->execute([$card['id'], $type, $amount, $bal, $ref['sale_id'] ?? null, $ref['refund_id'] ?? null, $ref['payment_id'] ?? null,
                   $note !== null ? mb_substr($note, 0, 255) : null, $uid]);
    $pdo->prepare("UPDATE gift_cards SET balance_cents = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$bal, $card['id']]);
    return $bal;
}

/** Insert a card with a fresh code. $f: source, initial_cents, status, and any optional column. Returns the id. */
function bcrf_gc_insert(PDO $pdo, array $f): int {
    $cols = ['source', 'initial_cents', 'status', 'sale_id', 'sale_line_id', 'purchaser_name', 'purchaser_email', 'recipient_name',
             'recipient_email', 'message', 'note', 'created_by'];
    $vals = array_map(fn($c) => $f[$c] ?? null, $cols);
    for ($try = 0; $try < 5; $try++) {
        try {
            $pdo->prepare("INSERT INTO gift_cards (code, " . implode(', ', $cols) . ", balance_cents, created_at)
                           VALUES (?, " . implode(', ', array_fill(0, count($cols), '?')) . ", 0, UTC_TIMESTAMP())")
                ->execute(array_merge([bcrf_gc_code()], $vals));
            return (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000' || !str_contains($e->getMessage(), 'uq_code') || $try === 4) throw $e;   // code clash (1 in 10^24): try another
        }
    }
    throw new RuntimeException('Could not make a gift card code.');
}

// -- Register: selling and spending --

/** Rules for a sale that sells or spends gift cards. $lines = priced lines (bcrf_sale_price), $v = validated input. Throws BcrfSaleError. */
function bcrf_gc_sale_check(array $lines, array $v): void {
    $selling = false;
    foreach ($lines as $l) {
        if (($l['product_type'] ?? '') !== 'gift_card') continue;
        $selling = true;
        $q = (float)$l['quantity'];
        if ($q != floor($q) || $q < 1 || $q > 20) throw new BcrfSaleError('Gift cards are sold in whole numbers (1 to 20 at a time).');
        $amt = (int)$l['unit_price_cents'];
        if ($amt < BCRF_GC_POS_MIN_CENTS || $amt > BCRF_GC_MAX_CENTS) {
            throw new BcrfSaleError(sprintf('A gift card can be %s to %s. Change the amount on the gift card line.', bcrf_gc_money(BCRF_GC_POS_MIN_CENTS), bcrf_gc_money(BCRF_GC_MAX_CENTS)));
        }
        if ((int)$l['discount_cents'] !== 0) throw new BcrfSaleError('Gift cards can\'t be discounted. Remove the discount, or ring the gift card up on its own.');
        if ((int)$l['tax_cents'] !== 0) throw new BcrfSaleError('The Gift Card product is being taxed. Set its tax category to Exempt in Products, then try again.');
    }
    if ($selling) {
        foreach ($v['payments'] as $p) if ($p['tender'] === 'gift_card') throw new BcrfSaleError('Gift cards can\'t be bought with a gift card. Use another payment type for the gift card.');
    }
}

/** Issue one card per unit on a gift card sale line (inside the sale's transaction). Returns the new card ids. */
function bcrf_gc_issue_for_sale(PDO $pdo, int $saleId, int $saleLineId, array $line, ?int $uid, ?int $customerId = null): array {
    $ids = [];
    $email = null; $name = null;
    if ($customerId) {
        try {
            $st = $pdo->prepare("SELECT TRIM(CONCAT(first_name, ' ', last_name)) AS n, email FROM customers WHERE id = ?");
            $st->execute([$customerId]);
            if ($c = $st->fetch(PDO::FETCH_ASSOC)) { $name = $c['n'] ?: null; $email = $c['email'] ?: null; }
        } catch (Throwable $e) {}
    }
    for ($i = 0; $i < (int)$line['quantity']; $i++) {
        $id = bcrf_gc_insert($pdo, ['source' => 'pos', 'initial_cents' => (int)$line['unit_price_cents'], 'status' => 'active', 'sale_id' => $saleId,
                                    'sale_line_id' => $saleLineId, 'purchaser_name' => $name, 'purchaser_email' => $email, 'created_by' => $uid]);
        bcrf_gc_post($pdo, bcrf_gc_get($pdo, $id, true), 'issue', (int)$line['unit_price_cents'], ['sale_id' => $saleId], null, $uid);
        $ids[] = $id;
    }
    return $ids;
}

/** Spend from a card as a payment (inside the sale's transaction). Returns the card. Throws BcrfSaleError. */
function bcrf_gc_redeem(PDO $pdo, string $code, int $cents, int $saleId, int $paymentId, ?int $uid): array {
    $card = bcrf_gc_by_code($pdo, $code, true);
    if (!$card) throw new BcrfSaleError('Gift card not found. Check the code and try again.');
    if ($card['status'] === 'pending') throw new BcrfSaleError('Gift card ' . bcrf_gc_masked($card['code']) . ' was ordered online but hasn\'t been paid for yet.');
    if ($card['status'] !== 'active') throw new BcrfSaleError('Gift card ' . bcrf_gc_masked($card['code']) . ' was cancelled and can\'t be used.');
    if ($card['balance_cents'] < $cents) {
        throw new BcrfSaleError(sprintf('Gift card %s only has %s left. Take %s from it and the rest another way.', bcrf_gc_masked($card['code']),
            bcrf_gc_money($card['balance_cents']), bcrf_gc_money($card['balance_cents'])));
    }
    bcrf_gc_post($pdo, $card, 'redeem', -$cents, ['sale_id' => $saleId, 'payment_id' => $paymentId], null, $uid);
    $pdo->prepare("UPDATE payments SET gift_card_id = ?, reference = ? WHERE id = ?")->execute([$card['id'], $card['last4'], $paymentId]);
    return $card;
}

/** Register lookup before adding the payment: code → balance (staff only). */
function bcrf_gc_lookup(PDO $pdo, string $code): array {
    $card = bcrf_gc_by_code($pdo, $code);
    if (!$card) throw new BcrfGiftCardError('Gift card not found. Check the code (16 letters and numbers).');
    $msg = null;
    if ($card['status'] === 'pending') $msg = 'Ordered online, not paid yet.';
    elseif ($card['status'] === 'void') $msg = 'Cancelled' . ($card['void_reason'] ? ': ' . $card['void_reason'] : '') . '.';
    elseif ($card['balance_cents'] <= 0) $msg = 'Nothing left on this card.';
    return ['code' => $card['code'], 'masked' => bcrf_gc_masked($card['code']), 'balance_cents' => $card['balance_cents'],
            'status' => $card['status'], 'usable' => $msg === null, 'message' => $msg];
}

/** Cards sold on a sale and cards that paid for it, for the receipt. */
function bcrf_gc_for_sale(PDO $pdo, int $saleId): array {
    $st = $pdo->prepare("SELECT id, code, initial_cents, balance_cents, status, sale_line_id FROM gift_cards WHERE sale_id = ? ORDER BY id");
    $st->execute([$saleId]);
    $sold = array_map(fn($r) => ['id' => (int)$r['id'], 'code' => $r['code'], 'code_fmt' => bcrf_gc_fmt($r['code']), 'initial_cents' => (int)$r['initial_cents'],
                                 'balance_cents' => (int)$r['balance_cents'], 'status' => $r['status']], $st->fetchAll(PDO::FETCH_ASSOC));
    $st = $pdo->prepare("SELECT p.id AS payment_id, g.code, t.balance_after_cents FROM payments p JOIN gift_cards g ON g.id = p.gift_card_id
                         LEFT JOIN gift_card_txns t ON t.payment_id = p.id AND t.type = 'redeem' WHERE p.sale_id = ? ORDER BY p.id");
    $st->execute([$saleId]);
    $used = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $used[(int)$r['payment_id']] = ['masked' => bcrf_gc_masked($r['code']), 'balance_after_cents' => (int)$r['balance_after_cents']];
    return ['sold' => $sold, 'used' => $used];
}

// -- Voids and refunds --

/** A void must be able to take back every card it sold: refuse if one has been spent or cancelled since. */
function bcrf_gc_void_blocker(PDO $pdo, int $saleId): ?string {
    if (!bcrf_gift_cards_ready($pdo)) return null;
    $st = $pdo->prepare("SELECT code FROM gift_cards WHERE sale_id = ? AND (status <> 'active' OR balance_cents <> initial_cents) LIMIT 1");
    $st->execute([$saleId]);
    $c = $st->fetchColumn();
    return $c ? 'Gift card ' . bcrf_gc_masked((string)$c) . ' sold on this sale has been used, so the sale can\'t be voided. Refund what\'s left instead.' : null;
}

/** Void (inside its transaction): money taken from cards goes back on them; cards sold on the sale are cancelled. */
function bcrf_gc_after_void(PDO $pdo, int $saleId, string $saleNumber, ?int $uid): void {
    if (!bcrf_gift_cards_ready($pdo)) return;
    $st = $pdo->prepare("SELECT id, gift_card_id, amount_cents FROM payments WHERE sale_id = ? AND tender = 'gift_card' AND gift_card_id IS NOT NULL ORDER BY id");
    $st->execute([$saleId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $card = bcrf_gc_get($pdo, (int)$p['gift_card_id'], true);
        bcrf_gc_post($pdo, $card, 'reverse', (int)$p['amount_cents'], ['sale_id' => $saleId, 'payment_id' => (int)$p['id']], 'Sale ' . $saleNumber . ' voided', $uid);
    }
    $st = $pdo->prepare("SELECT id FROM gift_cards WHERE sale_id = ? ORDER BY id");
    $st->execute([$saleId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $card = bcrf_gc_get($pdo, (int)$id, true);
        if ($card['status'] !== 'active' || $card['balance_cents'] !== $card['initial_cents']) {
            throw new BcrfSaleError('Gift card ' . bcrf_gc_masked($card['code']) . ' sold on this sale has been used, so the sale can\'t be voided.');
        }
        bcrf_gc_post($pdo, $card, 'void', -$card['balance_cents'], ['sale_id' => $saleId], 'Sale ' . $saleNumber . ' voided', $uid);
        $pdo->prepare("UPDATE gift_cards SET status = 'void', voided_at = UTC_TIMESTAMP(), voided_by = ?, void_reason = ? WHERE id = ?")
            ->execute([$uid, 'Sale ' . $saleNumber . ' voided', $card['id']]);
    }
}

/**
 * Refunding gift card lines (inside the refund's transaction): each unit refunded cancels one unused card from that line
 * (newest first). $refundLines: [sale_line_id, quantity, product_type]. Throws BcrfSaleError if not enough unused cards.
 */
function bcrf_gc_refund_lines(PDO $pdo, int $saleId, int $refundId, string $refundNumber, array $refundLines, ?int $uid): void {
    foreach ($refundLines as $l) {
        if (($l['product_type'] ?? '') !== 'gift_card') continue;
        $q = (float)$l['quantity'];
        if ($q != floor($q)) throw new BcrfSaleError('Gift cards are refunded whole: choose 1, 2, …');
        $st = $pdo->prepare("SELECT id FROM gift_cards WHERE sale_line_id = ? AND status = 'active' AND balance_cents = initial_cents ORDER BY id DESC LIMIT " . (int)$q . " FOR UPDATE");
        $st->execute([(int)$l['sale_line_id']]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) < $q) {
            throw new BcrfSaleError('Only ' . count($ids) . ' of the gift cards on this line are unused, and only unused gift cards can be refunded. '
                . 'For a card that has been spent from, an Owner or Manager can lower its balance in Gift Cards and refund that amount as a custom refund.');
        }
        foreach ($ids as $id) {
            $card = bcrf_gc_get($pdo, (int)$id, true);
            bcrf_gc_post($pdo, $card, 'refund_void', -$card['balance_cents'], ['sale_id' => $saleId, 'refund_id' => $refundId], 'Refund ' . $refundNumber, $uid);
            $pdo->prepare("UPDATE gift_cards SET status = 'void', voided_at = UTC_TIMESTAMP(), voided_by = ?, void_reason = ? WHERE id = ?")
                ->execute([$uid, 'Refunded (' . $refundNumber . ')', $card['id']]);
        }
    }
}

/**
 * Money going back by gift card (inside the refund's transaction): onto the card(s) that paid the sale, first payment first,
 * never more than each paid minus what has already gone back to it. Returns [[gift_card_id, cents, last4], ...].
 */
function bcrf_gc_refund_credit(PDO $pdo, int $saleId, int $refundId, string $refundNumber, int $cents, ?int $uid): array {
    $st = $pdo->prepare("SELECT p.id, p.gift_card_id, p.amount_cents,
                                (SELECT COALESCE(SUM(rp.amount_cents), 0) FROM refund_payments rp JOIN refunds r ON r.id = rp.refund_id
                                  WHERE r.sale_id = p.sale_id AND rp.tender = 'gift_card' AND rp.gift_card_id = p.gift_card_id) AS back
                         FROM payments p WHERE p.sale_id = ? AND p.tender = 'gift_card' AND p.status = 'completed' AND p.gift_card_id IS NOT NULL ORDER BY p.id");
    $st->execute([$saleId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    // A card that paid twice on one sale: count what went back to it once, against its total.
    $room = [];
    foreach ($rows as $r) $room[(int)$r['gift_card_id']] = ($room[(int)$r['gift_card_id']] ?? -((int)$r['back'])) + (int)$r['amount_cents'];
    $out = [];
    $left = $cents;
    foreach ($room as $cardId => $max) {
        if ($left <= 0) break;
        $give = min($left, max(0, $max));
        if ($give <= 0) continue;
        $card = bcrf_gc_get($pdo, $cardId, true);
        if ($card['status'] !== 'active') continue;   // a cancelled card can't take money back: the refund must go another way
        bcrf_gc_post($pdo, $card, 'refund_credit', $give, ['sale_id' => $saleId, 'refund_id' => $refundId], 'Refund ' . $refundNumber, $uid);
        $out[] = [$cardId, $give, $card['last4']];
        $left -= $give;
    }
    if ($left > 0) throw new BcrfSaleError(sprintf('Only %s can go back onto the gift card that paid. Give the rest back another way.', bcrf_gc_money($cents - $left)));
    return $out;
}

// -- Admin --

function bcrf_gc_is_manager(): bool { return in_array((string)($_SESSION['user_role'] ?? ''), ['administrator', 'moderator'], true); }

/** Cards for the admin list. $f: q (code, last 4, name or email), status, source. */
function bcrf_gc_list(PDO $pdo, array $f = [], int $limit = 200): array {
    $w = []; $a = [];
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
        $code = bcrf_gc_norm($q);
        if ($code !== '') { $w[] = 'code = ?'; $a[] = $code; }
        else {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $w[] = '(code LIKE ? OR purchaser_name LIKE ? OR purchaser_email LIKE ? OR recipient_name LIKE ? OR recipient_email LIKE ?)';
            array_push($a, '%' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $q)) . '%', $like, $like, $like, $like);
        }
    }
    if (in_array($f['status'] ?? '', ['active', 'pending', 'void'], true)) { $w[] = 'status = ?'; $a[] = $f['status']; }
    if (($f['status'] ?? '') === 'open') $w[] = "status = 'active' AND balance_cents > 0";
    if (in_array($f['source'] ?? '', ['pos', 'online', 'manual'], true)) { $w[] = 'source = ?'; $a[] = $f['source']; }
    $st = $pdo->prepare("SELECT * FROM gift_cards" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY id DESC LIMIT " . (int)$limit);
    $st->execute($a);
    return array_map('bcrf_gc_row', $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Outstanding balances (05/13 gift card liability). */
function bcrf_gc_liability(PDO $pdo): array {
    $r = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(balance_cents), 0) c FROM gift_cards WHERE status = 'active' AND balance_cents > 0")->fetch(PDO::FETCH_ASSOC);
    $s = $pdo->query("SELECT source, COUNT(*) n, COALESCE(SUM(initial_cents), 0) c FROM gift_cards WHERE status <> 'pending' GROUP BY source")->fetchAll(PDO::FETCH_ASSOC);
    return ['cards' => (int)$r['n'], 'cents' => (int)$r['c'], 'issued_by_source' => array_column(array_map(fn($x) => [$x['source'], ['n' => (int)$x['n'], 'cents' => (int)$x['c']]], $s), 1, 0)];
}

function bcrf_gc_history(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT t.*, s.sale_number, r.refund_number FROM gift_card_txns t
                         LEFT JOIN sales s ON s.id = t.sale_id LEFT JOIN refunds r ON r.id = t.refund_id
                         WHERE t.gift_card_id = ? ORDER BY t.id");
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_gc_type_label(string $t): string {
    return ['issue' => 'Issued', 'redeem' => 'Spent', 'refund_credit' => 'Refund put back', 'refund_void' => 'Refunded (cancelled)',
            'void' => 'Cancelled', 'adjust' => 'Adjusted', 'reverse' => 'Sale voided, put back'][$t] ?? $t;
}

function bcrf_gc_audit(PDO $pdo, string $action, array $card, string $label, ?string $actor = null): void {
    if (!function_exists('bcrf_audit_event') || !function_exists('bcrf_audit_actor')) return;
    [$t, $aid, $name, $ip] = bcrf_audit_actor($actor);
    bcrf_audit_event($pdo, ['actor_type' => $t, 'actor_id' => $aid, 'actor_label' => $name, 'ip' => $ip, 'action' => $action,
        'object_type' => 'gift_cards', 'object_id' => (string)$card['id'], 'object_label' => 'Gift card ' . bcrf_gc_masked($card['code']) . ' - ' . $label]);
}

/** Owner or Manager: change a balance by +/- cents with a reason. */
function bcrf_gc_adjust(PDO $pdo, int $id, int $cents, string $reason, ?int $uid): array {
    if (!bcrf_gc_is_manager()) throw new BcrfGiftCardError('Only an Owner or Manager can change a gift card balance.');
    $reason = mb_substr(trim($reason), 0, 255);
    if ($reason === '') throw new BcrfGiftCardError('Give a reason for the change.');
    if ($cents === 0 || abs($cents) > BCRF_GC_MAX_CENTS) throw new BcrfGiftCardError('Enter an amount to add or take off (up to ' . bcrf_gc_money(BCRF_GC_MAX_CENTS) . ').');
    $pdo->beginTransaction();
    try {
        $card = bcrf_gc_get($pdo, $id, true);
        if (!$card) throw new BcrfGiftCardError('Gift card not found.');
        if ($card['status'] !== 'active') throw new BcrfGiftCardError('Only active gift cards can be changed.');
        $bal = bcrf_gc_post($pdo, $card, 'adjust', $cents, [], $reason, $uid);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    bcrf_gc_audit($pdo, 'update', $card, sprintf('balance %s %s → %s: %s', $cents > 0 ? '+' : '-', bcrf_gc_money(abs($cents)), bcrf_gc_money($bal), $reason));
    return bcrf_gc_get($pdo, $id);
}

/** Owner or Manager: cancel a card (lost and replaced, fraud). What's left on it goes to $0. */
function bcrf_gc_void(PDO $pdo, int $id, string $reason, ?int $uid): array {
    if (!bcrf_gc_is_manager()) throw new BcrfGiftCardError('Only an Owner or Manager can cancel a gift card.');
    $reason = mb_substr(trim($reason), 0, 255);
    if ($reason === '') throw new BcrfGiftCardError('Give a reason for cancelling the card.');
    $pdo->beginTransaction();
    try {
        $card = bcrf_gc_get($pdo, $id, true);
        if (!$card) throw new BcrfGiftCardError('Gift card not found.');
        if ($card['status'] === 'void') { $pdo->rollBack(); return $card; }
        if ($card['balance_cents'] > 0) bcrf_gc_post($pdo, $card, 'void', -$card['balance_cents'], [], $reason, $uid);
        $pdo->prepare("UPDATE gift_cards SET status = 'void', voided_at = UTC_TIMESTAMP(), voided_by = ?, void_reason = ? WHERE id = ?")->execute([$uid, $reason, $id]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    bcrf_gc_audit($pdo, 'update', $card, 'cancelled with ' . bcrf_gc_money($card['balance_cents']) . ' left: ' . $reason);
    return bcrf_gc_get($pdo, $id);
}

/** Owner or Manager: give a card without a sale (raffle prize, apology, replacement). */
function bcrf_gc_issue_manual(PDO $pdo, int $cents, string $reason, array $to, ?int $uid): array {
    if (!bcrf_gc_is_manager()) throw new BcrfGiftCardError('Only an Owner or Manager can give a gift card without a sale.');
    $reason = mb_substr(trim($reason), 0, 255);
    if ($reason === '') throw new BcrfGiftCardError('Say why the card is being given (e.g. raffle prize, replacement for ····AB12).');
    if ($cents < 100 || $cents > BCRF_GC_MAX_CENTS) throw new BcrfGiftCardError('A gift card can be $1.00 to ' . bcrf_gc_money(BCRF_GC_MAX_CENTS) . '.');
    $name = mb_substr(trim((string)($to['name'] ?? '')), 0, 120) ?: null;
    $email = trim((string)($to['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new BcrfGiftCardError('Check the email address.');
    $pdo->beginTransaction();
    try {
        $id = bcrf_gc_insert($pdo, ['source' => 'manual', 'initial_cents' => $cents, 'status' => 'active', 'recipient_name' => $name,
                                    'recipient_email' => $email !== '' ? strtolower($email) : null, 'note' => $reason, 'created_by' => $uid]);
        $card = bcrf_gc_get($pdo, $id, true);
        bcrf_gc_post($pdo, $card, 'issue', $cents, [], $reason, $uid);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    bcrf_gc_audit($pdo, 'create', $card, 'given without a sale, ' . bcrf_gc_money($cents) . ': ' . $reason);
    return bcrf_gc_get($pdo, $id);
}

// -- Email --

/** Email the card to the recipient (or the buyer), with the code, amount, message and the card link. Returns the address used. */
function bcrf_gc_email(PDO $pdo, array $card, ?string $to = null): string {
    if ($card['status'] !== 'active') throw new BcrfGiftCardError('Only active gift cards can be emailed.');
    $to = trim((string)($to ?? ($card['recipient_email'] ?: $card['purchaser_email'])));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || strlen($to) > 190) throw new BcrfGiftCardError('Check the email address.');
    // At most 5 sends per card per hour.
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM audit_events WHERE action = 'gift_card_email' AND object_type = 'gift_cards' AND object_id = ? AND occurred_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR");
        $st->execute([(string)$card['id']]);
        if ((int)$st->fetchColumn() >= 5) throw new BcrfGiftCardError('This gift card was emailed 5 times in the last hour. Try again later.');
    } catch (BcrfGiftCardError $e) { throw $e; } catch (Throwable $e) {}
    if (!function_exists('bcf_send_mail')) require_once dirname(__DIR__) . '/admin/includes/email_template.php';
    if (!function_exists('bcrf_farm_name')) require_once __DIR__ . '/farm.php';
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $farm = bcrf_farm_name();
    $from = $card['purchaser_name'] && $to !== $card['purchaser_email'] ? $card['purchaser_name'] : null;
    $hi = $card['recipient_name'] ?: null;
    $inner = '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:1.3rem;margin:0 0 14px">' . ($from ? $h($from) . ' sent you a gift card 🎁' : 'Your gift card 🎁') . '</h1>'
        . ($hi ? '<p style="margin:0 0 12px">Hi ' . $h($hi) . ',</p>' : '')
        . '<p style="margin:0 0 12px">Here is a <strong>' . $h(bcrf_gc_money($card['initial_cents'])) . ' ' . $h($farm) . ' gift card</strong>'
        . ($card['balance_cents'] !== $card['initial_cents'] ? ' with <strong>' . $h(bcrf_gc_money($card['balance_cents'])) . '</strong> left on it' : '') . '.</p>'
        . ($card['message'] ? '<p style="margin:0 0 12px;padding:10px 14px;border-left:3px solid #c9923a;font-style:italic">' . nl2br($h($card['message'])) . '</p>' : '')
        . '<p style="margin:0 0 12px;font-size:1.15rem;letter-spacing:.06em"><strong>' . $h(bcrf_gc_fmt($card['code'])) . '</strong></p>'
        . '<p style="margin:0 0 18px;text-align:center"><a href="' . $h(bcrf_gc_url($card['code'])) . '" style="display:inline-block;background:#c9923a;color:#3b2010;font-family:Lora,Georgia,serif;font-weight:bold;text-decoration:none;padding:13px 28px;border-radius:8px">Show my gift card →</a></p>'
        . '<p style="font-size:.9rem;margin:0 0 8px">Show the card (or its QR code) at the farm stand, gate or thrift shop. It works on anything we sell at the farm, it never expires, and whatever you don\'t spend stays on the card for next time.</p>'
        . '<p style="font-size:.85rem;color:rgba(245,234,214,.8);margin:0">Treat the code like cash: anyone who has it can spend it. Gift cards can\'t be exchanged for cash.</p>';
    $subject = $from ? $from . ' sent you a ' . $farm . ' gift card' : 'Your ' . $farm . ' gift card';
    if (!bcf_send_mail($to, $subject, $inner, ['subtitle' => 'Gift Card', 'reply_to' => function_exists('bcrf_farm_email') ? bcrf_farm_email('support') : null])) {
        error_log('[gift cards] email failed for card ' . $card['id'] . ': ' . ($GLOBALS['bcf_mail_last_error'] ?? ''));
        throw new BcrfGiftCardError('The email could not be sent. Check the address, or print the card instead.');
    }
    $pdo->prepare("UPDATE gift_cards SET sent_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$card['id']]);
    bcrf_gc_audit($pdo, 'gift_card_email', $card, 'emailed to ' . $to);
    return $to;
}

// -- Online: buying a card on the website --

/** Validate the buy form and save a pending card. Throws BcrfGiftCardError with a message for the buyer. */
function bcrf_gc_online_create(PDO $pdo, array $post): array {
    $amt = (string)($post['amount'] ?? '');
    $cents = $amt === 'other' ? (int)round(((float)preg_replace('/[^0-9.]/', '', (string)($post['amount_other'] ?? ''))) * 100) : (int)$amt;
    if ($amt !== 'other' && !in_array($cents, BCRF_GC_ONLINE_PRESETS, true)) throw new BcrfGiftCardError('Choose an amount.');
    if ($cents < BCRF_GC_ONLINE_MIN_CENTS || $cents > BCRF_GC_MAX_CENTS) {
        throw new BcrfGiftCardError('Choose an amount from ' . bcrf_gc_money(BCRF_GC_ONLINE_MIN_CENTS) . ' to ' . bcrf_gc_money(BCRF_GC_MAX_CENTS) . '.');
    }
    if ($cents % 100 !== 0) throw new BcrfGiftCardError('Choose a whole-dollar amount.');
    $clean = fn($k, $n) => mb_substr(trim(strip_tags((string)($post[$k] ?? ''))), 0, $n);
    $name  = $clean('purchaser_name', 120);
    $email = filter_var(trim((string)($post['purchaser_email'] ?? '')), FILTER_VALIDATE_EMAIL);
    if ($name === '' || !$email) throw new BcrfGiftCardError('Fill in your name and email.');
    $self = ($post['send_to'] ?? 'them') === 'me';
    $rName = $self ? null : ($clean('recipient_name', 120) ?: null);
    $rEmail = null;
    if (!$self) {
        $rEmail = filter_var(trim((string)($post['recipient_email'] ?? '')), FILTER_VALIDATE_EMAIL);
        if (!$rEmail) throw new BcrfGiftCardError('Add the email address the gift card should go to, or choose "Send it to me".');
    }
    $msg = trim(str_replace("\r", '', strip_tags((string)($post['message'] ?? ''))));
    if (mb_strlen($msg) > 300) throw new BcrfGiftCardError('Keep the message under 300 characters.');
    // Light abuse guard: at most 10 unpaid orders from one email in an hour.
    $st = $pdo->prepare("SELECT COUNT(*) FROM gift_cards WHERE purchaser_email = ? AND status = 'pending' AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR");
    $st->execute([strtolower($email)]);
    if ((int)$st->fetchColumn() >= 10) throw new BcrfGiftCardError('Too many unfinished orders. Please try again later, or call us.');
    $id = bcrf_gc_insert($pdo, ['source' => 'online', 'initial_cents' => $cents, 'status' => 'pending', 'purchaser_name' => $name,
                                'purchaser_email' => strtolower($email), 'recipient_name' => $rName, 'recipient_email' => $rEmail ? strtolower($rEmail) : null,
                                'message' => $msg !== '' ? $msg : null]);
    if (function_exists('bcrf_event')) bcrf_event('gift_card.ordered', 'gift_cards', $id, ['amount_cents' => $cents]);
    return bcrf_gc_get($pdo, $id);
}

/** Order page address (the buyer's own page; holds the code, so it's never shown to anyone else). */
function bcrf_gc_order_url(array $card, string $extra = ''): string { return bcrf_gc_url($card['code']) . $extra; }

/** Stripe Checkout for a pending online card. Returns the session URL. */
function bcrf_gc_online_checkout(PDO $pdo, array $card): string {
    require_once __DIR__ . '/stripe.php';
    if ($card['status'] !== 'pending' || $card['source'] !== 'online') throw new RuntimeException('This gift card is not waiting for payment.');
    if (!function_exists('bcrf_farm_name')) require_once __DIR__ . '/farm.php';
    $s = stripe_api('POST', 'checkout/sessions', [
        'mode' => 'payment', 'customer_email' => $card['purchaser_email'],
        'success_url' => bcrf_gc_order_url($card, '&paid=1'),
        'cancel_url'  => bcrf_gc_order_url($card, '&cancelled=1'),
        'line_items[0][price_data][currency]' => 'usd',
        'line_items[0][price_data][unit_amount]' => $card['initial_cents'],
        'line_items[0][price_data][product_data][name]' => bcrf_farm_name() . ' gift card',
        'line_items[0][price_data][product_data][description]' => $card['recipient_email'] ? 'Emailed to ' . $card['recipient_email'] : 'Emailed to you',
        'line_items[0][quantity]' => 1,
        'metadata[gift_card_id]' => (string)$card['id'],
        'payment_intent_data[metadata][gift_card_id]' => (string)$card['id'],
        'payment_intent_data[description]' => 'Gift card ' . bcrf_gc_masked($card['code']),
    ]);
    if (empty($s['url'])) throw new RuntimeException('Stripe did not return a checkout link.');
    $pdo->prepare("UPDATE gift_cards SET stripe_session_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$s['id'], $card['id']]);
    return (string)$s['url'];
}

/** Payment confirmed (webhook or the card page). Returns true only for the call that activated it. Never activates for a short payment. */
function bcrf_gc_online_mark_paid(PDO $pdo, int $id, string $sessionId, ?int $amountCents = null): bool {
    $pdo->beginTransaction();
    try {
        $card = bcrf_gc_get($pdo, $id, true);
        if (!$card || $card['status'] !== 'pending' || $card['source'] !== 'online') { $pdo->rollBack(); return false; }
        if ($amountCents !== null && $amountCents < $card['initial_cents']) {
            $pdo->rollBack();
            error_log('[gift cards] ACTION REQUIRED: card ' . $id . ' paid ' . $amountCents . ' of ' . $card['initial_cents'] . ' cents; not activated.');
            return false;
        }
        $pdo->prepare("UPDATE gift_cards SET status = 'active', paid_at = UTC_TIMESTAMP(), stripe_session_id = COALESCE(NULLIF(?, ''), stripe_session_id) WHERE id = ?")
            ->execute([$sessionId, $id]);
        bcrf_gc_post($pdo, $card, 'issue', $card['initial_cents'], [], 'Paid online', null);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    $card = bcrf_gc_get($pdo, $id);
    if (function_exists('bcrf_event')) bcrf_event('gift_card.paid', 'gift_cards', $id, ['amount_cents' => $card['initial_cents']]);
    bcrf_gc_audit($pdo, 'create', $card, 'bought online by ' . $card['purchaser_name'] . ', ' . bcrf_gc_money($card['initial_cents']), 'Stripe (automatic)');
    bcrf_gc_online_send($pdo, $card);
    return true;
}

/** After payment: the card to the recipient (or buyer), and a short receipt to the buyer when it went to someone else. Never throws. */
function bcrf_gc_online_send(PDO $pdo, array $card): void {
    try { bcrf_gc_email($pdo, $card); }
    catch (Throwable $e) {
        error_log('[gift cards] ACTION REQUIRED: card ' . $card['id'] . ' paid but the email failed: ' . $e->getMessage());
        if (function_exists('bcrf_queue_push')) {
            bcrf_queue_push('email.send', ['to' => function_exists('bcrf_farm_email') ? bcrf_farm_email('support') : '',
                'subject' => 'ACTION REQUIRED: gift card email failed (' . bcrf_gc_masked($card['code']) . ')',
                'html' => '<p>A gift card was paid for online but the email to the recipient failed. Open Gift Cards in the console, find ····'
                    . htmlspecialchars($card['last4']) . ' and press Email again.</p>', 'opts' => ['subtitle' => 'Gift cards']],
                ['dedupe' => 'gc-email-fail:' . $card['id']]);
        }
    }
    if ($card['recipient_email'] && $card['recipient_email'] !== $card['purchaser_email']) {
        try {
            if (!function_exists('bcf_send_mail')) require_once dirname(__DIR__) . '/admin/includes/email_template.php';
            $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
            $inner = '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:1.3rem;margin:0 0 14px">Thanks for your gift card order</h1>'
                . '<p style="margin:0 0 12px">Hi ' . $h($card['purchaser_name']) . ', your ' . $h(bcrf_gc_money($card['initial_cents'])) . ' gift card was emailed to <strong>'
                . $h($card['recipient_email']) . '</strong>' . ($card['recipient_name'] ? ' for ' . $h($card['recipient_name']) : '') . '.</p>'
                . '<p style="margin:0 0 12px">Card ending ' . $h($card['last4']) . '. If it doesn\'t arrive, ask them to check their spam folder, or reply to this email and we\'ll resend it.</p>';
            bcf_send_mail($card['purchaser_email'], 'Your gift card order', $inner, ['subtitle' => 'Gift Card', 'reply_to' => function_exists('bcrf_farm_email') ? bcrf_farm_email('support') : null]);
        } catch (Throwable $e) { error_log('[gift cards] buyer receipt ' . $card['id'] . ': ' . $e->getMessage()); }
    }
}

/** Card page: still pending with a Stripe session? Ask Stripe (the webhook may be a few seconds behind). */
function bcrf_gc_online_check_stripe(PDO $pdo, array $card): array {
    if ($card['status'] !== 'pending' || empty($card['stripe_session_id'])) return $card;
    try {
        require_once __DIR__ . '/stripe.php';
        $s = stripe_retrieve_session((string)$card['stripe_session_id']);
        if (in_array($s['payment_status'] ?? '', ['paid', 'no_payment_required'], true) && (int)($s['metadata']['gift_card_id'] ?? 0) === (int)$card['id']) {
            bcrf_gc_online_mark_paid($pdo, (int)$card['id'], (string)$s['id'], isset($s['amount_total']) ? (int)$s['amount_total'] : null);
            return bcrf_gc_get($pdo, (int)$card['id']);
        }
    } catch (Throwable $e) { error_log('[gift cards] stripe check ' . $card['id'] . ': ' . $e->getMessage()); }
    return $card;
}

}
