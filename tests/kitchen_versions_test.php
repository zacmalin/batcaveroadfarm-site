<?php
// tests/kitchen_versions_test.php — Farm OS Phase 4 slice 2 (Nov 12, 2026): recipe versions and recipe → product links
// (includes/kitchen_versions.php, migration 0051) against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/kitchen_versions_test.php
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
require_once $root . '/includes/kitchen_versions.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (BcrfKitchenError $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};

$ok(bcrf_kitchen_versions_ready($pdo), 'migration 0051 ran');
(require $root . '/admin/migrations/0051_kitchen_versions.php')($pdo);
$ok(bcrf_kitchen_versions_ready($pdo), 'migration repeats safely');

$pdo->exec("INSERT INTO measurement_units (id, name, system_type, abbreviation, unit_type) VALUES
  (1, 'Gram', 'Metric', 'g', 'Weight'), (2, 'Ounce', 'US', 'oz', 'Weight'), (4, 'Cup', 'US', 'c', 'Volume'), (9, 'Whole', 'US', NULL, 'Count'),
  (12, 'Fluid Ounce', 'US', 'fl oz', 'Volume')");
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role) VALUES (7, 'Zac', 'M', 'z@example.com', 'administrator')");
$ins = $pdo->prepare("INSERT INTO ingredients (singular_name, plural_name, density_g_per_ml, weight_per_unit_g, allergens, sub_ingredients) VALUES (?, ?, ?, ?, ?, ?)");
foreach ([['strawberry', 'strawberries', null, 15, '', null], ['sugar', 'sugars', 0.85, null, '', null], ['lemon juice', 'lemon juices', 1.03, null, '', null],
          ['pectin', 'pectins', 0.5, null, null, 'dextrose, fruit pectin, citric acid']] as $row) $ins->execute($row);
$ing = array_column($pdo->query("SELECT id, singular_name FROM ingredients")->fetchAll(), 'id', 'singular_name');
$pdo->exec("INSERT INTO recipes (id, title, slug, date_published, status) VALUES (1, 'Strawberry Jam', 'strawberry-jam', '2026-11-12', 'published'), (2, 'Other', 'other', '2026-11-12', 'published')");
$ri = $pdo->prepare("INSERT INTO recipe_ingredients (recipe_id, ingredient_id, quantity, unit_id) VALUES (?, ?, ?, ?)");
$ri->execute([1, $ing['strawberry'], 100, 9]);      // 1500 g
$ri->execute([1, $ing['sugar'], 4, 4]);             // ~804 g
$ri->execute([1, $ing['lemon juice'], 0.25, 4]);    // ~61 g
$ri->execute([1, $ing['pectin'], 1, 2]);            // 28 g
$ri->execute([2, $ing['sugar'], 1, 4]);

// -- Versions --
$st = bcrf_kitchen_version_state($pdo, 1);
$ok($st['latest'] === null && !$st['current'], 'no version yet');
$throws(fn() => bcrf_kitchen_save_version($pdo, 1, 7), 'pectin allergens unchecked: refused', 'ready');
$pdo->exec("UPDATE ingredients SET allergens = '' WHERE id = " . (int)$ing['pectin']);
$v1 = bcrf_kitchen_save_version($pdo, 1, 7, '  first   batch  ');
$ok($v1['created'] && (int)$v1['version']['version_no'] === 1, 'version 1 saved');
$ok($v1['version']['note'] === 'first batch', 'note tidied');
$ok(str_starts_with($v1['version']['statement'], 'strawberry, sugar, lemon juice, pectin (dextrose'), 'statement stored: ' . $v1['version']['statement']);
$ok($v1['version']['contains_line'] === '' && (float)$v1['version']['total_g'] > 2390, 'contains and total stored');
$ok(count($v1['version']['snapshot']['lines']) === 4 && $v1['version']['snapshot']['title'] === 'Strawberry Jam', 'snapshot');
$ok($v1['version']['by_name'] === 'Zac M', 'who saved it');
$again = bcrf_kitchen_save_version($pdo, 1, 7);
$ok(!$again['created'] && (int)$again['version']['id'] === (int)$v1['version']['id'], 'saving with no change returns version 1');
$ok(bcrf_kitchen_version_state($pdo, 1)['current'], 'current');

$pdo->exec("UPDATE recipe_ingredients SET quantity = 5 WHERE recipe_id = 1 AND ingredient_id = " . (int)$ing['sugar']);
$st = bcrf_kitchen_version_state($pdo, 1);
$ok(!$st['current'] && in_array('sugar: 4 Cup → 5 Cup', $st['changes'], true), 'quantity change shows: ' . implode(' | ', $st['changes']));
$v2 = bcrf_kitchen_save_version($pdo, 1, 7);
$ok($v2['created'] && (int)$v2['version']['version_no'] === 2, 'version 2');
$pdo->exec("UPDATE ingredients SET sub_ingredients = 'dextrose, fruit pectin' WHERE id = " . (int)$ing['pectin']);
$st = bcrf_kitchen_version_state($pdo, 1);
$ok(!$st['current'] && in_array('pectin: its own ingredients changed', $st['changes'], true), 'an ingredient detail change makes it out of date: ' . implode(' | ', $st['changes']));
$pdo->exec("UPDATE ingredients SET allergens = 'milk' WHERE id = " . (int)$ing['pectin']);
$st = bcrf_kitchen_version_state($pdo, 1);
$ok(in_array('Contains: none → Milk', $st['changes'], true), 'contains change shown');
$pdo->exec("UPDATE ingredients SET allergens = '', sub_ingredients = 'dextrose, fruit pectin, citric acid' WHERE id = " . (int)$ing['pectin']);
$ok(bcrf_kitchen_version_state($pdo, 1)['current'], 'putting it back matches version 2 again');
$ri->execute([1, $ing['sugar'], 0.5, 4]);           // a second sugar line
$st = bcrf_kitchen_version_state($pdo, 1);
$ok(in_array('sugar: 5 Cup → 5 Cup + 1/2 Cup', $st['changes'], true), 'second line of the same ingredient: ' . implode(' | ', $st['changes']));
$pdo->exec("DELETE FROM recipe_ingredients WHERE recipe_id = 1 AND quantity = 0.5");
$pdo->exec("DELETE FROM recipe_ingredients WHERE recipe_id = 1 AND ingredient_id = " . (int)$ing['lemon juice']);
$ok(in_array('Removed lemon juice', bcrf_kitchen_version_state($pdo, 1)['changes'], true), 'removed line');
$ri->execute([1, $ing['lemon juice'], 0.25, 4]);
$ok(bcrf_kitchen_version_state($pdo, 1)['current'], 'same lines in a new order of entry still match');
$pdo->exec("UPDATE ingredients SET label_name = 'fresh strawberries' WHERE id = " . (int)$ing['strawberry']);
$ch = bcrf_kitchen_version_state($pdo, 1)['changes'];
$ok($ch === ['Name on the label changed: fresh strawberries'], 'label name change: ' . implode(' | ', $ch));
$pdo->exec("UPDATE ingredients SET label_name = NULL");
$pdo->exec("UPDATE ingredients SET density_g_per_ml = 0.9 WHERE id = " . (int)$ing['sugar']);
$ch = bcrf_kitchen_version_state($pdo, 1)['changes'];
$ok($ch === ['Weights changed (an ingredient\'s grams per mL or weight of one)'], 'density change: ' . implode(' | ', $ch));
$pdo->exec("UPDATE ingredients SET density_g_per_ml = 0.85 WHERE id = " . (int)$ing['sugar']);
$ok(count(bcrf_kitchen_versions($pdo, 1)) === 2 && (int)bcrf_kitchen_versions($pdo, 1)[0]['version_no'] === 2, 'history newest first');
$ok(bcrf_kitchen_version_state($pdo, 999) === null, 'missing recipe');
$throws(fn() => bcrf_kitchen_save_version($pdo, 999, 7), 'missing recipe refused', 'no longer');

// -- Products --
$pdo->exec("INSERT INTO inventory (id, category, name, price, stock_quantity, size_value, size_unit_id, sku, product_type, track_inventory) VALUES
  (21, 'Pantry', 'Strawberry Jam 8 oz', 8.00, 0, 8, 2, 'JAM8', 'kitchen_good', 1),
  (22, 'Pantry', 'Strawberry Jam 16 fl oz', 14.00, 0, 16, 12, 'JAM16', 'kitchen_good', 1),
  (23, 'Pantry', 'Pickles', 3.00, 5, NULL, NULL, 'BCF-005', 'retail', 1),
  (24, 'Zoo', 'Adult Admission', 8.00, 0, NULL, NULL, 'ADM', 'admission', 0)");
$list = array_column(bcrf_kitchen_products($pdo), null, 'id');
$ok(isset($list[21], $list[22]) && !isset($list[23]) && $list[21]['recipe_id'] === null, 'kitchen goods listed, not linked yet');
$ok($list[21]['net_qty'] === 'Net Wt. 8 oz (227 g)', 'net quantity from the product size: ' . $list[21]['net_qty']);
$link = array_column(bcrf_kitchen_linkable_products($pdo), 'name', 'id');
$ok(isset($link[23]) && !isset($link[24]), 'Pickles can be linked, admission can\'t');
$throws(fn() => bcrf_kitchen_product_save($pdo, 21, ['recipe_id' => 99, 'units_per_batch' => '12'], 7), 'unknown recipe', 'recipe');
$throws(fn() => bcrf_kitchen_product_save($pdo, 21, ['recipe_id' => 1, 'units_per_batch' => ''], 7), 'units needed', 'how many');
$throws(fn() => bcrf_kitchen_product_save($pdo, 21, ['recipe_id' => 1, 'units_per_batch' => '12', 'shelf_life_days' => '2.5'], 7), 'shelf life whole days', 'whole number');
$throws(fn() => bcrf_kitchen_product_save($pdo, 999, ['recipe_id' => 1, 'units_per_batch' => '12'], 7), 'missing product', 'no longer');
$ok(bcrf_kitchen_product_save($pdo, 21, ['recipe_id' => 1, 'units_per_batch' => '12', 'shelf_life_days' => '365'], 7) === ['linked'], 'linked');
$p = bcrf_kitchen_product($pdo, 21);
$ok((int)$p['recipe_id'] === 1 && (float)$p['units_per_batch'] === 12.0 && (int)$p['shelf_life_days'] === 365 && (int)$p['approved_type'] === 0, 'link saved');
$ch = bcrf_kitchen_product_save($pdo, 21, ['recipe_id' => 1, 'units_per_batch' => '10', 'shelf_life_days' => '365', 'approved_type' => '1', 'approved_note' => 'Kaye, Nov 20'], 7);
$ok($ch === ['units per batch', 'marked approved type', 'note'], 'changes listed: ' . implode(',', $ch));
$ok(bcrf_kitchen_product_save($pdo, 21, ['recipe_id' => 1, 'units_per_batch' => '10.00', 'shelf_life_days' => '365', 'approved_type' => '1', 'approved_note' => 'Kaye, Nov 20'], 7) === [], 'no change');
bcrf_kitchen_product_save($pdo, 23, ['recipe_id' => 2, 'units_per_batch' => '6'], 7);
$ok(isset(array_column(bcrf_kitchen_products($pdo), null, 'id')[23]), 'a linked retail product shows on the list');
$ok(count(bcrf_kitchen_products_for_recipe($pdo, 1)) === 1 && bcrf_kitchen_products_for_recipe($pdo, 1)[0]['name'] === 'Strawberry Jam 8 oz', 'products for a recipe');
$ok(bcrf_kitchen_product_unlink($pdo, 23) && !bcrf_kitchen_product_unlink($pdo, 23), 'unlink once');

// -- Batch fit --
$fit = bcrf_kitchen_batch_fit(2393.0, 10, 8, 'Ounce');
$ok($fit && !$fit['over'] && abs($fit['need_g'] - 2268) < 1, '10 x 8 oz fits in the batch');
$fit = bcrf_kitchen_batch_fit(2393.0, 12, 8, 'Ounce');
$ok($fit['over'] && abs($fit['each_g'] - 226.8) < 0.1, '12 x 8 oz is more than the batch weighs');
$fit = bcrf_kitchen_batch_fit(2393.0, 8, 8, 'Ounce');
$ok(!$fit['over'] && abs($fit['fits'] - 10.6) < 0.1, '8 jars fit, about 10.6 possible');
$ok(bcrf_kitchen_batch_fit(2393.0, 8, 16, 'Fluid Ounce') === null, 'volume sizes not checked');
$ok(bcrf_kitchen_size_grams(1, 'Pounds') !== null && bcrf_kitchen_size_grams(0, 'Ounce') === null, 'size grams');

echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
