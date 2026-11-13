<?php
// tests/kitchen_batches_test.php — Farm OS Phase 4 slice 3 (Nov 13, 2026): production batches and lot codes
// (includes/kitchen_batches.php, migration 0053) against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/kitchen_batches_test.php
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
require_once $root . '/includes/kitchen_batches.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (BcrfKitchenError $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};

$ok(bcrf_kitchen_batches_ready($pdo), 'migration 0053 ran');
(require $root . '/admin/migrations/0053_kitchen_batches.php')($pdo);
$ok(bcrf_kitchen_batches_ready($pdo), 'migration repeats safely');

$loc = array_column($pdo->query("SELECT id, code FROM locations")->fetchAll(), 'id', 'code');
$pdo->exec("INSERT INTO measurement_units (id, name, system_type, abbreviation, unit_type) VALUES
  (1, 'Gram', 'Metric', 'g', 'Weight'), (2, 'Ounce', 'US', 'oz', 'Weight'), (3, 'Pound', 'US', 'lb', 'Weight'), (4, 'Cup', 'US', 'c', 'Volume'), (9, 'Whole', 'US', NULL, 'Count')");
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role) VALUES (7, 'Zac', 'M', 'z@example.com', 'administrator')");
$ins = $pdo->prepare("INSERT INTO ingredients (singular_name, plural_name, density_g_per_ml, weight_per_unit_g, allergens) VALUES (?, ?, ?, ?, ?)");
foreach ([['strawberry', 'strawberries', null, 15, ''], ['sugar', 'sugar', 0.85, null, ''], ['lemon juice', 'lemon juice', 1.03, null, '']] as $row) $ins->execute($row);
$ing = array_column($pdo->query("SELECT id, singular_name FROM ingredients")->fetchAll(), 'id', 'singular_name');
$pdo->exec("INSERT INTO recipes (id, title, slug, date_published, status) VALUES (1, 'Strawberry Jam', 'strawberry-jam', '2026-11-12', 'published')");
$ri = $pdo->prepare("INSERT INTO recipe_ingredients (recipe_id, ingredient_id, quantity, unit_id) VALUES (?, ?, ?, ?)");
$ri->execute([1, $ing['strawberry'], 100, 9]);    // 1500 g
$ri->execute([1, $ing['sugar'], 4, 4]);           // ~806 g
$ri->execute([1, $ing['lemon juice'], 0.25, 4]);  // ~61 g
// Jam (stocked, 8 oz), sugar 4 lb bags (stocked, 10 at the Kitchen), lemon juice (not stocked)
$pdo->exec("INSERT INTO inventory (id, category, name, price, stock_quantity, size_value, size_unit_id, sku, product_type, track_inventory) VALUES
  (21, 'Pantry', 'Strawberry Jam 8 oz', 8.00, 0, 8, 2, 'JAM-8', 'kitchen_good', 1),
  (31, 'Pantry', 'Sugar 4 lb', 4.00, 0, 4, 3, 'SUG4', 'retail', 1),
  (32, 'Pantry', 'Lemon juice', 3.00, 0, NULL, NULL, 'LEM', 'retail', 0),
  (33, 'Pantry', 'Strawberries flat', 20.00, 0, NULL, NULL, 'STR', 'produce', 1)");
bcrf_stock_adjust($pdo, 31, $loc['kitchen'], 10, 'receive', 1, '', 7);
$ok((float)$one("SELECT stock_quantity FROM inventory WHERE id = 31") == 10, 'sugar received at the Kitchen');

// -- Ingredient → stock product --
$ok(bcrf_kitchen_ingredient_stock_set($pdo, $ing['sugar'], 31) === true, 'link sugar to Sugar 4 lb');
$ok(bcrf_kitchen_ingredient_stock_set($pdo, $ing['sugar'], 31) === false, 'same link: nothing changed');
$throws(fn() => bcrf_kitchen_ingredient_stock_set($pdo, $ing['lemon juice'], 32), 'non-stocked product refused', 'counts stock');
bcrf_kitchen_ingredient_stock_set($pdo, $ing['strawberry'], 33);   // stocked, but no weight size
$ok(count(bcrf_kitchen_stock_products($pdo)) === 3, 'stock product picker: stocked items only');

// -- Blockers --
$throws(fn() => bcrf_kitchen_batch_record($pdo, 21, ['batches' => 1, 'made_on' => date('Y-m-d')], 7), 'not linked', 'Link this product');
bcrf_kitchen_product_save($pdo, 21, ['recipe_id' => 1, 'units_per_batch' => 12, 'shelf_life_days' => 365], 7);
$throws(fn() => bcrf_kitchen_batch_record($pdo, 21, ['batches' => 1, 'made_on' => date('Y-m-d')], 7), 'no version yet', 'version');
$v1 = bcrf_kitchen_save_version($pdo, 1, 7);
$plan = bcrf_kitchen_batch_plan($pdo, 21, 2);
$ok(!$plan['blockers'] && $plan['units_default'] == 24, 'plan: 2 batches make 24');
$ok(in_array(true, array_map(fn($w) => str_contains($w, 'NCDA'), $plan['warnings']), true), 'plan warns: NCDA type not confirmed');
$byName = array_column($plan['lines'], null, 'name');
$ok($byName['sugar']['stock_id'] === 31 && abs($byName['sugar']['qty'] - round($byName['sugar']['grams'] / 1814.37, 2)) < 0.011, 'sugar: grams ÷ grams in a 4 lb bag = ' . $byName['sugar']['qty']);
$ok($byName['lemon juice']['stock_id'] === null && str_contains($byName['lemon juice']['why_not'], 'Not linked'), 'lemon juice: not linked');
$ok($byName['strawberry']['stock_id'] === null && str_contains($byName['strawberry']['why_not'], 'weight size'), 'strawberries: product has no weight size');
$throws(fn() => bcrf_kitchen_batch_record($pdo, 21, ['batches' => 1, 'made_on' => date('Y-m-d', strtotime('+1 day'))], 7), 'future date', 'future');
$throws(fn() => bcrf_kitchen_batch_record($pdo, 21, ['batches' => 0, 'made_on' => date('Y-m-d')], 7), 'zero batches', 'between');
$throws(fn() => bcrf_kitchen_batch_record($pdo, 21, ['batches' => 1, 'made_on' => '2026-02-30'], 7), 'bad date', 'day');

// -- Record --
$today = date('Y-m-d');
$b = bcrf_kitchen_batch_record($pdo, 21, ['batches' => 2, 'made_on' => $today, 'location_id' => $loc['farmstand'], 'take_ingredients' => 1, 'notes' => ' first  run '], 7);
$ok($b['lot_code'] === date('ymd') . '-JAM8-1', 'lot code yymmdd-SKU-1: ' . $b['lot_code']);
$ok((float)$b['units_made'] == 24 && $b['best_by'] === date('Y-m-d', strtotime('+365 days')) && (int)$b['recipe_version_id'] === (int)$v1['version']['id'], 'units, best-by, version');
$ok($b['notes'] === 'first run' && (int)$b['stock_added'] === 1 && (int)$b['ingredients_taken'] === 1, 'notes cleaned; stock added; ingredients taken');
$ok((float)$one("SELECT quantity FROM inventory_levels WHERE product_id = 21 AND location_id = ?", [$loc['farmstand']]) == 24, '24 jars at the Farmstand');
$sugarTaken = (float)$one("SELECT qty_taken FROM kitchen_batch_ingredients WHERE batch_id = ? AND inventory_id = 31", [$b['id']]);
$ok(abs($sugarTaken - $byName['sugar']['qty']) < 0.001 && abs((float)$one("SELECT quantity FROM inventory_levels WHERE product_id = 31 AND location_id = ?", [$loc['kitchen']]) - (10 - $sugarTaken)) < 0.001, 'sugar taken from the Kitchen');
$ok(count($b['ingredients']) === 3 && abs((float)array_column($b['ingredients'], 'grams', 'name')['strawberry'] - 3000) < 0.01, 'every line\'s grams kept (strawberries 3000 g)');
$ok((string)$one("SELECT reason FROM inventory_adjustments WHERE product_id = 21 ORDER BY id DESC LIMIT 1") === 'produced'
    && (string)$one("SELECT source_type FROM inventory_adjustments WHERE product_id = 31 ORDER BY id DESC LIMIT 1") === 'kitchen_batch', 'ledger: produced and batch_use from the batch');
$b2 = bcrf_kitchen_batch_record($pdo, 21, ['batches' => 1, 'units_made' => '11', 'made_on' => $today, 'location_id' => $loc['kitchen']], 7);
$ok($b2['lot_code'] === date('ymd') . '-JAM8-2' && (float)$b2['units_made'] == 11 && (int)$b2['ingredients_taken'] === 0, 'second batch same day: -2; actual units; ingredients left alone');
$ok(abs((float)$one("SELECT stock_quantity FROM inventory WHERE id = 31") - (10 - $sugarTaken)) < 0.001, 'sugar untouched without the box');
$b3 = bcrf_kitchen_batch_record($pdo, 21, ['batches' => 1, 'made_on' => date('Y-m-d', strtotime('-1 day'))], 7);
$ok($b3['lot_code'] === date('ymd', strtotime('-1 day')) . '-JAM8-1', 'yesterday starts at 1 again');
$ok(count(bcrf_kitchen_batches($pdo)) === 3 && bcrf_kitchen_batches($pdo)[0]['lot_code'] !== $b3['lot_code'], 'list newest made-on first');
$ok(count(bcrf_kitchen_batches($pdo, ['q' => 'JAM8-2'])) === 1, 'search by lot code');

// Short on stock: takes what there is, batch still recorded
$pdo->exec("UPDATE ingredients SET inventory_id = 31 WHERE id = " . (int)$ing['sugar']);
$b4 = bcrf_kitchen_batch_record($pdo, 21, ['batches' => 40, 'made_on' => $today, 'take_ingredients' => 1], 7);
$ok((float)$one("SELECT stock_quantity FROM inventory WHERE id = 31") == 0 && (float)$one("SELECT qty_taken FROM kitchen_batch_ingredients WHERE batch_id = ? AND inventory_id = 31", [$b4['id']]) > 0, 'not enough sugar: takes what is there, batch recorded');

// Recipe changed since the version: blocked
$pdo->exec("UPDATE recipe_ingredients SET quantity = 5 WHERE recipe_id = 1 AND ingredient_id = " . (int)$ing['sugar']);
$throws(fn() => bcrf_kitchen_batch_record($pdo, 21, ['batches' => 1, 'made_on' => $today], 7), 'changed recipe blocked', 'changed since version 1');
$pdo->exec("UPDATE recipe_ingredients SET quantity = 4 WHERE recipe_id = 1 AND ingredient_id = " . (int)$ing['sugar']);
$ok(!bcrf_kitchen_batch_plan($pdo, 21)['blockers'], 'back as it was: allowed again');

// -- Void --
$throws(fn() => bcrf_kitchen_batch_void($pdo, $b['id'], '  ', 7), 'void needs a reason', 'why');
$before31 = (float)$one("SELECT stock_quantity FROM inventory WHERE id = 31");
$beforeFs = (float)$one("SELECT quantity FROM inventory_levels WHERE product_id = 21 AND location_id = ?", [$loc['farmstand']]);
$v = bcrf_kitchen_batch_void($pdo, $b['id'], 'test batch', 7);
$ok($v['voided_at'] !== null && $v['void_reason'] === 'test batch', 'voided with reason');
$ok((float)$one("SELECT quantity FROM inventory_levels WHERE product_id = 21 AND location_id = ?", [$loc['farmstand']]) == $beforeFs - 24, 'its 24 jars taken back out of the Farmstand');
$ok(abs((float)$one("SELECT stock_quantity FROM inventory WHERE id = 31") - ($before31 + $sugarTaken)) < 0.001, 'sugar put back');
$throws(fn() => bcrf_kitchen_batch_void($pdo, $b['id'], 'again', 7), 'void twice refused', 'already');
// Sold some: void refused
bcrf_stock_adjust($pdo, 21, $loc['kitchen'], 5, 'internal_use', -1, '', 7);
$throws(fn() => bcrf_kitchen_batch_void($pdo, $b2['id'], 'oops', 7), 'void after some were used/sold refused', 'already been sold');
$ok($one("SELECT voided_at FROM kitchen_batches WHERE id = ?", [$b2['id']]) === null, 'refused void changed nothing');
$ok($one("SELECT COUNT(*) FROM kitchen_batches") == 4, 'batches are never deleted');

// Stock levels still equal totals
$bad = (int)$one("SELECT COUNT(*) FROM inventory i WHERE i.track_inventory = 1 AND ABS(i.stock_quantity - COALESCE((SELECT SUM(quantity) FROM inventory_levels l WHERE l.product_id = i.id), 0)) > 0.0005");
$ok($bad === 0, 'every total equals its location levels');
$ok((int)$one("SELECT COUNT(*) FROM inventory_adjustments WHERE reason = 'sync'") === 0, 'no drift repairs needed');
$ok(bcrf_kitchen_lot_part('', 9) === 'P9' && bcrf_kitchen_lot_part('bcf-004', 1) === 'BCF004', 'lot part from SKU');

echo $fails ? "$fails of $n checks failed\n" : "All $n checks passed\n";
exit($fails ? 1 : 0);
