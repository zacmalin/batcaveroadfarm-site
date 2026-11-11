<?php
// tests/gift_cards_test.php — gift cards (Farm OS Phase 2, Nov 11, 2026) against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_gc_test DB_USER=... DB_PASS=... php tests/gift_cards_test.php
// Refuses to run unless DB_NAME ends in "_test". Empties that database, builds it from every migration (as a new farm
// would), then rings real sales through includes/sales.php and refunds/voids through includes/refunds.php.
// Exit 0 = all passed. Never touches a real database.
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$dsn = 'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4';
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', $opts);
$root = dirname(__DIR__);
ini_set('log_errors', '0');

// Stand-ins for the site mailer and the signed-in user.
$GLOBALS['mails'] = [];
function bcf_send_mail(string $to, string $subject, string $html, array $opts = []): bool { $GLOBALS['mails'][] = [$to, $subject, $html]; return true; }
$_SESSION = ['user_id' => 1, 'user_role' => 'Shopkeep'];

// -- Fresh database from the migrations --
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }
(require $root . '/admin/migrations/0028_gift_cards.php')($pdo);   // safe to repeat

require $root . '/includes/sales.php';
require $root . '/includes/drawers.php';
require $root . '/includes/refunds.php';
require $root . '/includes/admission.php';
require $root . '/includes/receipts.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$throws = function (callable $f, string $what, string $like = '') use ($ok) {
    try { $f(); $ok(false, "$what (no error)"); }
    catch (BcrfSaleError | BcrfGiftCardError $e) { $ok($like === '' || stripos($e->getMessage(), $like) !== false, "$what [" . $e->getMessage() . "]"); }
};
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$ledgerOk = function () use ($pdo): bool {   // every balance equals its ledger
    return (int)$pdo->query("SELECT COUNT(*) FROM gift_cards g WHERE g.balance_cents <> (SELECT COALESCE(SUM(amount_cents), 0) FROM gift_card_txns t WHERE t.gift_card_id = g.id)")->fetchColumn() === 0;
};

// -- Setup: migration result, a register with an open drawer, a product to sell --
$ok(bcrf_gift_cards_ready($pdo), 'migration 0028 ran');
$gcId = (int)$one("SELECT id FROM inventory WHERE product_type = 'gift_card'");
$ok($gcId > 0, 'Gift Card product created');
$ok((int)$one("SELECT COUNT(*) FROM inventory WHERE product_type = 'gift_card'") === 1, 'only one Gift Card product after running twice');
$ok($one("SELECT tc.code FROM inventory i JOIN tax_categories tc ON tc.id = i.tax_category_id WHERE i.id = ?", [$gcId]) === 'exempt', 'Gift Card product is tax exempt');
$ok((int)$one("SELECT track_inventory FROM inventory WHERE id = ?", [$gcId]) === 0 && $one("SELECT pricing_mode FROM inventory WHERE id = ?", [$gcId]) === 'open_price', 'no stock, open price');
$ok(isset(bcrf_tenders_active($pdo)['gift_card']), 'register offers Gift card (empty setting = all)');
$pdo->exec("INSERT INTO site_settings (setting_key, setting_value) VALUES ('pos_tenders', 'cash,venmo') ON DUPLICATE KEY UPDATE setting_value = 'cash,venmo'");
(require $root . '/admin/migrations/0028_gift_cards.php')($pdo);
$ok($one("SELECT setting_value FROM site_settings WHERE setting_key = 'pos_tenders'") === 'cash,venmo,gift_card', 'gift_card appended to a chosen tender list');
(require $root . '/admin/migrations/0028_gift_cards.php')($pdo);
$ok($one("SELECT setting_value FROM site_settings WHERE setting_key = 'pos_tenders'") === 'cash,venmo,gift_card', '…only once');

$regId = (int)$one("SELECT id FROM registers ORDER BY id LIMIT 1");
bcrf_drawer_open($pdo, $regId, ['2000' => 5]);
$pdo->exec("INSERT INTO inventory (category, name, price, stock_quantity, product_type, pricing_mode, unit, track_inventory, tax_category_id, is_active)
            VALUES ('Pantry', 'Jam', 8.00, 50, 'retail', 'each', 'each', 1, (SELECT id FROM tax_categories WHERE code = 'general'), 1)");
$jamId = (int)$pdo->lastInsertId();
$genBp = (int)$one("SELECT rate_bp FROM tax_categories WHERE code = 'general'");

$k = 0;
$sale = function (array $lines, array $pays, array $extra = []) use ($pdo, $regId, &$k) {
    $k++;
    return bcrf_sale_complete($pdo, $extra + ['idempotency_key' => 'gctest-' . str_pad((string)$k, 12, '0', STR_PAD_LEFT) . '-x', 'register_id' => $regId,
                                              'lines' => $lines, 'payments' => $pays]);
};
$gcLine = fn(int $cents, int $qty = 1) => ['product_id' => $gcId, 'qty' => $qty, 'unit_price_cents' => $cents];
$jam = fn(int $qty = 1) => ['product_id' => $jamId, 'qty' => $qty];
$cash = fn(int $c) => ['tender' => 'cash', 'amount_cents' => $c, 'tendered_cents' => $c];
$gcPay = fn(string $code, int $c) => ['tender' => 'gift_card', 'amount_cents' => $c, 'gift_card_code' => $code];
$jamTotal = fn(int $q) => $q * 800 + bcrf_muldiv($q * 800, $genBp, 10000);

// -- 1. Codes --
$c = bcrf_gc_code();
$ok(strlen($c) === 16 && !preg_match('/[01OI]/', $c), 'code: 16 chars, no 0/1/O/I');
$ok(bcrf_gc_norm(strtolower(bcrf_gc_fmt($c))) === $c, 'code: dashes and lower case accepted');
$ok(bcrf_gc_norm('https://example.com/gift-cards/card.php?g=' . bcrf_gc_fmt($c)) === $c, 'code: card link accepted');
$ok(bcrf_gc_norm('ABCD') === '' && bcrf_gc_norm('ABCDEFGHJKLMNPQ0') === '', 'code: wrong length or 0 rejected');

// -- 2. Selling gift cards on the register --
$r = $sale([$gcLine(5000)], [$cash(5000)]);
$s1 = $r['sale'];
$ok($s1['total_cents'] === 5000 && $s1['tax_cents'] === 0, 'gift card sale: $50, no tax');
$ok(count($s1['gift_cards']) === 1 && $s1['gift_cards'][0]['initial_cents'] === 5000 && $s1['gift_cards'][0]['balance_cents'] === 5000, 'one card issued with $50');
$card1 = $s1['gift_cards'][0]['code'];
$ok($ledgerOk(), 'ledger matches after issue');
$r2 = $sale([$gcLine(2500, 3), $jam(1)], [$cash(7500 + $jamTotal(1))]);
$ok(count($r2['sale']['gift_cards']) === 3, 'three cards from qty 3');
$ok((int)$one("SELECT COUNT(DISTINCT code) FROM gift_cards") === 4, 'all codes different');
$ok($r2['sale']['tax_cents'] === bcrf_muldiv(800, $genBp, 10000), 'tax only on the jam');
$throws(fn() => $sale([$gcLine(400)], [$cash(400)]), 'under $5 refused', '$5.00');
$throws(fn() => $sale([$gcLine(50100)], [$cash(50100)]), 'over $500 refused', '$500.00');
$throws(fn() => $sale([['product_id' => $gcId, 'qty' => 1.5, 'unit_price_cents' => 1000]], [$cash(1500)]), 'half a gift card refused', 'whole');
$throws(fn() => $sale([$gcLine(5000)], [$cash(4500)], ['discount_pct' => 10]), 'whole-sale discount on a gift card refused', 'discount');
$throws(fn() => $sale([$gcLine(5000) + ['disc_pct' => 10]], [$cash(4500)]), 'item discount on a gift card refused', 'discount');
$throws(fn() => $sale([$gcLine(1000)], [$gcPay($card1, 1000)]), 'gift card bought with a gift card refused', 'bought with a gift card');
$ok((int)$one("SELECT COUNT(*) FROM gift_cards") === 4 && (int)$one("SELECT COUNT(*) FROM sales") === 2, 'refused sales left nothing behind');
// replay: same key returns the same sale, no new card
$keyBefore = $k;
$k = 0; $again = $sale([$gcLine(5000)], [$cash(5000)]); $k = $keyBefore;
$ok($again['replayed'] && (int)$one("SELECT COUNT(*) FROM gift_cards") === 4, 'double tap: no second card');

// -- 3. Spending --
$r3 = $sale([$jam(2)], [$gcPay(bcrf_gc_fmt($card1), $jamTotal(2))]);
$bal1 = 5000 - $jamTotal(2);
$ok((int)$one("SELECT balance_cents FROM gift_cards WHERE code = ?", [$card1]) === $bal1, 'spent from the card');
$ok(($r3['sale']['payments'][0]['gift_card']['balance_after_cents'] ?? null) === $bal1, 'receipt shows the balance left');
$ok($r3['sale']['payments'][0]['reference'] === substr($card1, -4), 'payment reference is the last 4');
$throws(fn() => $sale([$jam(10)], [$gcPay($card1, $jamTotal(10))]), 'more than the balance refused', 'only has');
$ok((int)$one("SELECT balance_cents FROM gift_cards WHERE code = ?", [$card1]) === $bal1, 'refused spend took nothing');
$throws(fn() => $sale([$jam(1)], [$gcPay('ABCDEFGHJKLMNPQR', $jamTotal(1))]), 'unknown card refused', 'not found');
// split: gift card + cash
$cards3 = array_column($r2['sale']['gift_cards'], 'code');
$r4 = $sale([$jam(5)], [$gcPay($cards3[0], 2500), $cash($jamTotal(5) - 2500)]);
$ok((int)$one("SELECT balance_cents FROM gift_cards WHERE code = ?", [$cards3[0]]) === 0, 'split: card emptied, rest in cash');
$ok($ledgerOk(), 'ledger matches after spending');
$lk = bcrf_gc_lookup($pdo, $cards3[0]);
$ok($lk['usable'] === false && str_contains((string)$lk['message'], 'Nothing left'), 'lookup: empty card says so');
$lk = bcrf_gc_lookup($pdo, $cards3[1]);
$ok($lk['usable'] && $lk['balance_cents'] === 2500 && $lk['masked'] === '····' . substr($cards3[1], -4), 'lookup: balance and masked code');

// -- 4. Refunds --
// money paid by gift card goes back onto it
$q = bcrf_refund_quote($pdo, (int)$r3['sale']['id'], [['sale_line_id' => (int)$one("SELECT id FROM sale_lines WHERE sale_id = ?", [$r3['sale']['id']]), 'qty' => 1, 'restock' => true]]);
$ok(($q['suggested'][0]['tender'] ?? '') === 'gift_card', 'refund suggests the gift card');
$rf = bcrf_refund_complete($pdo, ['idempotency_key' => 'gcrefund-000000000001', 'sale_id' => $r3['sale']['id'], 'register_id' => $regId, 'reason' => 'test',
    'lines' => [['sale_line_id' => (int)$one("SELECT id FROM sale_lines WHERE sale_id = ?", [$r3['sale']['id']]), 'qty' => 1, 'restock' => true]],
    'payments' => [['tender' => 'gift_card', 'amount_cents' => $q['total']]]]);
$ok((int)$one("SELECT balance_cents FROM gift_cards WHERE code = ?", [$card1]) === $bal1 + $q['total'], 'refund put back on the card');
$ok((int)$one("SELECT gift_card_id FROM refund_payments WHERE refund_id = (SELECT id FROM refunds WHERE refund_number = ?)", [$rf['refund_number']]) > 0, 'refund payment names the card');
$throws(fn() => bcrf_refund_complete($pdo, ['idempotency_key' => 'gcrefund-000000000002', 'sale_id' => $r3['sale']['id'], 'register_id' => $regId, 'reason' => 'test',
    'lines' => [['sale_line_id' => (int)$one("SELECT id FROM sale_lines WHERE sale_id = ?", [$r3['sale']['id']]), 'qty' => 1, 'restock' => true]],
    'payments' => [['tender' => 'gift_card', 'amount_cents' => $q['total'] + 1]]]), 'refund more than paid by card refused');
// refunding a sold gift card line cancels an unused card; a spent card can't be refunded
$gcLineId = (int)$one("SELECT id FROM sale_lines WHERE sale_id = ? AND product_type = 'gift_card'", [$r2['sale']['id']]);
$rf2 = bcrf_refund_complete($pdo, ['idempotency_key' => 'gcrefund-000000000003', 'sale_id' => $r2['sale']['id'], 'register_id' => $regId, 'reason' => 'changed mind',
    'lines' => [['sale_line_id' => $gcLineId, 'qty' => 1]], 'payments' => [$cash(2500)]]);
$ok((int)$one("SELECT COUNT(*) FROM gift_cards WHERE sale_line_id = ? AND status = 'void'", [$gcLineId]) === 1, 'refunded gift card line: one unused card cancelled');
$ok((string)$one("SELECT status FROM gift_cards WHERE code = ?", [$cards3[0]]) === 'active', '…not the one that was spent');
$throws(fn() => bcrf_refund_complete($pdo, ['idempotency_key' => 'gcrefund-000000000004', 'sale_id' => $r2['sale']['id'], 'register_id' => $regId, 'reason' => 'x',
    'lines' => [['sale_line_id' => $gcLineId, 'qty' => 2]], 'payments' => [$cash(5000)]]), 'refunding a spent card refused', 'unused');
$throws(fn() => bcrf_refund_complete($pdo, ['idempotency_key' => 'gcrefund-000000000005', 'sale_id' => $r2['sale']['id'], 'register_id' => $regId, 'reason' => 'x',
    'lines' => [['sale_line_id' => $gcLineId, 'qty' => 0.5]], 'payments' => [$cash(1250)]]), 'refunding half a card refused', 'whole');
$ok($ledgerOk(), 'ledger matches after refunds');

// -- 5. Voids --
$throws(fn() => $sale([$gcLine(3000), $jam(1)], [$gcPay($cards3[1], $jamTotal(1)), $cash(3000)]), 'card + jam paid partly by card refused', 'bought with a gift card');
$r5 = $sale([$jam(1)], [$gcPay($cards3[1], $jamTotal(1))]);
$v5 = bcrf_sale_void($pdo, (int)$r5['sale']['id'], 'rang wrong');
$ok($v5['status'] === 'voided' && (int)$one("SELECT balance_cents FROM gift_cards WHERE code = ?", [$cards3[1]]) === 2500, 'void puts the gift card payment back');
$r6 = $sale([$gcLine(4000)], [$cash(4000)]);
$card6 = $r6['sale']['gift_cards'][0]['code'];
bcrf_sale_void($pdo, (int)$r6['sale']['id'], 'wrong amount');
$ok((string)$one("SELECT status FROM gift_cards WHERE code = ?", [$card6]) === 'void' && (int)$one("SELECT balance_cents FROM gift_cards WHERE code = ?", [$card6]) === 0, 'void cancels the card it sold');
$throws(fn() => $sale([$jam(1)], [$gcPay($card6, $jamTotal(1))]), 'cancelled card refused at the register', 'cancelled');
$r7 = $sale([$gcLine(2000)], [$cash(2000)]);
$card7 = $r7['sale']['gift_cards'][0]['code'];
$sale([$jam(1)], [$gcPay($card7, $jamTotal(1))]);
$d7 = bcrf_sale_detail($pdo, (int)$r7['sale']['id']);
$ok(is_string($d7['void_blocker']) && str_contains($d7['void_blocker'], 'used'), 'sale whose card was spent can\'t be voided');
$throws(fn() => bcrf_sale_void($pdo, (int)$r7['sale']['id'], 'x'), 'void of a spent card refused', 'used');
$ok($ledgerOk(), 'ledger matches after voids');

// -- 6. Rewards and reports --
$ok(!bcrf_rewards_product_earns('gift_card', null), 'gift cards earn no Roost Rewards points');
$ok(bcrf_drawer_summary($pdo, (int)$one("SELECT id FROM drawer_sessions WHERE status = 'open'"))['by_tender']['gift_card']['cents'] > 0, 'drawer report lists gift card payments');
$exp = bcrf_drawer_expected($pdo, bcrf_drawer_current($pdo, $regId));
$cashIn = (int)$one("SELECT COALESCE(SUM(p.amount_cents), 0) FROM payments p JOIN sales s ON s.id = p.sale_id WHERE p.tender = 'cash' AND p.status = 'completed' AND s.drawer_session_id IS NOT NULL")
        - (int)$one("SELECT COALESCE(SUM(amount_cents), 0) FROM refund_payments WHERE tender = 'cash'");
$ok($exp === 10000 + $cashIn, 'expected cash ignores gift card payments');

// -- 7. Online purchase --
$throws(fn() => bcrf_gc_online_create($pdo, ['amount' => '3000', 'purchaser_name' => 'Al', 'purchaser_email' => 'al@example.com', 'send_to' => 'me']), 'online: odd preset refused', 'Choose an amount');
$throws(fn() => bcrf_gc_online_create($pdo, ['amount' => 'other', 'amount_other' => '5', 'purchaser_name' => 'Al', 'purchaser_email' => 'al@example.com', 'send_to' => 'me']), 'online: under $10 refused', '$10.00');
$throws(fn() => bcrf_gc_online_create($pdo, ['amount' => 'other', 'amount_other' => '12.50', 'purchaser_name' => 'Al', 'purchaser_email' => 'al@example.com', 'send_to' => 'me']), 'online: cents refused', 'whole-dollar');
$throws(fn() => bcrf_gc_online_create($pdo, ['amount' => '5000', 'purchaser_name' => 'Al', 'purchaser_email' => 'al@example.com', 'send_to' => 'them']), 'online: recipient email needed', 'email address');
$on = bcrf_gc_online_create($pdo, ['amount' => 'other', 'amount_other' => '$75', 'purchaser_name' => 'Al Buyer', 'purchaser_email' => 'AL@example.com',
                                   'send_to' => 'them', 'recipient_name' => 'Bea', 'recipient_email' => 'bea@example.com', 'message' => 'Happy birthday!']);
$ok($on['status'] === 'pending' && $on['balance_cents'] === 0 && $on['initial_cents'] === 7500 && $on['purchaser_email'] === 'al@example.com', 'online: saved pending, $0 until paid');
$throws(fn() => $sale([$jam(1)], [$gcPay($on['code'], $jamTotal(1))]), 'online: unpaid card refused at the register', 'paid for');
$ok(!bcrf_gc_online_mark_paid($pdo, (int)$on['id'], 'cs_test', 5000), 'online: short payment does not activate');
$GLOBALS['mails'] = [];
$ok(bcrf_gc_online_mark_paid($pdo, (int)$on['id'], 'cs_test', 7500), 'online: paid activates');
$ok(!bcrf_gc_online_mark_paid($pdo, (int)$on['id'], 'cs_test', 7500), 'online: second confirmation does nothing');
$on = bcrf_gc_get($pdo, (int)$on['id']);
$ok($on['status'] === 'active' && $on['balance_cents'] === 7500, 'online: $75 on the card');
$ok(count($GLOBALS['mails']) === 2 && $GLOBALS['mails'][0][0] === 'bea@example.com' && $GLOBALS['mails'][1][0] === 'al@example.com', 'online: card to recipient, receipt to buyer');
$ok(str_contains($GLOBALS['mails'][0][2], bcrf_gc_fmt($on['code'])) && str_contains($GLOBALS['mails'][0][2], 'Happy birthday!') && str_contains($GLOBALS['mails'][0][1], 'Al Buyer'), 'online: email has code, message and sender');
$ok(!str_contains($GLOBALS['mails'][1][2], $on['code']) && !str_contains($GLOBALS['mails'][1][2], bcrf_gc_fmt($on['code'])), 'online: buyer receipt doesn\'t show the code');
$sale([$jam(1)], [$gcPay($on['code'], $jamTotal(1))]);
$ok((int)$one("SELECT balance_cents FROM gift_cards WHERE id = ?", [$on['id']]) === 7500 - $jamTotal(1), 'online card spends at the register');
$throws(fn() => bcrf_gc_online_create($pdo, ['amount' => '5000', 'purchaser_name' => 'A', 'purchaser_email' => 'x@example.com', 'send_to' => 'me', 'message' => str_repeat('a', 301)]), 'online: long message refused', '300');

// -- 8. Admin: adjust, cancel, give --
$throws(fn() => bcrf_gc_adjust($pdo, (int)$on['id'], 500, 'x', 1), 'adjust needs a manager', 'Owner or Manager');
$_SESSION['user_role'] = 'administrator';
$throws(fn() => bcrf_gc_adjust($pdo, (int)$on['id'], 500, ' ', 1), 'adjust needs a reason', 'reason');
$throws(fn() => bcrf_gc_adjust($pdo, (int)$on['id'], -100000, 'too much', 1), 'adjust below $0 refused');
$b = (int)$one("SELECT balance_cents FROM gift_cards WHERE id = ?", [$on['id']]);
bcrf_gc_adjust($pdo, (int)$on['id'], -500, 'damaged card swap', 1);
$ok((int)$one("SELECT balance_cents FROM gift_cards WHERE id = ?", [$on['id']]) === $b - 500, 'adjust takes off $5');
$m = bcrf_gc_issue_manual($pdo, 2000, 'Raffle prize', ['name' => 'Cy', 'email' => 'cy@example.com'], 1);
$ok($m['source'] === 'manual' && $m['balance_cents'] === 2000 && $m['note'] === 'Raffle prize', 'manual card given');
bcrf_gc_void($pdo, (int)$m['id'], 'lost', 1);
$ok((string)$one("SELECT status FROM gift_cards WHERE id = ?", [$m['id']]) === 'void' && (int)$one("SELECT balance_cents FROM gift_cards WHERE id = ?", [$m['id']]) === 0, 'cancel takes it to $0');
$liab = bcrf_gc_liability($pdo);
$ok($liab['cents'] === (int)$one("SELECT SUM(balance_cents) FROM gift_cards WHERE status = 'active'"), 'liability = sum of active balances');
$ok(count(bcrf_gc_list($pdo, ['q' => substr($card1, -4)])) >= 1 && count(bcrf_gc_list($pdo, ['q' => 'bea@example'])) === 1, 'list finds by last 4 and by email');
$ok(count(bcrf_gc_list($pdo, ['status' => 'void'])) === (int)$one("SELECT COUNT(*) FROM gift_cards WHERE status = 'void'"), 'list filters cancelled');
$ok($ledgerOk(), 'every balance equals its ledger');
$ok((int)$one("SELECT COUNT(*) FROM gift_cards WHERE balance_cents < 0") === 0, 'no negative balances');

// -- 9. Two registers spending the same card at once: only one wins --
$card9 = bcrf_gc_issue_manual($pdo, 1000, 'race test', [], 1);
$pdo2 = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', $opts);
$pdo->beginTransaction();
bcrf_gc_by_code($pdo, $card9['code'], true);   // register A holds the card
$pdo2->exec("SET SESSION innodb_lock_wait_timeout = 1");
$blocked = false;
try { $pdo2->beginTransaction(); bcrf_gc_by_code($pdo2, $card9['code'], true); } catch (PDOException $e) { $blocked = true; }
if ($pdo2->inTransaction()) $pdo2->rollBack();
$pdo->rollBack();
$ok($blocked, 'second register waits for the first (row lock)');

echo $fails ? "\n$fails of $n checks failed\n" : "All $n checks passed\n";
exit($fails ? 1 : 0);
