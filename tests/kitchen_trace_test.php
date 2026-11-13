<?php
// tests/kitchen_trace_test.php — Farm OS Phase 4 slice 7 (Nov 13, 2026): lot tracing for recalls (includes/kitchen_trace.php)
// against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/kitchen_trace_test.php
// Refuses to run unless DB_NAME ends in "_test". Empties that database and builds it from every migration. Exit 0 = all passed.
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$dsn = 'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4';
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', $opts);
$GLOBALS['pdo'] = $pdo;
$root = dirname(__DIR__);
ini_set('log_errors', '0');
date_default_timezone_set('America/New_York');

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }
require_once $root . '/includes/kitchen_trace.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$ok(bcrf_kitchen_trace_ready($pdo), 'trace ready');

// -- Setup: jam (counts stock) and bread (doesn't); two customers --
$pdo->exec("INSERT INTO inventory (id, category, name, price, stock_quantity, sku, product_type, track_inventory) VALUES
  (21, 'Pantry', 'Strawberry Jam 8 oz', 8.00, 0, 'JAM8', 'kitchen_good', 1), (22, 'Bakery', 'Sourdough', 9.00, 0, 'BRD', 'kitchen_good', 0)");
$pdo->exec("INSERT INTO customers (id, first_name, last_name, email, phone_number) VALUES (1, 'Ann', 'Apple', 'ann@example.com', '828-555-0101'), (2, 'Cara', 'Cole', 'cara@example.com', NULL), (3, 'Dan', 'Dee', 'dan@example.com', NULL)");
$pdo->exec("INSERT INTO recipes (id, title, slug, date_published, status) VALUES (1, 'Jam', 'jam', '2026-11-01', 'published')");
$pdo->exec("INSERT INTO recipe_versions (id, recipe_id, version_no, fingerprint, statement, snapshot, created_at) VALUES (1, 1, 1, REPEAT('a', 64), 'Strawberries', '{}', UTC_TIMESTAMP())");
$bat = $pdo->prepare("INSERT INTO kitchen_batches (id, lot_code, inventory_id, recipe_id, recipe_version_id, batches, units_made, made_on, best_by, location_id, stock_added, created_at)
                      VALUES (?, ?, ?, 1, 1, 1, ?, ?, ?, 1, ?, UTC_TIMESTAMP())");
$bat->execute([1, '261101-JAM8-1', 21, 10, '2026-11-01', '2027-11-01', 1]);
$bat->execute([2, '261102-JAM8-1', 21, 10, '2026-11-02', '2027-11-02', 1]);
$bat->execute([3, '261103-JAM8-1', 21, 5, '2026-11-03', '2027-11-03', 1]);
$bat->execute([4, '261101-BRD-1', 22, 12, '2026-11-01', '2026-11-05', 0]);
$bat->execute([5, '261104-BRD-1', 22, 12, '2026-11-04', '2026-11-08', 0]);
$sale = function (int $id, ?int $cust, string $at, int $pid, float $qty, string $status = 'completed', string $payer = '') use ($pdo) {
    $pdo->prepare("INSERT INTO sales (id, sale_number, channel, customer_id, status, business_date, completed_at, idempotency_key) VALUES (?, ?, 'pos', ?, ?, ?, ?, ?)")
        ->execute([$id, 'S-' . $id, $cust, $status, substr($at, 0, 10), $at, 'k' . $id]);
    $pdo->prepare("INSERT INTO sale_lines (sale_id, line_no, product_id, description, quantity, unit_price_cents, gross_cents, line_total_cents) VALUES (?, 1, ?, 'x', ?, 800, 800, 800)")
        ->execute([$id, $pid, $qty]);
    if ($payer !== '') $pdo->prepare("INSERT INTO payments (sale_id, tender, amount_cents, payer_handle, business_date, created_at) VALUES (?, 'venmo', 800, ?, ?, ?)")->execute([$id, $payer, substr($at, 0, 10), $at]);
};
$led = function (int $pid, float $q, string $reason, ?string $type, ?int $src, string $at) use ($pdo) {
    $pdo->prepare("INSERT INTO inventory_adjustments (product_id, location_id, quantity_change, quantity_before, quantity_after, reason, source_type, source_id, created_at)
                   VALUES (?, 1, ?, 0, 0, ?, ?, ?, ?)")->execute([$pid, $q, $reason, $type, $src, $at]);
};
// Jam ledger (UTC times)
$led(21, 4, 'opening', 'migration', null, '2026-11-01 10:00:00');                 // unknown lot: 4
$led(21, 10, 'produced', 'kitchen_batch', 1, '2026-11-01 15:00:00');             // lot A: 10
$sale(1, 1, '2026-11-01 16:00:00', 21, 3); $led(21, -3, 'sale', 'sale', 1, '2026-11-01 16:00:00');   // Ann: 3 from unknown
$led(21, 10, 'produced', 'kitchen_batch', 2, '2026-11-02 15:00:00');             // lot B: 10
$sale(2, null, '2026-11-02 16:00:00', 21, 5, 'completed', '@bob'); $led(21, -5, 'sale', 'sale', 2, '2026-11-02 16:00:00');   // 1 unknown + 4 A
$led(21, -1, 'spoilage', 'manual', null, '2026-11-02 17:00:00');                  // 1 A
$led(21, 2, 'transfer_in', 'transfer', 9, '2026-11-02 18:00:00'); $led(21, -2, 'transfer_out', 'transfer', 9, '2026-11-02 18:00:00');
$sale(3, 2, '2026-11-02 19:00:00', 21, 4, 'voided'); $led(21, -4, 'sale', 'sale', 3, '2026-11-02 19:00:00');   // 4 A (A empty)
$led(21, 4, 'void', 'sale', 3, '2026-11-02 19:30:00');                            // back to A
$sale(4, 1, '2026-11-03 12:00:00', 21, 6, 'partially_refunded'); $led(21, -6, 'sale', 'sale', 4, '2026-11-03 12:00:00');   // 5 A + 1 B
$pdo->exec("INSERT INTO refunds (id, refund_number, sale_id, business_date, net_cents, tax_cents, total_cents, reason, idempotency_key, created_at) VALUES (1, 'R-1', 4, '2026-11-03', 800, 0, 800, 'dented', 'r1', '2026-11-03 13:00:00')");
$led(21, 1, 'refund_restock', 'refund', 1, '2026-11-03 13:00:00');               // back to B (newest lot first)
$led(21, 5, 'produced', 'kitchen_batch', 3, '2026-11-03 14:00:00');              // lot C: 5
$led(21, -5, 'batch_void', 'kitchen_batch', 3, '2026-11-03 14:30:00');           // lot C voided: its own units go
$sale(5, 3, '2026-11-04 12:00:00', 21, 2); $led(21, -2, 'sale', 'sale', 5, '2026-11-04 12:00:00');   // 2 B

$f = bcrf_kitchen_fifo($pdo, 21);
$A = $f['lots']['b1']; $B = $f['lots']['b2']; $C = $f['lots']['b3']; $U = $f['lots']['u1'];
$ok($U['in'] === 4000 && $U['sold'] === 4000 && $U['left'] === 0 && $U['end'] === '2026-11-02 16:00:00', 'opening stock is an unknown lot, sold first');
$ok($A['in'] === 10000 && $A['sold'] === 9000 && ($A['removed']['spoilage'] ?? 0) === 1000 && $A['left'] === 0, 'lot A: 9 sold, 1 spoiled, none left');
$ok($A['end'] === '2026-11-03 12:00:00', 'lot A: off the shelf when sale 4 took the last (void put units back)');
$ok($f['sales']['sale:3']['qty'] === 0 && !$f['sales']['sale:3']['from'], 'voided sale gave its units back');
$ok($f['sales']['sale:4']['from'] === ['b1' => 5000], 'refund put the unit back on lot B (newest first): sale 4 = 5 from A');
$ok($B['in'] === 10000 && $B['sold'] === 2000 && $B['left'] === 8000 && $B['end'] === null, 'lot B: 2 sold, 8 left, still on the shelf');
$ok($C['in'] === 5000 && ($C['removed']['batch_void'] ?? 0) === 5000 && $C['left'] === 0 && $B['left'] === 8000, 'batch void takes its own lot, not the oldest');
$ok(!isset($f['lots']['u2']), 'transfers ignored (no new unknown lot)');

$t = bcrf_kitchen_lot_trace($pdo, 1);
$ok($t['mode'] === 'fifo' && $t['from'] === '2026-11-01 15:00:00' && $t['to'] === '2026-11-03 12:00:00', 'lot A traced first in first out, shelf window');
$ok(array_column($t['likely'], 'number') === ['S-2', 'S-4'] && array_column($t['likely'], 'qty') === [4.0, 5.0], 'lot A likely: S-2 (4), S-4 (5)');
$ok(array_column($t['possible'], 'number') === ['S-1'], 'lot A possible: S-1 only (voided S-3 left out, S-5 after the window)');
$ok($t['likely'][0]['customer_id'] === null && $t['likely'][0]['payer'] === '@bob' && $t['likely'][1]['email'] === 'ann@example.com' && $t['likely'][1]['phone'] === '828-555-0101', 'who bought: payer handle for no-customer sale, contact for Ann');
$c = bcrf_kitchen_trace_contacts($t);
$ok(count($c) === 2 && $c[1]['name'] === 'Ann Apple' && $c[1]['list'] === 'likely' && $c[1]['sales'] === ['S-4', 'S-1'] && abs($c[1]['qty'] - 8) < 0.001, 'contacts: one row per customer, likely first, sales merged');
$t = bcrf_kitchen_lot_trace($pdo, 2);
$ok(array_column($t['likely'], 'number') === ['S-5'] && array_column($t['possible'], 'number') === ['S-2', 'S-4'] && $t['lot']['end'] === null, 'lot B: likely S-5; possible S-2, S-4 while on the shelf');
$t = bcrf_kitchen_lot_trace($pdo, 3);
$ok(!$t['likely'] && $t['lot']['left'] === 0, 'voided lot C: nothing sold');

// Bread doesn't count stock: sales from made-on day to the earlier of best-by and the next batch (farm time)
$sale(10, 1, bcrf_kitchen_farm_day_utc('2026-11-01') , 22, 1);                     // midnight ET Nov 1: in
$sale(11, 2, '2026-11-02 15:00:00', 22, 2);                                        // in
$sale(12, 3, '2026-11-03 15:00:00', 22, 1, 'voided');                              // voided: out
$sale(13, 3, '2026-11-05 03:00:00', 22, 1);                                        // Nov 4 11 PM ET: in (next batch day)
$sale(14, 3, '2026-11-05 15:00:00', 22, 1);                                        // Nov 5: out
$sale(15, 1, '2026-11-01 03:00:00', 22, 1);                                        // Oct 31 11 PM ET: out
$t = bcrf_kitchen_lot_trace($pdo, 4);
$ok($t['mode'] === 'window' && !$t['likely'] && str_contains($t['note'], 'doesn\'t count stock') && str_contains($t['note'], 'next batch'), 'no-stock product: window mode');
$ok(array_column($t['possible'], 'number') === ['S-10', 'S-11', 'S-13'], 'window: made-on day to next batch, farm time, voided left out: ' . implode(',', array_column($t['possible'], 'number')));
$t = bcrf_kitchen_lot_trace($pdo, 5);
$ok(array_column($t['possible'], 'number') === ['S-13', 'S-14'], 'last bread lot: to its best-by');

$ok((int)bcrf_kitchen_lot_find($pdo, ' 261101-jam8-1 ')['id'] === 1 && bcrf_kitchen_lot_find($pdo, 'nope') === null && bcrf_kitchen_lot_find($pdo, '') === null, 'find a lot by code, case and spaces ignored');
try { bcrf_kitchen_lot_trace($pdo, 99); $ok(false, 'missing lot refused'); } catch (BcrfKitchenError $e) { $ok(true, 'missing lot refused'); }
$ok(bcrf_kitchen_farm_day_utc('2026-11-01') === '2026-11-01 04:00:00' && bcrf_kitchen_farm_day_utc('2026-11-10', true) === '2026-11-11 04:59:59', 'farm day to UTC across the DST change');

echo ($fails ? "$fails of $n FAILED" : "All $n passed"), "\n";
exit($fails ? 1 : 0);
