<?php
// tests/money_in_test.php — "All money in" (includes/reports.php, Nov 14, 2026) against a THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_money_test DB_USER=... DB_PASS=... php tests/money_in_test.php
// One figure for the Sales tab, dashboard card, Monthly report and connector: register (after discounts and refunds,
// no gift cards sold), online tickets, passes (not comp), bookings & parties (not register ones, which the register
// sale holds), with tax kept out and gift cards counted when spent. Also the hour chart (farm time) and top items.
// Refuses to run unless DB_NAME ends in "_test". Exit 0 = all passed.
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '',
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$root = dirname(__DIR__);
ini_set('log_errors', '0');
set_exception_handler(function (Throwable $e) { fwrite(STDERR, 'Uncaught: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n"); exit(1); });
$_SESSION = ['user_id' => 1, 'user_role' => 'administrator'];

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
require $root . '/includes/reports.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };

$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role, account_status) VALUES (1, 'Zac', 'Owner', 'o@x.test', 'administrator', 'active')");
$regId = (int)$one("SELECT id FROM registers ORDER BY id LIMIT 1");
bcrf_drawer_open($pdo, $regId, ['2000' => 5]);
$genBp = (int)$one("SELECT rate_bp FROM tax_categories WHERE code = 'general'");
$pdo->exec("INSERT INTO inventory (category, name, price, stock_quantity, product_type, pricing_mode, unit, track_inventory, tax_category_id, is_active)
            VALUES ('Pantry', 'Jam', 8.00, 0, 'retail', 'each', 'each', 0, (SELECT id FROM tax_categories WHERE code = 'general'), 1)");
$jamId = (int)$pdo->lastInsertId();
$gcId = (int)$one("SELECT id FROM inventory WHERE product_type = 'gift_card'");
$ok($gcId > 0, 'Gift Card product exists');

$k = 0;
$sale = function (array $lines, array $pays) use ($pdo, $regId, &$k) {
    $k++;
    return bcrf_sale_complete($pdo, ['idempotency_key' => 'mitest-' . str_pad((string)$k, 12, '0', STR_PAD_LEFT) . '-xx', 'register_id' => $regId, 'lines' => $lines, 'payments' => $pays])['sale'];
};
$cash = fn(int $c) => ['tender' => 'cash', 'amount_cents' => $c, 'tendered_cents' => $c];
$jamTax = fn(int $q) => bcrf_muldiv($q * 800, $genBp, 10000);
$bd = bcrf_business_date();

// -- Register: 2 jams cash, a $50 gift card, 1 jam paid with that card, then 1 of the first 2 jams refunded --
$s1 = $sale([['product_id' => $jamId, 'qty' => 2]], [$cash(1600 + $jamTax(2))]);
$s2 = $sale([['product_id' => $gcId, 'qty' => 1, 'unit_price_cents' => 5000]], [$cash(5000)]);
$code = $s2['gift_cards'][0]['code'];
$s3 = $sale([['product_id' => $jamId, 'qty' => 1]], [['tender' => 'gift_card', 'amount_cents' => 800 + $jamTax(1), 'gift_card_code' => $code]]);
$line = (int)$one("SELECT id FROM sale_lines WHERE sale_id = ?", [$s1['id']]);
$q = bcrf_refund_quote($pdo, $s1['id'], [['sale_line_id' => $line, 'qty' => 1, 'restock' => false]]);
bcrf_refund_complete($pdo, ['idempotency_key' => 'mirefund-000000000001', 'sale_id' => $s1['id'], 'register_id' => $regId, 'reason' => 'test',
                            'lines' => [['sale_line_id' => $line, 'qty' => 1, 'restock' => false]], 'payments' => [['tender' => 'cash', 'amount_cents' => $q['total']]]]);

// -- Online: a $21 ticket order (tax $1.33 inside), a $60 Stripe pass, a $45 cash pass, a comp pass, party payments --
$pdo->prepare("INSERT INTO tickets (public_code, source, quantity, status, payment_status, amount_cents, tax_cents, tax_rate_bp, created_at, paid_at)
               VALUES ('MITEST0001', 'online', 3, 'issued', 'paid', 2100, 133, 675, UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute();
$pdo->prepare("INSERT INTO tickets (public_code, source, quantity, status, payment_status, amount_cents, created_at, paid_at)
               VALUES ('MITEST0002', 'online', 1, 'issued', 'pending', 800, UTC_TIMESTAMP(), NULL)")->execute();
$pt = (int)$one("SELECT id FROM pass_types ORDER BY id LIMIT 1");
if (!$pt) { $pdo->exec("INSERT INTO pass_types (type_key, label, price) VALUES ('family', 'Family', 60.00)"); $pt = (int)$pdo->lastInsertId(); }
$pp = $pdo->prepare("INSERT INTO passes (pass_type_id, holder_name, email, token, status, payment_status, payment_provider, amount_paid, tax_cents, tax_rate_bp, paid_at)
                     VALUES (?, 'P', 'p@x.test', ?, 'active', 'paid', ?, ?, ?, ?, UTC_TIMESTAMP())");
$pp->execute([$pt, bin2hex(random_bytes(8)), 'stripe', 60.00, 379, 675]);
$pp->execute([$pt, bin2hex(random_bytes(8)), 'cash', 45.00, 285, 675]);
$pp->execute([$pt, bin2hex(random_bytes(8)), 'comp', 60.00, 0, 0]);
$ep = $pdo->prepare("INSERT INTO event_payments (quote_id, subject_type, subject_id, method, amount_cents, tax_cents, tax_rate_bp, created_at) VALUES (1, 'event_inquiry', 1, ?, ?, ?, 675, UTC_TIMESTAMP())");
$ep->execute(['stripe', 10675, 675]);    // party paid online: $100 + $6.75 tax
$ep->execute(['register', 5000, 0]);     // paid at the register: the register sale holds it, not counted twice
$ep->execute(['stripe', -2135, -135]);   // refund
$pdo->exec("INSERT INTO gift_cards (code, source, initial_cents, balance_cents, status, created_at, paid_at)
            VALUES ('ONLINEGIFTCARD22', 'online', 2500, 2500, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())");

$mi = bcrf_rpt_money_in($pdo, $bd, $bd);
$s = $mi['sources'];
$regNet = 800 + 800;   // 1 jam kept from the 2 + the jam paid by gift card; the $50 card is not income
$ok($s['register']['net'] === $regNet, 'register: jam kept + jam bought with the gift card, gift card sale left out, refund taken off (' . $s['register']['net'] . ')');
$ok($s['register']['tax'] === $jamTax(2) + $jamTax(1) - ($q['total'] - 800), 'register tax after the refund');
$ok($s['tickets']['net'] === 2100 - 133 && $s['tickets']['count'] === 1, 'online tickets: paid order only, tax backed out');
$ok($s['passes']['net'] === 6000 - 379 + 4500 - 285 && $s['passes']['count'] === 2, 'passes: Stripe and cash, not comp');
$ok($s['events']['net'] === (10675 - 675) - (2135 - 135), 'bookings & parties: online payment less refund, register one skipped');
$ok($mi['net'] === array_sum(array_column($s, 'net')) && $mi['gross'] === $mi['net'] + $mi['tax'], 'totals add up');
$ok($mi['gc_sold'] === 5000 + 2500 && $mi['gc_sold_count'] === 2, 'gift cards bought (register + online) shown as owed');
$ok($mi['gc_spent'] === 800 + $jamTax(1), 'gift card spent');
$ok(array_sum($mi['by_day'][$bd]) === $mi['net'], 'by day matches the total');

// Daily report still matches the drawer (gift card sale is in register net sales there)
$day = bcrf_rpt_days($pdo, $bd, $bd, false)[$bd];
$ok($day['net_sales'] === $regNet + 5000, 'Daily report net sales still includes the gift card sold (matches the drawer)');

// Nothing yesterday; previous range
$y = date('Y-m-d', strtotime($bd . ' -1 day'));
$ok(bcrf_rpt_money_in($pdo, $y, $y)['net'] === 0, 'yesterday: nothing');
$ok(bcrf_rpt_prev_range('2026-11-01', '2026-11-14') === ['2026-10-01', '2026-10-14'], 'month so far vs same days last month');
$ok(bcrf_rpt_prev_range('2026-03-01', '2026-03-31') === ['2026-02-01', '2026-02-28'], 'full March vs all of February');
$ok(bcrf_rpt_prev_range('2026-01-01', '2026-02-29') === ['2025-01-01', '2025-02-28'], 'year so far vs same dates last year (leap day safe)');
$ok(bcrf_rpt_prev_range('2026-11-08', '2026-11-14') === ['2026-11-01', '2026-11-07'], '7 days vs the 7 before');

// Hours in farm time, top items after refunds, no gift card
$det = bcrf_rpt_register_detail($pdo, $bd, $bd);
$hr = (int)bcrf_local_time(gmdate('Y-m-d H:i:s'), 'G');
$ok(isset($det['hours'][$hr]) && array_sum(array_column($det['hours'], 'cents')) === 800 * 3, 'hour chart: farm-local hour, before refunds, no gift card');
$ok(count($det['items']) === 1 && $det['items'][0]['name'] === 'Jam' && $det['items'][0]['cents'] === 1600 && (int)$det['items'][0]['qty'] === 2, 'top items: jam 2 sold after 1 refunded');

// Voided sale drops out
bcrf_sale_void($pdo, $s3['id'], 'test void');
$after = bcrf_rpt_money_in($pdo, $bd, $bd);
$ok($after['sources']['register']['net'] === 800, 'void takes the sale out');
$ok($after['gc_spent'] === 0, 'void puts the gift card money back: nothing spent');

echo $fails ? "$fails of $n checks failed\n" : "All $n checks passed\n";
exit($fails ? 1 : 0);
