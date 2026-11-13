<?php
// tests/pos_test.php — Phase 1 (POS, sales and admission) against a real, THROWAWAY MySQL 8 database. Added Nov 13, 2026.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_pos_test DB_USER=... DB_PASS=... php tests/pos_test.php
// Roadmap 16 lists Phase 1 checks that were run by hand while it was built but never saved to the repo. This file
// keeps them: sales core (prices, tax, discounts, split tender, double taps), cash drawers, refunds and voids,
// the gate (tickets, capacity, overrides, check-out), held carts, stock by location (moves, transfers, counts)
// and the daily report adding up. Refuses to run unless DB_NAME ends in "_test". Exit 0 = all passed.
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$dsn = 'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4';
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', $opts);
$root = dirname(__DIR__);
ini_set('log_errors', '0');

$GLOBALS['mails'] = [];
function bcf_send_mail(string $to, string $subject, string $html, array $opts = []): bool { $GLOBALS['mails'][] = [$to, $subject, $html]; return true; }
$_SESSION = ['user_id' => 3, 'user_role' => 'Shopkeep'];
$asOwner = function () { $_SESSION = ['user_id' => 1, 'user_role' => 'administrator']; };
$asClerk = function () { $_SESSION = ['user_id' => 3, 'user_role' => 'Shopkeep']; };

// -- Fresh database from the migrations --
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }

require $root . '/includes/sales.php';
require $root . '/includes/drawers.php';
require $root . '/includes/refunds.php';
require $root . '/includes/admission.php';
require $root . '/includes/receipts.php';
require $root . '/includes/register_extras.php';
require $root . '/includes/reports.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$throws = function (callable $f, string $what, string $like = '') use ($ok) {
    try { $f(); $ok(false, "$what (no error)"); }
    catch (BcrfSaleError | BcrfStockError | BcrfRegisterError $e) { $ok($like === '' || stripos($e->getMessage(), $like) !== false, "$what [" . $e->getMessage() . "]"); }
};
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };

$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role, account_status) VALUES
  (1, 'Zac', 'Owner', 'o@x.test', 'administrator', 'active'), (3, 'Kim', 'Shop', 'k@x.test', 'Shopkeep', 'active')");
$genId = (int)$one("SELECT id FROM tax_categories WHERE code = 'general'");
$genBp = (int)$one("SELECT rate_bp FROM tax_categories WHERE code = 'general'");
$exemptId = (int)$one("SELECT id FROM tax_categories WHERE code = 'exempt'");
$prod = function (string $name, float $price, int $stock, ?int $tax, string $type = 'retail', string $mode = 'each') use ($pdo) {
    $pdo->prepare("INSERT INTO inventory (category, name, price, stock_quantity, product_type, pricing_mode, unit, track_inventory, tax_category_id, is_active)
                   VALUES ('Pantry', ?, ?, 0, ?, ?, ?, ?, ?, 1)")
        ->execute([$name, $price, $type, $mode, $mode === 'by_weight' ? 'lb' : 'each', $stock >= 0 ? 1 : 0, $tax]);
    $id = (int)$pdo->lastInsertId();
    if ($stock > 0) bcrf_stock_adjust($pdo, $id, bcrf_location_default($pdo), $stock, 'receive', 1, 'opening', 1);
    return $id;
};
$jam   = $prod('Jam', 8.00, 50, $genId);
$eggs  = $prod('Eggs (dozen)', 5.00, 20, $exemptId);
$pin   = $prod('Pin', 0.99, -1, $genId);   // not stocked

$regId = (int)$one("SELECT id FROM registers ORDER BY id LIMIT 1");
$ok($regId > 0, 'a register is seeded');
$k = 0;
$sale = function (array $lines, array $pays, array $extra = []) use ($pdo, $regId, &$k) {
    $k++;
    return bcrf_sale_complete($pdo, $extra + ['idempotency_key' => 'postest-' . str_pad((string)$k, 12, '0', STR_PAD_LEFT) . '-x', 'register_id' => $regId,
                                              'lines' => $lines, 'payments' => $pays]);
};
$cash = fn(int $c, ?int $tend = null) => ['tender' => 'cash', 'amount_cents' => $c, 'tendered_cents' => $tend ?? $c];
$venmo = fn(int $c) => ['tender' => 'venmo', 'amount_cents' => $c, 'payer_handle' => '@buyer'];
$tax = fn(int $net) => bcrf_muldiv($net, $genBp, 10000);
$stockAt = fn(int $pid) => (float)$one("SELECT COALESCE(SUM(quantity), 0) FROM inventory_levels WHERE product_id = ?", [$pid]);
$total = fn(int $pid) => (float)$one("SELECT stock_quantity FROM inventory WHERE id = ?", [$pid]);

// -- 1. Drawer must be open --
$throws(fn() => $sale([['product_id' => $jam, 'qty' => 1]], [$cash(800 + $tax(800))]), 'no sale with the drawer closed', 'drawer is closed');
$drawer = bcrf_drawer_open($pdo, $regId, ['2000' => 5, '500' => 4]);   // $100 + $20
$ok((int)$one("SELECT opening_float_cents FROM drawer_sessions WHERE id = ?", [$drawer]) === 12000, 'float counted from bills: $120');
$throws(fn() => bcrf_drawer_open($pdo, $regId, ['2000' => 1]), 'second open drawer on one register refused', 'already open');

// -- 2. Sales core --
$r = $sale([['product_id' => $jam, 'qty' => 2], ['product_id' => $eggs, 'qty' => 1]], [$cash(1600 + $tax(1600) + 500, 5000)]);
$s1 = $r['sale'];
$ok($s1['subtotal_cents'] === 2100 && $s1['tax_cents'] === $tax(1600) && $s1['total_cents'] === 2100 + $tax(1600), 'jam taxed, eggs exempt, server prices');
$ok(preg_match('/^R-\d{4}-\d{6}$/', $s1['sale_number']) === 1, 'receipt number R-YYYY-NNNNNN');
$ok((int)$s1['payments'][0]['change_cents'] === 5000 - $s1['total_cents'], 'change worked out');
$ok($stockAt($jam) == 48 && $total($jam) == 48 && $stockAt($eggs) == 19, 'stock off the register location; total matches levels');
$ok((int)$one("SELECT COUNT(*) FROM inventory_adjustments WHERE source_type = 'sale' AND source_id = ?", [$s1['id']]) === 2, 'one ledger row per stocked line');
$ok((int)$one("SELECT COUNT(*) FROM pos_receipts WHERE receipt_number = ?", [$s1['sale_number']]) === 1, 'legacy Sales Log row written');
// double tap
$kk = $k; $k = 1; $again = $sale([['product_id' => $jam, 'qty' => 2], ['product_id' => $eggs, 'qty' => 1]], [$cash(1)]); $k = $kk;
$ok($again['replayed'] && $again['sale']['id'] === $s1['id'] && $stockAt($jam) == 48, 'same save code: same sale back, nothing twice');
// payments must match
$throws(fn() => $sale([['product_id' => $jam, 'qty' => 1]], [$cash(100)]), 'underpaid sale refused', 'Payments add up');
$throws(fn() => $sale([['product_id' => $jam, 'qty' => 1]], [['tender' => 'venmo', 'amount_cents' => 800 + $tax(800)]]), 'Venmo needs the payer', 'payer');
$throws(fn() => $sale([], [$cash(100)]), 'empty cart refused', 'empty');
$throws(fn() => $sale([['product_id' => $jam, 'qty' => 1]], [$cash(800 + $tax(800), 100)]), 'cash tendered less than applied refused', 'less than');
// discounts: 10% off the sale, spread across lines, tax on what's left
$quote = function (array $lines, int $bp = 0, bool $exempt = false) use ($pdo, $genId) {
    $ids = array_filter(array_column($lines, 'product_id'));
    $products = [];
    foreach ($ids as $id) { $st = $pdo->prepare("SELECT *, category AS cat_label FROM inventory WHERE id = ?"); $st->execute([$id]); $products[$id] = $st->fetch(); }
    $cart = array_map(fn($l) => $l + ['unit_price_cents' => 0, 'disc_bp' => (int)round(($l['disc_pct'] ?? 0) * 100)], $lines);
    return bcrf_sale_price($cart, $products, bcrf_tax_categories($pdo), $bp, $exempt, $genId);
};
$lines = [['product_id' => $jam, 'qty' => 3], ['product_id' => $pin, 'qty' => 1]];
$q = $quote($lines, 1000);
$ok($q['discount'] === bcrf_muldiv(2499, 1000, 10000), '10% off the sale: discount on $24.99');
$ok(array_sum(array_column($q['lines'], 'discount_cents')) === $q['discount'], 'sale discount spread across lines adds up exactly');
$ok($q['total'] === $q['subtotal'] - $q['discount'] + $q['tax'], 'total = subtotal - discount + tax');
$r = $sale($lines, [$cash($q['total'])], ['discount_pct' => 10]);
$ok($r['sale']['total_cents'] === $q['total'] && $r['sale']['discount_bp'] === 1000, '10% sale saved at the quoted total');
// item discount then sale discount
$lines2 = [['product_id' => $jam, 'qty' => 2, 'disc_pct' => 50], ['product_id' => $jam, 'qty' => 1]];
$q2 = $quote($lines2, 1000);
$ok($q2['lines'][0]['item_discount_cents'] === 800 && $q2['discount'] === 800 + bcrf_muldiv(1600, 1000, 10000), 'item 50% first, then 10% on what is left');
$r = $sale($lines2, [$cash($q2['total'])], ['discount_pct' => 10]);
$ok($r['sale']['total_cents'] === $q2['total'], 'item + sale discount saved at the quoted total');
// an odd discount needs a manager
$throws(fn() => $sale([['product_id' => $jam, 'qty' => 1]], [$cash(1)], ['discount_pct' => 13]), 'non-preset % needs an Owner or Manager', 'Owner or Manager');
// tax exempt
$q3 = $quote([['product_id' => $jam, 'qty' => 1]], 0, true);
$r = $sale([['product_id' => $jam, 'qty' => 1]], [$cash(800)], ['tax_exempt' => 1]);
$ok($q3['tax'] === 0 && $r['sale']['tax_cents'] === 0 && $r['sale']['total_cents'] === 800, 'tax-exempt sale: no tax');
// custom item taxed at general, not stocked
$r = $sale([['product_id' => 0, 'name' => 'Hay bale', 'qty' => 2, 'unit_price_cents' => 650]], [$cash(1300 + $tax(1300))]);
$ok($r['sale']['tax_cents'] === $tax(1300) && $r['sale']['lines'][0]['product_id'] === null, 'custom item: general tax, no product');
// split tender with P2P
$r = $sale([['product_id' => $jam, 'qty' => 2]], [$cash(1000), $venmo(1600 + $tax(1600) - 1000)]);
$split = $r['sale'];
$ok(count($split['payments']) === 2 && $split['payments'][1]['payer_handle'] === '@buyer', 'split tender: cash + Venmo with payer');
$ok($one("SELECT payment_method FROM pos_receipts WHERE receipt_number = ?", [$split['sale_number']]) === 'SPLIT', 'legacy log says SPLIT');
// selling more than on hand stops at zero and notes it
$pdo->exec("UPDATE inventory SET is_active = 0 WHERE id = $pin");
$throws(fn() => $sale([['product_id' => $pin, 'qty' => 1]], [$cash(99 + $tax(99))]), 'inactive product refused', 'no longer active');
$pdo->exec("UPDATE inventory SET is_active = 1 WHERE id = $pin");
$eggsLeft = (int)$stockAt($eggs);
$r = $sale([['product_id' => $eggs, 'qty' => $eggsLeft + 2]], [$cash(($eggsLeft + 2) * 500)]);
$ok($stockAt($eggs) == 0 && str_contains((string)$one("SELECT note FROM inventory_adjustments WHERE source_type = 'sale' AND source_id = ?", [$r['sale']['id']]), 'short by 2'), 'oversell: stock stops at 0, ledger notes short by 2');
// turned-off tender
$pdo->exec("INSERT INTO site_settings (setting_key, setting_value) VALUES ('pos_tenders', 'cash') ON DUPLICATE KEY UPDATE setting_value = 'cash'");
$throws(fn() => $sale([['product_id' => $jam, 'qty' => 1]], [$venmo(800 + $tax(800))]), 'turned-off payment type refused', 'turned off');
$pdo->exec("DELETE FROM site_settings WHERE setting_key = 'pos_tenders'");

// -- 3. Cash movements and closing the drawer --
$sess = bcrf_drawer_current($pdo, $regId);
$exp = bcrf_drawer_expected($pdo, $sess);
$cashIn = (int)$one("SELECT SUM(p.amount_cents) FROM payments p JOIN sales s ON s.id = p.sale_id WHERE s.drawer_session_id = ? AND p.tender = 'cash'", [$drawer]);
$ok($exp === 12000 + $cashIn, 'expected cash = float + cash sales');
bcrf_drawer_move($pdo, $drawer, 'paid_out', 1500, 'Ice');
bcrf_drawer_move($pdo, $drawer, 'paid_in', 500, 'Change top-up');
bcrf_drawer_move($pdo, $drawer, 'drop', 5000, 'To the safe');
$ok(bcrf_drawer_expected($pdo, $sess) === $exp - 1500 + 500 - 5000, 'paid out, paid in and drop counted');
$throws(fn() => bcrf_drawer_move($pdo, $drawer, 'paid_out', 5000000, 'x'), 'paying out more than the drawer holds refused', 'more cash');
$throws(fn() => bcrf_drawer_move($pdo, $drawer, 'paid_out', 100, ''), 'cash movement needs a reason', 'Say what');

// -- 4. Refunds and voids --
$asClerk();
$jamLine = (int)$one("SELECT id FROM sale_lines WHERE sale_id = ? AND product_id = ?", [$s1['id'], $jam]);
$q = bcrf_refund_quote($pdo, $s1['id'], [['sale_line_id' => $jamLine, 'qty' => 1, 'restock' => true]]);
$ok($q['total'] === 800 + bcrf_muldiv($tax(1600), 1, 2) || $q['total'] === bcrf_muldiv(1600, 1, 2) + bcrf_muldiv($tax(1600), 1000, 2000), 'refund quote: half the jam line with its tax');
$before = $stockAt($jam);
$rf = bcrf_refund_complete($pdo, ['idempotency_key' => 'posrefund-00000000001', 'sale_id' => $s1['id'], 'register_id' => $regId, 'reason' => 'dented',
                                 'lines' => [['sale_line_id' => $jamLine, 'qty' => 1, 'restock' => true]], 'payments' => [['tender' => 'cash', 'amount_cents' => $q['total']]]]);
$ok(preg_match('/^RF-\d{4}-\d{6}$/', $rf['refund_number']) === 1, 'refund number RF-YYYY-NNNNNN');
$ok($stockAt($jam) == $before + 1, 'restocked to the same location');
$again = bcrf_refund_complete($pdo, ['idempotency_key' => 'posrefund-00000000001', 'sale_id' => $s1['id'], 'register_id' => $regId, 'reason' => 'dented',
                                    'lines' => [['sale_line_id' => $jamLine, 'qty' => 1, 'restock' => true]], 'payments' => [['tender' => 'cash', 'amount_cents' => $q['total']]]]);
$ok($again['replayed'] && $stockAt($jam) == $before + 1, 'refund double tap: once');
// the rest of the line gives back exactly what is left
$q = bcrf_refund_quote($pdo, $s1['id'], [['sale_line_id' => $jamLine, 'qty' => 1]]);
$line = $pdo->query("SELECT * FROM sale_lines WHERE id = $jamLine")->fetch();
$ok($q['net'] === (int)$line['gross_cents'] - (int)$line['discount_cents'] - (int)$line['refunded_net_cents'] && $q['tax'] === (int)$line['tax_cents'] - (int)$line['refunded_tax_cents'], 'last piece of a line: exactly what is left');
$throws(fn() => bcrf_refund_quote($pdo, $s1['id'], [['sale_line_id' => $jamLine, 'qty' => 2]]), 'refunding more than is left refused', 'left to refund');
// Venmo refund needs a reference and can't exceed what Venmo paid
$vl = (int)$one("SELECT id FROM sale_lines WHERE sale_id = ?", [$split['id']]);
$throws(fn() => bcrf_refund_complete($pdo, ['idempotency_key' => 'posrefund-00000000002', 'sale_id' => $split['id'], 'register_id' => $regId, 'reason' => 'x',
        'lines' => [['sale_line_id' => $vl, 'qty' => 2]], 'payments' => [['tender' => 'venmo', 'amount_cents' => $split['total_cents']]]]), 'Venmo refund needs the transaction ID', 'transaction ID');
$throws(fn() => bcrf_refund_complete($pdo, ['idempotency_key' => 'posrefund-00000000003', 'sale_id' => $split['id'], 'register_id' => $regId, 'reason' => 'x',
        'lines' => [['sale_line_id' => $vl, 'qty' => 2]], 'payments' => [['tender' => 'venmo', 'amount_cents' => $split['total_cents'], 'reference' => 'AB12']]]), 'more back to Venmo than Venmo paid refused', 'Only');
// threshold: over $5 needs a manager when set
$pdo->exec("INSERT INTO site_settings (setting_key, setting_value) VALUES ('refund_approval_threshold_cents', '500') ON DUPLICATE KEY UPDATE setting_value = '500'");
$throws(fn() => bcrf_refund_complete($pdo, ['idempotency_key' => 'posrefund-00000000004', 'sale_id' => $split['id'], 'register_id' => $regId, 'reason' => 'x',
        'lines' => [['sale_line_id' => $vl, 'qty' => 2]], 'payments' => [['tender' => 'cash', 'amount_cents' => $split['total_cents']]]]), 'refund over the threshold needs a manager', 'Owner or Manager');
$asOwner();
$rf = bcrf_refund_complete($pdo, ['idempotency_key' => 'posrefund-00000000005', 'sale_id' => $split['id'], 'register_id' => $regId, 'reason' => 'x',
        'lines' => [['sale_line_id' => $vl, 'qty' => 2, 'restock' => true]], 'payments' => [['tender' => 'cash', 'amount_cents' => $split['total_cents']]]]);
$ok((int)$one("SELECT approved_by FROM refunds WHERE refund_number = ?", [$rf['refund_number']]) === 1, 'owner refund over threshold: approved_by recorded');
$pdo->exec("DELETE FROM site_settings WHERE setting_key = 'refund_approval_threshold_cents'");
$asClerk();
// void today's sale: stock back, drawer no longer expects its cash
$r = $sale([['product_id' => $jam, 'qty' => 4]], [$cash(3200 + $tax(3200))]);
$stockBefore = $stockAt($jam); $expBefore = bcrf_drawer_expected($pdo, $sess);
bcrf_sale_void($pdo, $r['sale']['id'], 'rang twice');
$ok($one("SELECT status FROM sales WHERE id = ?", [$r['sale']['id']]) === 'voided' && $stockAt($jam) == $stockBefore + 4, 'void: status voided, stock back');
$ok(bcrf_drawer_expected($pdo, $sess) === $expBefore - (3200 + $tax(3200)), 'void: drawer stops expecting its cash');
bcrf_sale_void($pdo, $r['sale']['id'], 'again');
$ok($stockAt($jam) == $stockBefore + 4, 'void twice: harmless');
$throws(fn() => bcrf_sale_void($pdo, $s1['id'], 'x'), 'part-refunded sale can\'t be voided', 'refunded');
$throws(fn() => bcrf_refund_quote($pdo, $r['sale']['id'], [['sale_line_id' => 1, 'qty' => 1]]), 'voided sale can\'t be refunded', 'voided');

// -- 5. Held carts --
$h = bcrf_hold_save($pdo, $regId, 'Blue hat', ['cart' => [['id' => $jam, 'qty' => 1]], 'discPct' => 10], 900);
$ok(count(bcrf_holds_list($pdo)) === 1, 'held cart listed');
$state = bcrf_hold_recall($pdo, $h);
$ok(($state['cart'][0]['id'] ?? 0) === $jam && $state['discPct'] == 10, 'recall gives the cart back');
$throws(fn() => bcrf_hold_recall($pdo, $h), 'second device can\'t recall the same cart', 'already taken');
$throws(fn() => bcrf_hold_save($pdo, $regId, 'x', ['cart' => []], 0), 'empty cart can\'t be held', 'empty');
$h2 = bcrf_hold_save($pdo, $regId, '', ['cart' => [['id' => $jam, 'qty' => 2]]], 1700);
bcrf_hold_discard($pdo, $h2);
$ok(count(bcrf_holds_list($pdo)) === 0, 'discarded cart gone');

// -- 6. Stock by location: transfer, adjustments, counts --
$asOwner();
$farm = bcrf_location_default($pdo);
$kitchen = (int)$one("SELECT id FROM locations WHERE name = 'Kitchen'");
$ok($kitchen > 0, 'Kitchen location seeded');
$t0 = $total($jam);
bcrf_stock_transfer($pdo, $jam, $farm, $kitchen, 5, 'jam shelf', 1);
$ok($total($jam) == $t0 && (float)$one("SELECT quantity FROM inventory_levels WHERE product_id = ? AND location_id = ?", [$jam, $kitchen]) == 5, 'transfer: total unchanged, 5 in the Kitchen');
$throws(fn() => bcrf_stock_transfer($pdo, $jam, $kitchen, $farm, 6, '', 1), 'can\'t move more than is there', '');
$throws(fn() => bcrf_stock_adjust($pdo, $jam, $farm, 1, 'shrink', -1, '', 1), 'lost/stolen needs a note', 'note');
bcrf_stock_adjust($pdo, $jam, $farm, 2, 'spoilage', 1, '', 1);
$ok($total($jam) == $t0 - 2, 'spoilage takes stock off');
$throws(fn() => bcrf_stock_adjust($pdo, $pin, $farm, 1, 'receive', 1, '', 1), 'unstocked item has no quantity', 'stocked');
$cid = bcrf_count_start($pdo, $farm, 'all', null, false, '', 1);
$throws(fn() => bcrf_count_start($pdo, $farm, 'all', null, false, '', 1), 'one open count per location', 'already open');
$farmJam = (float)$one("SELECT quantity FROM inventory_levels WHERE product_id = ? AND location_id = ?", [$jam, $farm]);
$res = bcrf_count_save($pdo, $cid, [$jam => (string)($farmJam - 1)], 1);
$ok($res['changed'] === 1 && (float)$one("SELECT quantity FROM inventory_levels WHERE product_id = ? AND location_id = ?", [$jam, $farm]) == $farmJam - 1, 'count sets the level and logs the difference');
$c = bcrf_count_close($pdo, $cid, false, 1);
$ok($c['status'] === 'closed', 'count closed');
$ok((int)$one("SELECT COUNT(*) FROM inventory i LEFT JOIN (SELECT product_id, SUM(quantity) s FROM inventory_levels GROUP BY product_id) l ON l.product_id = i.id
               WHERE i.track_inventory = 1 AND ROUND(i.stock_quantity, 3) <> ROUND(COALESCE(l.s, 0), 3)") === 0, 'every product total equals its location levels');
$ok((int)$one("SELECT COUNT(*) FROM inventory_adjustments a WHERE ROUND(a.quantity_after - a.quantity_before, 3) <> ROUND(a.quantity_change, 3)") === 0, 'every ledger row: after - before = change');

// -- 7. Gate: tickets, capacity, override, check-out --
$asClerk();
$pdo->exec("INSERT INTO site_settings (setting_key, setting_value) VALUES ('capacity_limit', '10') ON DUPLICATE KEY UPDATE setting_value = '10'");
$mk = function (int $qty, string $status = 'issued', ?string $expires = null) use ($pdo) {
    $pdo->prepare("INSERT INTO tickets (public_code, source, quantity, status, amount_cents, expires_at, created_at, purchaser_name) VALUES (?, 'online', ?, ?, 0, ?, UTC_TIMESTAMP(), 'Test')")
        ->execute([bcrf_ticket_code(), $qty, $status, $expires]);
    return (int)$pdo->lastInsertId();
};
$t1 = $mk(6);
$c1 = bcrf_ticket_redeem($pdo, $t1, 4);
$ok(bcrf_occupancy($pdo)['on_site'] === 4 && $one("SELECT status FROM tickets WHERE id = ?", [$t1]) === 'partially_redeemed', 'partial redemption: 4 of 6 in');
$throws(fn() => bcrf_ticket_redeem($pdo, $t1, 3), 'can\'t use more than is left', 'Only 2 left');
$t2 = $mk(6);
bcrf_ticket_redeem($pdo, $t2, 6);
$ok(bcrf_occupancy($pdo)['on_site'] === 10 && bcrf_occupancy($pdo)['level'] === 'full', '10 of 10: full');
$throws(fn() => bcrf_ticket_redeem($pdo, $t1, 2), 'full farm refuses without an override', 'full');
$throws(fn() => bcrf_ticket_redeem($pdo, $t1, 2, 'birthday'), 'clerk can\'t override capacity', 'Only an Owner or Manager');
$asOwner();
bcrf_ticket_redeem($pdo, $t1, 2, 'birthday');
$ok($one("SELECT capacity_override_reason FROM check_ins WHERE ticket_id = ? ORDER BY id DESC LIMIT 1", [$t1]) === 'birthday', 'owner override saved with its reason');
$ok($one("SELECT status FROM tickets WHERE id = ?", [$t1]) === 'redeemed', 'ticket fully used');
$throws(fn() => bcrf_ticket_redeem($pdo, $t1, 1), 'used ticket refused', 'have been used');
$throws(fn() => bcrf_ticket_redeem($pdo, $mk(2, 'void'), 1), 'void ticket refused', 'cancelled');
$throws(fn() => bcrf_ticket_redeem($pdo, $mk(2, 'pending_payment'), 1), 'unpaid ticket refused', 'never paid');
$throws(fn() => bcrf_ticket_redeem($pdo, $mk(2, 'issued', '2020-01-01 00:00:00'), 1), 'expired ticket refused', 'expired');
bcrf_check_out($pdo, $c1);
$ok(bcrf_occupancy($pdo)['on_site'] === 8, 'check-out frees 4 spaces');
$ok(bcrf_ticket_code_norm('abcd-efgh-jk') === 'ABCDEFGHJK', 'gate search: code typed with dashes and lower case');

// -- 7b. Admission sold on the register (the register also loads held carts: Nov 13 name clash found here) --
$asClerk();
$admTax = (int)$one("SELECT id FROM tax_categories WHERE code = 'admission'") ?: $genId;
$pdo->prepare("INSERT INTO inventory (category, name, price, stock_quantity, product_type, pricing_mode, unit, track_inventory, tax_category_id, is_active)
               VALUES ('Zoo', 'Day admission', 8.00, 0, 'admission', 'each', 'each', 0, ?, 1)")->execute([$admTax]);
$admId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO inventory (category, name, price, stock_quantity, product_type, pricing_mode, unit, track_inventory, tax_category_id, is_active)
               VALUES ('Zoo', 'Under 2', 0.00, 0, 'admission', 'each', 'each', 0, ?, 1)")->execute([$admTax]);
$babyId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ticket_types (product_id, name, counts_toward_capacity) VALUES (?, 'Day admission', 1), (?, 'Under 2', 0)")->execute([$admId, $babyId]);
$qa = $quote([['product_id' => $admId, 'qty' => 2], ['product_id' => $babyId, 'qty' => 1]]);
$onBefore = bcrf_occupancy($pdo)['on_site'];
$r = $sale([['product_id' => $admId, 'qty' => 2], ['product_id' => $babyId, 'qty' => 1]], [$cash($qa['total'])]);
$ok((int)$one("SELECT party_size FROM check_ins WHERE sale_id = ?", [$r['sale']['id']]) === 3 && bcrf_occupancy($pdo)['on_site'] === $onBefore + 2, 'register admission: party of 3 checked in, 2 count toward capacity');
$ok($one("SELECT status FROM tickets WHERE sale_id = ?", [$r['sale']['id']]) === 'redeemed', 'register admission: a used gate ticket');
$free = 10 - bcrf_occupancy($pdo)['on_site'];
try { $sale([['product_id' => $admId, 'qty' => $free + 1]], [$cash($quote([['product_id' => $admId, 'qty' => $free + 1]])['total'])]); $ok(false, 'register refuses admission over capacity'); }
catch (BcrfCapacityError $e) { $ok(str_contains($e->getMessage(), 'full'), 'register refuses admission over capacity'); }

// -- 8. Close the drawer, then the daily report adds up --
$asClerk();
$sess = bcrf_drawer_current($pdo, $regId);
$exp = bcrf_drawer_expected($pdo, $sess);
$counts = ['1' => $exp];   // count it all in pennies
$throws(fn() => bcrf_drawer_close($pdo, $drawer, ['1' => $exp - 2000], ''), 'short by $20 needs a note', 'Add a note');
$throws(fn() => bcrf_drawer_close($pdo, $drawer, ['1' => $exp - 2000], 'miscount'), 'short by $20 needs a manager', 'Owner or Manager');
$sum = bcrf_drawer_close($pdo, $drawer, $counts, '');
$ok((int)$one("SELECT variance_cents FROM drawer_sessions WHERE id = ?", [$drawer]) === 0 && $one("SELECT status FROM drawer_sessions WHERE id = ?", [$drawer]) === 'closed', 'even count closes the drawer');
$throws(fn() => $sale([['product_id' => $jam, 'qty' => 1]], [$cash(800 + $tax(800))]), 'no sale after the close', 'drawer is closed');
[, $today] = bcrf_farm_now();
$days = bcrf_rpt_days($pdo, $today, $today, false);
$d = $days[$today] ?? (is_array($days) ? reset($days) : []);
$gross = (int)$one("SELECT COALESCE(SUM(total_cents), 0) FROM sales WHERE status <> 'voided' AND business_date = ?", [$today]);
$refunded = (int)$one("SELECT COALESCE(SUM(total_cents), 0) FROM refunds WHERE business_date = ?", [$today]);
$ok(is_array($d) && $d !== [], 'daily report has today');
$ok(json_encode($d) !== false && (in_array($gross, array_map('intval', array_values(array_filter($d, 'is_numeric'))), true)
    || in_array($gross - $refunded, array_map('intval', array_values(array_filter($d, 'is_numeric'))), true)), 'daily report shows the day\'s takings');

echo $fails ? "$fails of $n checks failed\n" : "All $n checks passed\n";
exit($fails ? 1 : 0);
