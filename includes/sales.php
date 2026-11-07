<?php // includes/sales.php
/*
 * Farm OS Phase 1, slice 1 (Nov 6, 2026): completing a sale. Tables: admin/migrations/0009_sales_core.php.
 *
 *   $r = bcrf_sale_complete($pdo, $input);   // throws BcrfSaleError with a message staff can read
 *   $r['replayed'] is true when the same idempotency key was already saved (double tap, retry after a
 *   dropped connection): nothing is written twice and the original sale comes back.
 *
 * The server decides every price, tax and total. The browser only says what is in the cart, the
 * discount, and how it was paid. Money is integer cents; tax is per line, rounded half-up (05 › Tax).
 * One transaction writes the sale, its lines and payments, the stock ledger, a domain event, and a
 * pos_receipts row so the existing Sales Log and dashboard keep working unchanged.
 * Slice 2 (Nov 6): once migration 0010 has run, every sale belongs to the open drawer of its register
 * (includes/drawers.php); with no open drawer the sale is refused.
 * Slice 4a (Nov 6): admission products sold here check the party in (includes/admission.php). If the farm is
 * full the sale is refused with BcrfCapacityError, unless an Owner or Manager gives capacity_override (a reason).
 * Slice 8 (Nov 6): once migration 0015 has run, stock comes off the register's location (includes/stock.php) and the
 * sale records that location, so refunds and voids put it back in the same place.
 * Slice 9 (Nov 6): discounts other than the preset % buttons need an Owner or Manager (includes/receipts.php).
 * Nov 6: payment types turned off in Settings › Payments & Register are refused for new sales (bcrf_tenders_active()).
 */
require_once __DIR__ . '/stock.php';

if (!function_exists('bcrf_sale_complete')) {

if (!class_exists('BcrfSaleError')) { class BcrfSaleError extends RuntimeException {} }

/** In-person tenders the register offers (D-06). Online card, gift card and store credit come later. */
function bcrf_tenders(): array {
    return ['cash' => 'Cash', 'paypal' => 'PayPal', 'venmo' => 'Venmo', 'cashapp' => 'Cash App', 'check' => 'Check'];
}
function bcrf_tender_is_p2p(string $t): bool { return in_array($t, ['paypal', 'venmo', 'cashapp'], true); }

/** True once migration 0009 has run (cached per request). */
function bcrf_sales_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
                               AND table_name IN ('sales','sale_lines','payments','tax_categories','number_sequences','inventory_adjustments')")->fetchColumn();
        $c = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
                               AND table_name = 'inventory' AND column_name = 'tax_category_id'")->fetchColumn();
        return $ready = ($n === 6 && $c === 1);
    } catch (Throwable $e) { return $ready = false; }
}

/** True once migration 0014 has run: item discounts can be stored (slice 7). Cached per request. */
function bcrf_sales_line_disc_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $ready = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
                                   AND table_name = 'sale_lines' AND column_name = 'line_discount_bp'")->fetchColumn() === 1;
    } catch (Throwable $e) { $ready = false; }
    return $ready;
}

/** Customer accounts table exists (migration 0006). */
function bcrf_customers_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try { $ready = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'customers'")->fetchColumn() === 1; }
    catch (Throwable $e) { $ready = false; }
    return $ready;
}

/** Active tax categories, id => row. */
function bcrf_tax_categories(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query("SELECT id, code, name, rate_bp, notes FROM tax_categories WHERE archived_at IS NULL ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['id'] = (int)$r['id']; $r['rate_bp'] = (int)$r['rate_bp'];
        $out[$r['id']] = $r;
    }
    return $out;
}

/** a × b ÷ d, rounded half-up, for non-negative integers. */
function bcrf_muldiv(int $a, int $b, int $d): int {
    if ($d <= 0) return 0;
    return intdiv(2 * $a * $b + $d, 2 * $d);
}

/** Farm-local "now": [DateTimeImmutable local, business date Y-m-d, year]. */
function bcrf_farm_now(): array {
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    $now = new DateTimeImmutable('now', $tz);
    return [$now, $now->format('Y-m-d'), (int)$now->format('Y')];
}

/** Next number in a yearly counter. Call inside a transaction (the row stays locked until commit). */
function bcrf_next_number(PDO $pdo, string $name, int $year): int {
    $pdo->prepare("INSERT IGNORE INTO number_sequences (name, period, next_value) VALUES (?, ?, 1)")->execute([$name, $year]);
    $st = $pdo->prepare("SELECT next_value FROM number_sequences WHERE name = ? AND period = ? FOR UPDATE");
    $st->execute([$name, $year]);
    $n = (int)$st->fetchColumn();
    $pdo->prepare("UPDATE number_sequences SET next_value = next_value + 1 WHERE name = ? AND period = ?")->execute([$name, $year]);
    return $n;
}

/**
 * Work out lines and totals from products (server prices) and the cart. Pure: no writes.
 * $products: inventory id => row with price, pricing_mode, unit, tax_category_id, product_type, cat_label, name.
 * Returns ['lines' => [...], 'subtotal' => c, 'discount' => c, 'tax' => c, 'total' => c].
 */
function bcrf_sale_price(array $cart, array $products, array $taxCats, int $discountBp, bool $taxExempt, ?int $customTaxId): array {
    $lines = [];
    foreach ($cart as $i => $c) {
        $qtyMilli = (int)round(((float)$c['qty']) * 1000);
        if ($c['product_id']) {
            $p = $products[$c['product_id']];
            $open  = ($p['pricing_mode'] ?? 'each') === 'open_price';
            $price = $open ? (int)$c['unit_price_cents'] : (int)round(((float)$p['price']) * 100);
            $taxId = $p['tax_category_id'] !== null ? (int)$p['tax_category_id'] : $customTaxId;
            $line  = ['product_id' => (int)$p['id'], 'description' => (string)$p['name'], 'unit' => (string)($p['unit'] ?? 'each'),
                      'product_type' => (string)($p['product_type'] ?? 'retail'), 'category_label' => (string)($p['cat_label'] ?? ''),
                      'track' => (int)($p['track_inventory'] ?? 1) === 1];
        } else {
            $price = (int)$c['unit_price_cents'];
            $taxId = $customTaxId;
            $line  = ['product_id' => null, 'description' => (string)$c['name'], 'unit' => 'each', 'product_type' => 'fee',
                      'category_label' => 'Custom', 'track' => false];
        }
        $gross = bcrf_muldiv($qtyMilli, $price, 1000);
        $lineBp = max(0, min(10000, (int)($c['disc_bp'] ?? 0)));
        $lines[] = $line + ['line_no' => $i + 1, 'quantity' => $qtyMilli / 1000, 'unit_price_cents' => $price, 'gross_cents' => $gross,
                            'line_discount_bp' => $lineBp, 'item_discount_cents' => bcrf_muldiv($gross, $lineBp, 10000),
                            'tax_category_id' => $taxId, 'tax_rate_bp' => $taxExempt ? 0 : (int)($taxCats[$taxId]['rate_bp'] ?? 0),
                            'note' => $c['note'] ?? null];
    }
    $subtotal = array_sum(array_column($lines, 'gross_cents'));
    // Item discounts first (slice 7); the receipt discount then applies to what's left.
    $itemDisc = array_sum(array_column($lines, 'item_discount_cents'));
    $base     = $subtotal - $itemDisc;
    $receipt  = bcrf_muldiv($base, $discountBp, 10000);
    $discount = $itemDisc + $receipt;

    // Spread the receipt discount across lines in proportion to their value after item discounts (largest remainder),
    // so tax is worked out on what each line actually costs and the pieces add up exactly.
    $alloc = []; $rem = []; $given = 0;
    foreach ($lines as $k => $l) {
        $after = $l['gross_cents'] - $l['item_discount_cents'];
        $exact = $base > 0 ? $after * $receipt : 0;
        $alloc[$k] = $base > 0 ? intdiv($exact, $base) : 0;
        $rem[$k]   = $base > 0 ? $exact % $base : 0;
        $given    += $alloc[$k];
    }
    arsort($rem);
    foreach (array_keys($rem) as $k) { if ($given >= $receipt) break; $alloc[$k]++; $given++; }

    $tax = 0;
    foreach ($lines as $k => &$l) {
        $l['discount_cents']   = $l['item_discount_cents'] + $alloc[$k];
        $net                   = $l['gross_cents'] - $l['discount_cents'];
        $l['tax_cents']        = bcrf_muldiv($net, $l['tax_rate_bp'], 10000);
        $l['line_total_cents'] = $net + $l['tax_cents'];
        $tax += $l['tax_cents'];
    }
    unset($l);
    return ['lines' => $lines, 'subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'total' => $subtotal - $discount + $tax];
}

/** Check and tidy what the browser sent. Throws BcrfSaleError. */
function bcrf_sale_validate(array $in): array {
    $key = (string)($in['idempotency_key'] ?? '');
    if (!preg_match('/^[A-Za-z0-9-]{16,64}$/', $key)) throw new BcrfSaleError('This sale is missing its save code. Reload the register and try again.');
    $cart = [];
    foreach ((array)($in['lines'] ?? []) as $l) {
        $qty = round((float)($l['qty'] ?? 0), 3);
        if ($qty <= 0 || $qty > 10000) throw new BcrfSaleError('Each item needs a quantity above zero.');
        $pid = (int)($l['product_id'] ?? 0);
        $row = ['product_id' => $pid > 0 ? $pid : null, 'qty' => $qty,
                'unit_price_cents' => max(0, min(10000000, (int)($l['unit_price_cents'] ?? 0))),
                'note' => mb_substr(trim((string)($l['note'] ?? '')), 0, 255) ?: null];
        $lp = (float)($l['disc_pct'] ?? 0);
        if ($lp < 0 || $lp > 100) throw new BcrfSaleError('An item discount must be between 0 and 100%.');
        $row['disc_bp'] = (int)round($lp * 100);
        if (!$row['product_id']) {
            $row['name'] = mb_substr(trim((string)($l['name'] ?? '')), 0, 120);
            if ($row['name'] === '') throw new BcrfSaleError('A custom item needs a name.');
        }
        $cart[] = $row;
    }
    if (!$cart) throw new BcrfSaleError('The cart is empty.');
    if (count($cart) > 200) throw new BcrfSaleError('That is more than 200 lines; split it into two sales.');

    $pays = [];
    $tenders = bcrf_tenders();
    foreach ((array)($in['payments'] ?? []) as $p) {
        $t = strtolower((string)($p['tender'] ?? ''));
        if (!isset($tenders[$t])) throw new BcrfSaleError('Unknown payment type.');
        $amt = (int)($p['amount_cents'] ?? 0);
        if ($amt <= 0) throw new BcrfSaleError('Each payment needs an amount above zero.');
        $row = ['tender' => $t, 'amount_cents' => $amt, 'tendered_cents' => null, 'change_cents' => 0,
                'payer_handle' => mb_substr(trim((string)($p['payer_handle'] ?? '')), 0, 120) ?: null,
                'reference'    => mb_substr(trim((string)($p['reference'] ?? '')), 0, 80) ?: null];
        if ($t === 'cash') {
            $tendered = (int)($p['tendered_cents'] ?? $amt);
            if ($tendered < $amt) throw new BcrfSaleError('Cash handed over is less than the amount applied.');
            $row['tendered_cents'] = $tendered;
            $row['change_cents']   = $tendered - $amt;
        }
        if (bcrf_tender_is_p2p($t) && !$row['payer_handle']) {
            throw new BcrfSaleError($tenders[$t] . ' payments need the payer\'s name or handle (for matching later).');
        }
        $pays[] = $row;
    }
    // No payments is fine only for a $0 sale (e.g. an Under-3 admission alone); checked once the total is known.
    if (count($pays) > 10) throw new BcrfSaleError('Too many payments on one sale.');

    $pct = (float)($in['discount_pct'] ?? 0);
    if ($pct < 0 || $pct > 100) throw new BcrfSaleError('Discount must be between 0 and 100%.');
    return ['key' => $key, 'cart' => $cart, 'payments' => $pays, 'discount_bp' => (int)round($pct * 100), 'register_id' => max(0, (int)($in['register_id'] ?? 0)), 'capacity_override' => mb_substr(trim((string)($in['capacity_override'] ?? '')), 0, 255),
            'tax_exempt' => !empty($in['tax_exempt']), 'printed' => !empty($in['printed']),
            'customer_id' => max(0, (int)($in['customer_id'] ?? 0)) ?: null];
}

/** One sale with its lines and payments, by id or idempotency key; null if not found. */
function bcrf_sale_get(PDO $pdo, ?int $id, ?string $key = null): ?array {
    $st = $id ? $pdo->prepare("SELECT * FROM sales WHERE id = ?") : $pdo->prepare("SELECT * FROM sales WHERE idempotency_key = ?");
    $st->execute([$id ?: $key]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) return null;
    $l = $pdo->prepare("SELECT line_no, product_id, description, quantity, unit, unit_price_cents, gross_cents, discount_cents,
                               tax_rate_bp, tax_cents, line_total_cents, product_type, category_label, note"
                               . (bcrf_sales_line_disc_ready($pdo) ? ", line_discount_bp" : ", 0 AS line_discount_bp") . "
                        FROM sale_lines WHERE sale_id = ? ORDER BY line_no");
    $l->execute([$s['id']]);
    $p = $pdo->prepare("SELECT tender, amount_cents, tendered_cents, change_cents, payer_handle, reference FROM payments WHERE sale_id = ? ORDER BY id");
    $p->execute([$s['id']]);
    $s['lines'] = $l->fetchAll(PDO::FETCH_ASSOC);
    $s['payments'] = $p->fetchAll(PDO::FETCH_ASSOC);
    foreach (['id', 'subtotal_cents', 'discount_cents', 'tax_cents', 'total_cents', 'discount_bp', 'tax_exempt'] as $k) $s[$k] = (int)$s[$k];
    $s['customer_name'] = null; $s['customer_email'] = null;
    if ($s['customer_id'] && bcrf_customers_ready($pdo)) {
        $cn = $pdo->prepare("SELECT TRIM(CONCAT(first_name, ' ', last_name)) AS n, email FROM customers WHERE id = ?");
        $cn->execute([$s['customer_id']]);
        if ($c = $cn->fetch(PDO::FETCH_ASSOC)) { $s['customer_name'] = $c['n'] ?: null; $s['customer_email'] = $c['email'] ?: null; }
    }
    // Tax by rate, for the receipt
    $byRate = [];
    foreach ($s['lines'] as $ln) { $r = (int)$ln['tax_rate_bp']; $byRate[$r] = ($byRate[$r] ?? 0) + (int)$ln['tax_cents']; }
    ksort($byRate);
    $s['tax_by_rate'] = $byRate;
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    $s['completed_local'] = (new DateTimeImmutable($s['completed_at'], new DateTimeZone('UTC')))->setTimezone($tz)->format('n/j/Y g:i A');
    return $s;
}

/** Complete a sale. See the top of this file. */
function bcrf_sale_complete(PDO $pdo, array $in): array {
    if (!bcrf_sales_ready($pdo)) throw new BcrfSaleError('The sales tables are not set up yet: run migration 0009 in Settings › Database.');
    $v = bcrf_sale_validate($in);

    if ($done = bcrf_sale_get($pdo, null, $v['key'])) return ['replayed' => true, 'sale' => $done];
    // Slice 9: a discount that isn't one of the preset % buttons needs an Owner or Manager (05 › POS, pos.discount.override).
    require_once __DIR__ . '/receipts.php';
    if ($why = bcrf_discount_problem($pdo, (int)$v['discount_bp'], array_map('intval', array_column($v['cart'], 'disc_bp')))) throw new BcrfSaleError($why);
    $active = bcrf_tenders_active($pdo);
    foreach ($v['payments'] as $p) {
        if (!isset($active[$p['tender']])) throw new BcrfSaleError(bcrf_tenders()[$p['tender']] . ' is turned off in Settings › Payments & Register. Reload the register and use another payment type.');
    }

    $uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
    [$now, $bdate, $year] = bcrf_farm_now();
    $taxCats = bcrf_tax_categories($pdo);
    $customTax = null;
    foreach ($taxCats as $tc) if ($tc['code'] === 'general') $customTax = $tc['id'];

    // Admission sales add guests: one device at a time (released in the finally below).
    $adm = function_exists('bcrf_admission_ready') && bcrf_admission_ready($pdo);
    if ($adm) bcrf_capacity_lock($pdo);
    try {
    $pdo->beginTransaction();
    try {
        // The register's open drawer, locked so it can't close while this sale is being written.
        $drawerId = null;
        if (function_exists('bcrf_drawers_ready') && bcrf_drawers_ready($pdo)) {
            if (!$v['register_id']) throw new BcrfSaleError('Choose a register first.');
            $drawer = bcrf_drawer_current($pdo, $v['register_id'], true);
            if (!$drawer) throw new BcrfSaleError('The drawer is closed. Open it before taking payment.');
            $drawerId = (int)$drawer['id'];
        }

        // Lock the products being sold so two registers can't both take the last one unseen.
        $ids = array_values(array_unique(array_filter(array_column($v['cart'], 'product_id'))));
        $products = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT i.id, i.name, i.price, i.stock_quantity, i.is_active, i.pricing_mode, i.unit, i.tax_category_id,
                                        i.product_type, i.track_inventory, COALESCE(ic.name, i.category) AS cat_label
                                 FROM inventory i LEFT JOIN inventory_categories ic ON ic.id = i.category_id
                                 WHERE i.id IN ($in) FOR UPDATE");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $products[(int)$p['id']] = $p;
            foreach ($ids as $id) {
                if (!isset($products[$id])) throw new BcrfSaleError('An item in the cart no longer exists. Refresh the register.');
                if (!(int)$products[$id]['is_active']) throw new BcrfSaleError('"' . $products[$id]['name'] . '" is no longer active. Remove it or turn it back on.');
            }
        }

        // Item discounts need migration 0014; a customer must exist (slice 7).
        if (array_filter(array_column($v['cart'], 'disc_bp')) && !bcrf_sales_line_disc_ready($pdo)) {
            throw new BcrfSaleError('Item discounts need migration 0014 (Settings › Database). Use a discount on the whole sale for now.');
        }
        $customerName = null;
        if ($v['customer_id']) {
            if (!bcrf_customers_ready($pdo)) throw new BcrfSaleError('Customer accounts are not set up yet. Remove the customer and try again.');
            $cs = $pdo->prepare("SELECT TRIM(CONCAT(first_name, ' ', last_name)) FROM customers WHERE id = ?");
            $cs->execute([$v['customer_id']]);
            $customerName = $cs->fetchColumn();
            if ($customerName === false) throw new BcrfSaleError('That customer no longer exists. Remove them from the sale and try again.');
        }
        $priced = bcrf_sale_price($v['cart'], $products, $taxCats, $v['discount_bp'], $v['tax_exempt'], $customTax);
        $admission = [0, 0, []]; $ov = [null, null];
        if ($adm) {
            $admission = bcrf_admission_guests($pdo, $priced['lines']);
            if ($admission[0] > 0) $ov = bcrf_capacity_check($pdo, $admission[1], $v['capacity_override']);
        }
        if (!$v['payments'] && $priced['total'] !== 0) throw new BcrfSaleError('Add a payment first.');
        $paid = array_sum(array_column($v['payments'], 'amount_cents'));
        if ($paid !== $priced['total']) {
            throw new BcrfSaleError(sprintf('Payments add up to $%s but the total is $%s. Check the payments and try again.',
                number_format($paid / 100, 2), number_format($priced['total'] / 100, 2)));
        }

        $number = sprintf('R-%d-%06d', $year, bcrf_next_number($pdo, 'receipt', $year));
        $stockLoc = bcrf_stock_ready($pdo) ? bcrf_location_for_register($pdo, $v['register_id'] ?: null) : null;   // slice 8
        $completedUtc = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        try {
            $pdo->prepare("INSERT INTO sales (sale_number, channel, register_id, " . ($stockLoc ? "location_id, " : "") . "drawer_session_id, customer_id, status, subtotal_cents, discount_cents, tax_cents, total_cents, discount_bp,
                                              tax_exempt, business_date, completed_at, idempotency_key, receipt_delivery, created_at, created_by)
                           VALUES (?, 'pos', ?, " . ($stockLoc ? "?, " : "") . "?, ?, 'completed', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)")
                ->execute(array_merge([$number, $v['register_id'] ?: null], $stockLoc ? [$stockLoc] : [], [$drawerId, $v['customer_id'], $priced['subtotal'], $priced['discount'], $priced['tax'], $priced['total'], $v['discount_bp'],
                           $v['tax_exempt'] ? 1 : 0, $bdate, $completedUtc, $v['key'], $v['printed'] ? 'print' : 'none', $uid]));
        } catch (PDOException $e) {
            // Same key saved a moment ago by a parallel request: hand back that sale.
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_idem')) {
                $pdo->rollBack();
                if ($done = bcrf_sale_get($pdo, null, $v['key'])) return ['replayed' => true, 'sale' => $done];
            }
            throw $e;
        }
        $saleId = (int)$pdo->lastInsertId();

        $lineDiscCol = bcrf_sales_line_disc_ready($pdo);
        $insLine = $pdo->prepare("INSERT INTO sale_lines (sale_id, line_no, product_id, description, quantity, unit, unit_price_cents, gross_cents,
                                         discount_cents, tax_category_id, tax_rate_bp, tax_cents, line_total_cents, product_type, category_label, note"
                                         . ($lineDiscCol ? ", line_discount_bp" : "") . ")
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?" . ($lineDiscCol ? ", ?" : "") . ")");
        $setStock = $pdo->prepare("UPDATE inventory SET stock_quantity = ? WHERE id = ?");
        $ledger   = $pdo->prepare("INSERT INTO inventory_adjustments (product_id, quantity_change, quantity_before, quantity_after, reason,
                                          source_type, source_id, note, created_at, created_by)
                                   VALUES (?, ?, ?, ?, 'sale', 'sale', ?, ?, UTC_TIMESTAMP(3), ?)");
        $history  = $pdo->prepare("INSERT INTO sales_history (inventory_id, quantity_sold) VALUES (?, ?)");
        $legacyCart = [];
        foreach ($priced['lines'] as $l) {
            $insLine->execute(array_merge([$saleId, $l['line_no'], $l['product_id'], mb_substr($l['description'], 0, 255), $l['quantity'], $l['unit'],
                               $l['unit_price_cents'], $l['gross_cents'], $l['discount_cents'], $l['tax_category_id'], $l['tax_rate_bp'],
                               $l['tax_cents'], $l['line_total_cents'], $l['product_type'], mb_substr($l['category_label'], 0, 100), $l['note']],
                               $lineDiscCol ? [$l['line_discount_bp']] : []));
            if ($l['product_id'] && $l['track'] && $stockLoc) {   // slice 8: off the register's location; stops at zero there, as before
                $mv = bcrf_stock_move($pdo, (int)$l['product_id'], $stockLoc, -$l['quantity'], 'sale', 'sale', $saleId, null, $uid, 'clamp');
                if ($mv['short'] > 0) {
                    $pdo->prepare("UPDATE inventory_adjustments SET note = ? WHERE id = ?")
                        ->execute(["Sold {$l['quantity']} with " . bcrf_qtxt($mv['before']) . " on hand here (short by " . bcrf_qtxt($mv['short']) . ")", $mv['id']]);
                }
            } elseif ($l['product_id'] && $l['track']) {
                $p = &$products[$l['product_id']];
                $before = round((float)$p['stock_quantity'], 3);
                $after  = max(0, round($before - $l['quantity'], 3));   // stock never goes below zero (as before); the ledger notes a shortfall
                $short  = round($l['quantity'] - ($before - $after), 3);
                $setStock->execute([$after, $l['product_id']]);
                $ledger->execute([$l['product_id'], $after - $before, $before, $after, $saleId,
                                  $short > 0 ? "Sold {$l['quantity']} with {$before} on hand (short by {$short})" : null, $uid]);
                $p['stock_quantity'] = $after;
                unset($p);
            }
            if ($l['product_id']) $history->execute([$l['product_id'], $l['quantity']]);
            $legacyCart[] = ['id' => $l['product_id'] ?? 0, 'name' => $l['description'], 'price' => $l['unit_price_cents'] / 100,
                             'qty' => $l['quantity'], 'category' => $l['category_label'], 'tbl' => $l['product_id'] ? 'inventory' : 'VIRTUAL_CUSTOM_ITEM'];
        }

        if ($admission[0] > 0) {
            $admCents = array_sum(array_map(fn($l) => $l['product_type'] === 'admission' ? $l['line_total_cents'] : 0, $priced['lines']));
            bcrf_admission_for_sale($pdo, $saleId, $admission[0], $admission[1], $admission[2], $admCents, $ov[0], $ov[1]);
            if ($v['customer_id']) $pdo->prepare("UPDATE tickets SET customer_id = ? WHERE sale_id = ?")->execute([$v['customer_id'], $saleId]);
        }

        $insPay = $pdo->prepare("INSERT INTO payments (sale_id, tender, amount_cents, tendered_cents, change_cents, payer_handle, reference,
                                        status, business_date, created_at, created_by)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', ?, UTC_TIMESTAMP(3), ?)");
        foreach ($v['payments'] as $p) {
            $insPay->execute([$saleId, $p['tender'], $p['amount_cents'], $p['tendered_cents'], $p['change_cents'], $p['payer_handle'], $p['reference'], $bdate, $uid]);
        }

        // Sales Log / dashboard compatibility (they read pos_receipts until the reports are rebuilt).
        $tenders = array_unique(array_column($v['payments'], 'tender'));
        $method  = !$tenders ? 'FREE' : (count($tenders) === 1 ? strtoupper(reset($tenders)) : 'SPLIT');
        $pdo->prepare("INSERT INTO pos_receipts (receipt_number, cart_json, subtotal, discount, tax, total, payment_method, tax_exempt, was_printed)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$number, json_encode($legacyCart, JSON_UNESCAPED_UNICODE), $priced['subtotal'] / 100, $priced['discount'] / 100,
                       $priced['tax'] / 100, $priced['total'] / 100, $method, $v['tax_exempt'] ? 1 : 0, $v['printed'] ? 1 : 0]);
        $pdo->prepare("UPDATE sales SET legacy_receipt_id = ? WHERE id = ?")->execute([(int)$pdo->lastInsertId(), $saleId]);

        if (function_exists('bcrf_event')) {
            bcrf_event('sale.completed', 'sales', $saleId, ['sale_number' => $number, 'total_cents' => $priced['total'],
                       'tenders' => array_values($tenders), 'lines' => count($priced['lines']), 'business_date' => $bdate]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    } finally {
        if ($adm) bcrf_capacity_unlock($pdo);
    }

    if (function_exists('erp_audit')) {
        $labels = bcrf_tenders();
        erp_audit($pdo, 'create', 'sales', $saleId, sprintf('Sale %s - $%s %s (%d lines%s%s)', $number, number_format($priced['total'] / 100, 2),
            implode(' + ', array_map(fn($t) => $labels[$t], $tenders)), count($priced['lines']),
            $v['discount_bp'] ? ', ' . ($v['discount_bp'] / 100) . '% off' : '', $v['tax_exempt'] ? ', tax exempt' : '')
            . (array_filter(array_column($v['cart'], 'disc_bp')) ? ', item discounts' : '') . ($customerName ? ', customer ' . $customerName : '')
            . ($admission[0] ? ', ' . $admission[0] . ' admitted' . ($ov[0] ? ' - capacity override: ' . $ov[0] : '') : ''));
    }
    return ['replayed' => false, 'sale' => bcrf_sale_get($pdo, $saleId)];
}

}
