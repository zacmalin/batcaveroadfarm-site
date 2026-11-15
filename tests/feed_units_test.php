<?php
// tests/feed_units_test.php — Feed in pounds and scoops (Nov 15, 2026; migration 0071, includes/animal_care.php) against a
// real, THROWAWAY MySQL 8 database: bag weight from Size or the name, scoops (every feed or one), logging in scoops, lb, oz
// and bags (stock and cost in bags), 3-decimal stock, "3 bags + 12 lb", last unit per feed, the old way still working.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/feed_units_test.php
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
require_once $root . '/includes/animal_care.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (BcrfCareError $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};

// -- Migration --
$ok(bcrf_feed_units_ready($pdo), '0071 tables and columns exist');
$ok((int)$one("SELECT NUMERIC_SCALE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inventory' AND column_name = 'stock_quantity'") === 3, 'stock_quantity keeps 3 decimals');
$ok(array_column(bcrf_feed_measures($pdo), 'name') === ['6 fl oz scoop', '2 pint scoop'], 'Zac\'s two scoops preloaded');

// -- Seed --
$feeding = (int)$one("SELECT id FROM animal_care_types WHERE seed_key = 'feeding'");
$pdo->exec("INSERT INTO classifications (class_name) VALUES ('Goat')");
$pdo->exec("INSERT INTO breeds (breed_name, slug) VALUES ('Nigerian Dwarf', 'nd')");
$pdo->exec("INSERT INTO animals (name, breed_id, classification_id, date_of_birth, is_active) VALUES ('Hazel', 1, 1, '2023-01-01', 1), ('Clover', 1, 1, '2023-01-01', 1)");
[$hazel, $clover] = array_map('intval', $pdo->query("SELECT id FROM animals ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$ins = "INSERT INTO inventory (category, name, price, stock_quantity, product_type, pricing_mode, unit, track_inventory, is_active, avg_cost_cents) VALUES ('Zoo', ?, 0, ?, 'supply', 'each', ?, 1, 1, ?)";
$st = $pdo->prepare($ins);
$st->execute(["Producer's Pride Sweet Pelleted Feed, 50 lb. Bag", 10, 'each', 1850]); $pellets = (int)$pdo->lastInsertId();
$st->execute(['Whole corn', 4, 'each', 1500]); $corn = (int)$pdo->lastInsertId();
$st->execute(['Loose minerals', 20, 'lb', 120]); $minerals = (int)$pdo->lastInsertId();
$st->execute(['Hay', 5, 'each', 900]); $hay = (int)$pdo->lastInsertId();
bcrf_care_set_feed_ids($pdo, [$pellets, $corn, $minerals, $hay]);
(require $root . '/admin/migrations/0071_feed_units.php')($pdo);   // repeat: also fills bag weight from the name
$ok(count(bcrf_feed_measures($pdo)) === 2, 'repeat adds no scoops');
$ok((float)$one("SELECT size_value FROM inventory WHERE id = ?", [$pellets]) === 50.0 && $one("SELECT size_value FROM inventory WHERE id = ?", [$corn]) === null, 'bag weight read from the name, only where it says');
$throws(fn() => bcrf_feed_bag_save($pdo, $corn, 'x'), 'bad bag weight refused', 'pounds');
bcrf_feed_bag_save($pdo, $corn, '40');
$cornScoop = bcrf_feed_measure_save($pdo, 0, 'Corn can', '3', 'lb', $corn);
$throws(fn() => bcrf_feed_measure_save($pdo, 0, '2 pint scoop', '4', 'lb', null), 'duplicate scoop refused', 'already');
$throws(fn() => bcrf_feed_measure_save($pdo, 0, 'Cup', '0', 'lb', null), 'zero weight refused', 'weigh');
$throws(fn() => bcrf_feed_measure_save($pdo, 0, 'Cup', '1', 'kg', null), 'bad unit refused', 'lb or oz');

$fp = array_column(bcrf_care_feed_products($pdo), null, 'id');
$ok(abs($fp[$pellets]['unit_g'] - 50 * 453.59237) < 0.01 && abs($fp[$minerals]['unit_g'] - 453.59237) < 0.01 && $fp[$hay]['unit_g'] === null, 'bag weight from Size, from a lb unit, or unknown');
$ok($fp[$pellets]['stock_text'] === '10 bags' && $fp[$minerals]['stock_text'] === '20 lb' && $fp[$hay]['units'] === [], 'stock words; no units without a bag weight');
$ok(array_column($fp[$corn]['units'], 'key') === ['m' . $cornScoop, 'm1', 'm2', 'lb', 'oz', 'bag'] && !in_array('m' . $cornScoop, array_column($fp[$pellets]['units'], 'key'), true), 'own scoop first, only on its feed');
$ok(!in_array('bag', array_column($fp[$minerals]['units'], 'key'), true), 'no bags for feed counted in lb');

// -- Logging in scoops, lb, oz, bags --
$r = bcrf_care_log($pdo, ['type' => $feeding, 'animals' => [$hazel, $clover], 'feed_product' => $pellets, 'feed_qty' => '2', 'feed_unit' => 'm2'], 1);
$row = $pdo->query("SELECT * FROM animal_care_logs WHERE id = " . (int)$r['id'])->fetch();
$ok((float)$row['feed_qty'] === 0.16 && (float)$row['feed_lb'] === 8.0 && (float)$row['feed_entered_qty'] === 2.0 && $row['feed_entered_unit'] === '2 pint scoop', '2 x 2 pint scoop = 8 lb = 0.16 bag');
$ok((int)$row['feed_cost_cents'] === 296, 'cost 0.16 x $18.50');
$ok((float)$one("SELECT stock_quantity FROM inventory WHERE id = ?", [$pellets]) === 9.84, 'stock down 0.16 bag');
$ok(bcrf_feed_amount_text($row + ['feed_name' => 'Pellets']) === '2 × 2 pint scoop (8 lb) of Pellets', 'reads as scoops');
$r = bcrf_care_log($pdo, ['type' => $feeding, 'animals' => [$hazel], 'feed_product' => $pellets, 'feed_qty' => '1', 'feed_unit' => 'm1'], 1);
$row = $pdo->query("SELECT * FROM animal_care_logs WHERE id = " . (int)$r['id'])->fetch();
$ok((float)$row['feed_qty'] === 0.005 && (float)$row['feed_lb'] === 0.25 && (int)$row['feed_cost_cents'] === 9, '6 fl oz scoop = 4 oz = 0.005 bag, 9 cents');
$ok(bcrf_feed_amount_text($row + ['feed_name' => 'Pellets']) === '1 × 6 fl oz scoop (4 oz) of Pellets', 'small scoop reads in oz');
$r = bcrf_care_log($pdo, ['type' => $feeding, 'animals' => [$hazel], 'feed_product' => $corn, 'feed_qty' => '1', 'feed_unit' => 'lb'], 1);
$ok((float)$one("SELECT feed_qty FROM animal_care_logs WHERE id = ?", [$r['id']]) === 0.025, '1 lb of a 40 lb bag = 0.025 (needs 3 decimals)');
$ok((float)$one("SELECT stock_quantity FROM inventory WHERE id = ?", [$corn]) === 3.975, 'corn stock 3.975');
$fp = array_column(bcrf_care_feed_products($pdo), null, 'id');
$ok($fp[$corn]['last_unit'] === 'lb' && $fp[$pellets]['last_unit'] === '6 fl oz scoop', 'last unit per feed');
$ok($fp[$corn]['stock_text'] === '3 bags + 39 lb' && $fp[$pellets]['stock_text'] === '9 bags + 41.8 lb', 'stock as bags + lb: ' . $fp[$corn]['stock_text'] . ' / ' . $fp[$pellets]['stock_text']);
$r = bcrf_care_log($pdo, ['type' => $feeding, 'animals' => [$hazel], 'feed_product' => $minerals, 'feed_qty' => '8', 'feed_unit' => 'oz'], 1);
$ok((float)$one("SELECT feed_qty FROM animal_care_logs WHERE id = ?", [$r['id']]) === 0.5 && (float)$one("SELECT stock_quantity FROM inventory WHERE id = ?", [$minerals]) === 19.5, '8 oz of a feed counted in lb = 0.5');
$r = bcrf_care_log($pdo, ['type' => $feeding, 'animals' => [$hazel], 'feed_product' => $corn, 'feed_qty' => '1', 'feed_unit' => 'bag'], 1);
$row = $pdo->query("SELECT * FROM animal_care_logs WHERE id = " . (int)$r['id'])->fetch();
$ok((float)$row['feed_qty'] === 1.0 && (float)$row['feed_lb'] === 40.0 && bcrf_feed_amount_text($row + ['feed_name' => 'Corn']) === '1 bag (40 lb) of Corn', 'one whole bag');
$bagLog = (int)$r['id'];

// -- Refusals and the old way --
$throws(fn() => bcrf_care_log($pdo, ['type' => $feeding, 'animals' => [$hazel], 'feed_product' => $hay, 'feed_qty' => '2', 'feed_unit' => 'lb']), 'lb without a bag weight refused', 'bag weight');
$throws(fn() => bcrf_care_log($pdo, ['type' => $feeding, 'animals' => [$hazel], 'feed_product' => $pellets, 'feed_qty' => '2', 'feed_unit' => 'm' . $cornScoop]), 'another feed\'s scoop refused', 'scoops');
$r = bcrf_care_log($pdo, ['type' => $feeding, 'animals' => [$hazel], 'feed_product' => $hay, 'feed_qty' => '1'], 1);
$row = $pdo->query("SELECT * FROM animal_care_logs WHERE id = " . (int)$r['id'])->fetch();
$ok((float)$row['feed_qty'] === 1.0 && $row['feed_entered_unit'] === null && (int)$row['feed_cost_cents'] === 900 && bcrf_feed_amount_text($row + ['feed_name' => 'Hay']) === '1 of Hay', 'no unit = old way, in the product\'s unit');

// -- Void, remove a scoop --
bcrf_care_void($pdo, $bagLog, 'test', 1);
$ok((float)$one("SELECT stock_quantity FROM inventory WHERE id = ?", [$corn]) === 3.975, 'void puts the bag back');
bcrf_feed_measure_remove($pdo, 2);
$ok(array_column(bcrf_feed_measures($pdo), 'name') === ['6 fl oz scoop', 'Corn can'], 'removed scoop no longer offered');
$ok($one("SELECT feed_entered_unit FROM animal_care_logs WHERE feed_entered_unit = '2 pint scoop' LIMIT 1") === '2 pint scoop', 'past feedings keep the scoop name');
$ok(bcrf_feed_weight_text(0.25) === '4 oz' && bcrf_feed_weight_text(6.5) === '6.5 lb', 'weight words');

echo ($fails ? "$fails of $n FAILED" : "All $n checks passed") . "\n";
exit($fails ? 1 : 0);
