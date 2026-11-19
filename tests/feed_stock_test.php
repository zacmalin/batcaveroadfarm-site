<?php
// tests/feed_stock_test.php — Feed in Stock (Nov 19, 2026): animal feed switched Off still shows in Stock, Counts, Receive,
// POs and low-stock; its column shows under the Feed Room; "Set the count" on an item; feedings draw from where the feed is
// kept (the Feed Room), not the default location. Against a real, THROWAWAY MySQL 8 database:
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/feed_stock_test.php
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
require_once $root . '/includes/low_stock.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };

// -- Seed: two feeds saved with On off (like Zac's), one ordinary product that's Off --
$feeding = (int)$one("SELECT id FROM animal_care_types WHERE seed_key = 'feeding'");
$pdo->exec("INSERT INTO classifications (class_name) VALUES ('Goat')");
$pdo->exec("INSERT INTO breeds (breed_name, slug) VALUES ('Nigerian Dwarf', 'nd')");
$pdo->exec("INSERT INTO animals (name, breed_id, classification_id, date_of_birth, is_active) VALUES ('Hazel', 1, 1, '2023-01-01', 1)");
$hazel = (int)$one("SELECT id FROM animals LIMIT 1");
$st = $pdo->prepare("INSERT INTO inventory (category, name, price, stock_quantity, min_stock_level, product_type, pricing_mode, unit, track_inventory, is_active, avg_cost_cents)
                     VALUES ('Zoo', ?, 0, 0, 1, ?, 'each', 'each', 1, ?, 1850)");
$st->execute(["Sweet Pelleted Feed, 50 lb. Bag", 'supply', 0]); $pellets = (int)$pdo->lastInsertId();
$st->execute(["Scratch Grains, 50 lb. Bag", 'retail', 0]); $scratch = (int)$pdo->lastInsertId();
$st->execute(["Old candle", 'retail', 0]); $candle = (int)$pdo->lastInsertId();
bcrf_care_set_feed_ids($pdo, [$pellets, $scratch]);
$feedRoom = (int)$one("SELECT id FROM locations WHERE code = 'feed'");
$def = bcrf_location_default($pdo);
$ok($feedRoom > 0 && $feedRoom !== $def, 'Feed Room exists and is not the default');

// -- Lists --
$ids = array_map(fn($r) => (int)$r['id'], bcrf_stock_levels($pdo));
$ok(in_array($pellets, $ids, true) && in_array($scratch, $ids, true), 'feed switched Off still shows on Stock › On hand');
$ok(!in_array($candle, $ids, true), 'other products switched Off stay hidden');
$fr = bcrf_stock_levels($pdo, ['location_id' => $feedRoom]);
$ok(count($fr) === 2 && (int)$fr[0]['is_feed'] === 1, 'Feed Room filter lists the feed before anything is counted there');
$ok(bcrf_stock_levels($pdo, ['location_id' => $def]) === [], 'Farmstand filter does not list feed with no stock there');
$inUse = array_map('intval', $pdo->query("SELECT id FROM inventory WHERE " . bcrf_stock_in_use_where() . " ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$ok(in_array($pellets, $inUse, true) && in_array($scratch, $inUse, true) && !in_array($candle, $inUse, true), 'Receive/PO product list: feed in, Off candle out');
$low = array_column(bcrf_low_stock_items($pdo), 'name');
$ok(in_array('Scratch Grains, 50 lb. Bag', $low, true) && !in_array('Old candle', $low, true), 'empty feed counts as low stock');

// -- Home location and Set the count --
$ok(bcrf_stock_home_location($pdo, $pellets) === $feedRoom, 'feed with nothing on hand is kept in the Feed Room');
$ok(bcrf_stock_home_location($pdo, $candle) === $def, 'other products default to the default location');
$r = bcrf_stock_quick_count($pdo, $pellets, $feedRoom, '3.5', 1);
$ok($r['before'] == 0 && $r['after'] == 3.5 && $r['location'] === 'Feed Room', 'Set the count: 0 → 3.5 at the Feed Room');
$ok((float)$one("SELECT stock_quantity FROM inventory WHERE id = ?", [$pellets]) === 3.5, 'total follows the count');
$ok($one("SELECT source_type FROM inventory_adjustments WHERE product_id = ? ORDER BY id DESC LIMIT 1", [$pellets]) === 'quick_count', 'count is in the history');
$r = bcrf_stock_quick_count($pdo, $pellets, $feedRoom, '3.5', 1);
$ok($r['before'] == $r['after'], 'same count again changes nothing');
try { bcrf_stock_quick_count($pdo, $pellets, $feedRoom, '-1', 1); $ok(false, 'negative count refused'); } catch (BcrfStockError $e) { $ok(true, ''); }
$r = bcrf_stock_quick_count($pdo, $pellets, $feedRoom, '0', 1);
$ok($r['after'] == 0, 'zero is a valid count');
bcrf_stock_quick_count($pdo, $pellets, $feedRoom, '4', 1);

// -- Count session at the Feed Room lists feed even with no level there --
$cid = bcrf_count_start($pdo, $feedRoom, 'all', null, false, '', 1);
$items = array_map(fn($i) => (int)$i['id'], bcrf_count_items($pdo, bcrf_count_get($pdo, $cid)));
$ok(in_array($scratch, $items, true) && in_array($pellets, $items, true), 'Feed Room count lists all feed');
$cid2 = bcrf_count_start($pdo, $def, 'all', null, false, '', 1);
$ok(!in_array($scratch, array_map(fn($i) => (int)$i['id'], bcrf_count_items($pdo, bcrf_count_get($pdo, $cid2))), true), 'Farmstand count does not list feed');

// -- Feedings come out of the Feed Room --
// Feed whose total sits on the Farmstand (changed outside Stock): the feeding repairs it first and draws from there
$pdo->prepare("UPDATE inventory SET stock_quantity = 2 WHERE id = ?")->execute([$scratch]);
bcrf_care_log($pdo, ['type' => $feeding, 'animals' => [$hazel], 'feed_product' => $scratch, 'feed_qty' => '1'], 1);
$ok((float)$one("SELECT stock_quantity FROM inventory WHERE id = ?", [$scratch]) === 1.0, 'feed with stock only on the Farmstand still draws, not short');
bcrf_care_log($pdo, ['type' => $feeding, 'animals' => [$hazel], 'feed_product' => $pellets, 'feed_qty' => '1'], 1);
$ok((float)$one("SELECT quantity FROM inventory_levels WHERE product_id = ? AND location_id = ?", [$pellets, $feedRoom]) === 3.0, 'feeding drew 1 bag from the Feed Room');
$ok($one("SELECT quantity FROM inventory_levels WHERE product_id = ? AND location_id = ?", [$pellets, $def]) === false, 'nothing drawn from the Farmstand');

echo $fails ? "$fails of $n checks FAILED\n" : "All $n checks passed\n";
exit($fails ? 1 : 0);
