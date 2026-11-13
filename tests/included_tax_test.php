<?php
// tests/included_tax_test.php — sales tax inside online ticket and pass prices (Q-09, Nov 13, 2026), against a THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/included_tax_test.php
// Refuses to run unless DB_NAME ends in "_test". Empties that database and builds it from every migration.
// Exit 0 = all passed. Never touches a real database.
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '',
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$root = dirname(__DIR__);
ini_set('log_errors', '0');
set_exception_handler(function (Throwable $e) { fwrite(STDERR, 'Uncaught: ' . $e->getMessage() . ' at line ' . $e->getLine() . "\n"); exit(1); });

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }
require $root . '/includes/included_tax.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };

// -- The arithmetic --
$ok(bcrf_itax_part(800, 675) === 51, '$8.00 at 6.75% included: $0.51 tax ($7.49 + $0.51)');
$ok(bcrf_itax_part(500, 675) === 32, '$5.00 at 6.75% included: $0.32 tax');
$ok(bcrf_itax_part(0, 675) === 0 && bcrf_itax_part(800, 0) === 0, 'free or untaxed: no tax');
$ok(bcrf_itax_part(10675, 675) === 675, '$106.75 at 6.75% included: exactly $6.75');
$ok(bcrf_itax_rate($pdo) === 675, 'general rate is 6.75% after the migrations');

// -- An online ticket order: 2 adults ($8) + 1 child ($5) + 1 under-3 (free) = $21 --
$gen = (int)$one("SELECT id FROM tax_categories WHERE code = 'general'");
$pdo->exec("INSERT INTO inventory (id, category, name, price, stock_quantity, product_type, track_inventory, tax_category_id, is_active) VALUES
            (901, 'Zoo', 'Adult Admission', 8.00, 0, 'retail', 0, $gen, 1),
            (902, 'Zoo', 'Childs Admission', 5.00, 0, 'retail', 0, NULL, 1),
            (903, 'Zoo', 'Under 3 Admission', 0.00, 0, 'retail', 0, $gen, 1)");
$order = json_encode([['product_id' => 901, 'name' => 'Adult', 'qty' => 2, 'unit_cents' => 800],
                      ['product_id' => 902, 'name' => 'Child', 'qty' => 1, 'unit_cents' => 500],
                      ['product_id' => 903, 'name' => 'Under 3', 'qty' => 1, 'unit_cents' => 0]]);
$ins = $pdo->prepare("INSERT INTO tickets (public_code, source, quantity, status, payment_status, amount_cents, order_json, created_at, paid_at)
                      VALUES (?, 'online', 4, 'issued', ?, ?, ?, UTC_TIMESTAMP(), ?)");
$ins->execute(['TAXTEST001', 'paid', 2100, $order, gmdate('Y-m-d H:i:s')]);
$tid = (int)$pdo->lastInsertId();
bcrf_itax_record_ticket($pdo, $tid);
$t = $pdo->query("SELECT tax_cents, tax_rate_bp FROM tickets WHERE id = $tid")->fetch();
$ok((int)$t['tax_cents'] === 101 + 32 && (int)$t['tax_rate_bp'] === 675, 'ticket order: $16 adult line $1.01 + $5 child line $0.32 = $1.33 tax, ' . json_encode($t));
$pdo->exec("UPDATE tickets SET tax_cents = 999 WHERE id = $tid");
bcrf_itax_record_ticket($pdo, $tid);
$ok((int)$one("SELECT tax_cents FROM tickets WHERE id = $tid") === 999, 'recording twice never overwrites');
$pdo->exec("UPDATE tickets SET tax_cents = 133 WHERE id = $tid");

// Lines that don't add up to the amount paid: whole amount at the general rate
$ins->execute(['TAXTEST002', 'paid', 1000, $order, gmdate('Y-m-d H:i:s')]);
$t2 = (int)$pdo->lastInsertId();
bcrf_itax_record_ticket($pdo, $t2);
$ok((int)$one("SELECT tax_cents FROM tickets WHERE id = $t2") === bcrf_itax_part(1000, 675), 'mismatched lines: amount paid taxed at the general rate');
$pdo->exec("UPDATE tickets SET payment_status = 'refunded' WHERE id = $t2");

// -- Passes: paid online, cash, comp --
$pdo->exec("INSERT INTO pass_types (id, type_key, label, price) VALUES (1, 'family', 'Family Pass', 120.00)");
$pp = $pdo->prepare("INSERT INTO passes (pass_type_id, holder_name, email, token, status, payment_status, payment_provider, amount_paid) VALUES (1, 'A', 'a@example.com', ?, 'active', ?, ?, ?)");
$pp->execute([str_repeat('a', 48), 'paid', 'stripe', 120.00]); $p1 = (int)$pdo->lastInsertId();
$pp->execute([str_repeat('b', 48), 'paid', 'comp', 120.00]);   $p2 = (int)$pdo->lastInsertId();
$pp->execute([str_repeat('c', 48), 'pending', 'stripe', 120.00]); $p3 = (int)$pdo->lastInsertId();
foreach ([$p1, $p2, $p3] as $id) bcrf_itax_record_pass($pdo, $id);
$r1 = $pdo->query("SELECT tax_cents, tax_rate_bp, paid_at FROM passes WHERE id = $p1")->fetch();
$ok((int)$r1['tax_cents'] === bcrf_itax_part(12000, 675) && (int)$r1['tax_cents'] === 759 && $r1['paid_at'] !== null, 'paid $120 pass: $7.59 tax, paid date set');
$ok((int)$one("SELECT tax_cents FROM passes WHERE id = $p2") === 0, 'comp pass: no tax');
$ok($one("SELECT tax_cents FROM passes WHERE id = $p3") === null, 'unpaid pass: nothing recorded');

// -- Monthly report rows --
$rows = bcrf_itax_rows($pdo, gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s', time() + 3600));
$by = array_column($rows, null, 'label');
$tk = $by['Online tickets (tax included)'] ?? null; $ps = $by['Annual passes (tax included)'] ?? null;
$ok($tk && $tk['orders'] === 1 && $tk['tax'] === 133 && $tk['net'] === 1967, 'report: one paid ticket order (refunded one left out), $19.67 + $1.33');
$ok($ps && $ps['orders'] === 1 && $ps['tax'] === 759 && $ps['net'] === 11241, 'report: one taxed pass (comp left out), $112.41 + $7.59');
$ok(bcrf_itax_rows($pdo, '2000-01-01 00:00:00', '2000-02-01 00:00:00') === [], 'report: nothing in another month');

// -- Gate register: admission priced tax-included (0061) --
require $root . '/includes/sales.php';
$cats = bcrf_tax_categories($pdo);
$adm = null; $genId = null;
foreach ($cats as $c) { if ($c['code'] === 'admission') $adm = $c; if ($c['code'] === 'general') $genId = $c['id']; }
$ok($adm !== null && $adm['rate_bp'] === 675 && $adm['included'] === true, 'admission tax category: 6.75%, included in the price');
$prod = [1 => ['id' => 1, 'name' => 'Adult', 'price' => '8.00', 'pricing_mode' => 'each', 'unit' => 'each', 'tax_category_id' => $adm['id'] ?? null, 'product_type' => 'retail', 'cat_label' => 'Admissions', 'track_inventory' => 0],
         2 => ['id' => 2, 'name' => 'Mug', 'price' => '10.00', 'pricing_mode' => 'each', 'unit' => 'each', 'tax_category_id' => $genId, 'product_type' => 'retail', 'cat_label' => 'Merch', 'track_inventory' => 0]];
$a = bcrf_sale_price([['product_id' => 1, 'qty' => 1]], $prod, $cats, 0, false, $genId);
$ok($a['subtotal'] === 749 && $a['discount'] === 0 && $a['tax'] === 51 && $a['total'] === 800, 'one $8 admission: $7.49 + $0.51 = $8.00, ' . json_encode([$a['subtotal'], $a['discount'], $a['tax'], $a['total']]));
$l = $a['lines'][0];
$ok($l['gross_cents'] - $l['discount_cents'] + $l['tax_cents'] === $l['line_total_cents'] && $l['line_total_cents'] === 800, 'admission line: net + tax = $8.00 paid');
$b = bcrf_sale_price([['product_id' => 1, 'qty' => 2], ['product_id' => 2, 'qty' => 1]], $prod, $cats, 1000, false, $genId);
$ok($b['total'] === 2401 && $b['tax'] === 152 && $b['subtotal'] === 2499 && $b['discount'] === 250, '2 admissions + $10 mug, 10% off: $14.40 incl. tax + $9.00 + $0.61 tax = $24.01, ' . json_encode([$b['subtotal'], $b['discount'], $b['tax'], $b['total']]));
$ok(array_sum(array_column($b['lines'], 'line_total_cents')) === $b['total'], 'lines add up to the sale total');
$c = bcrf_sale_price([['product_id' => 1, 'qty' => 1]], $prod, $cats, 0, true, $genId);
$ok($c['tax'] === 0 && $c['total'] === 800, 'tax-exempt sale of admission: $8.00, no tax');

echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
