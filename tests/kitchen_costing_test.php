<?php
// tests/kitchen_costing_test.php — Farm OS Phase 4 slice 6 (Nov 13, 2026): recipe and product costing, margin flag
// (includes/kitchen_costing.php, migration 0056) against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/kitchen_costing_test.php
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
$near = fn($a, $b, $eps = 0.01) => $a !== null && abs((float)$a - (float)$b) < $eps;

$ok(bcrf_kitchen_costing_ready($pdo), 'migration 0056 ran');
(require $root . '/admin/migrations/0056_kitchen_costing.php')($pdo);
$ok(bcrf_kitchen_costing_ready($pdo) && $one("SELECT setting_value FROM site_settings WHERE setting_key = 'kitchen_margin_alert'") === '40', 'migration repeats safely; alert 40%');
$ok((bool)$pdo->query("SHOW COLUMNS FROM kitchen_batches LIKE 'unit_cost_cents'")->fetch(), 'kitchen_batches cost columns added');
$ok(bcrf_kitchen_margin_alert($pdo) == 40, 'alert reads 40');
$throws(fn() => bcrf_kitchen_margin_alert_save($pdo, '120'), 'alert above 95 refused', '0 to 95');
$ok(bcrf_kitchen_margin_alert_save($pdo, '35') == 35 && bcrf_kitchen_margin_alert($pdo) == 35, 'alert saved');
bcrf_kitchen_margin_alert_save($pdo, '40');
$ok(bcrf_kitchen_money(123.4) === '$1.23' && bcrf_kitchen_money(null) === '—' && bcrf_kitchen_cents_in('$4.29', 'x') === 429 && bcrf_kitchen_cents_in(' ', 'x') === null, 'money in and out');
$throws(fn() => bcrf_kitchen_cents_in('abc', 'Price'), 'bad money refused', 'dollars');

// -- Setup: jam with strawberries (manual cost) and sugar (from stock) --
$pdo->exec("INSERT INTO site_settings (setting_key, setting_value) VALUES ('business_name', 'Test Farm'), ('address_street', '1 Road'), ('address_city', 'Old Fort'), ('address_state', 'NC'), ('address_zip', '28762')
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
$pdo->exec("INSERT INTO measurement_units (id, name, system_type, abbreviation, unit_type) VALUES (1, 'Pound', 'US', 'lb', 'Weight'), (2, 'Ounce', 'US', 'oz', 'Weight'), (4, 'Cup', 'US', 'c', 'Volume'), (9, 'Whole', 'US', NULL, 'Count')");
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role) VALUES (7, 'Zac', 'M', 'z@example.com', 'administrator')");
$ins = $pdo->prepare("INSERT INTO ingredients (singular_name, plural_name, density_g_per_ml, weight_per_unit_g, allergens) VALUES (?, ?, ?, ?, ?)");
foreach ([['strawberry', 'strawberries', null, 15, ''], ['sugar', 'sugar', 0.85, null, ''], ['pectin', 'pectin', null, null, '']] as $row) $ins->execute($row);
$ing = array_column($pdo->query("SELECT id, singular_name FROM ingredients")->fetchAll(), 'id', 'singular_name');
$pdo->exec("INSERT INTO recipes (id, title, slug, date_published, status) VALUES (1, 'Strawberry Jam', 'strawberry-jam', '2026-11-12', 'published'), (2, 'Jam Base', 'jam-base', '2026-11-12', 'draft')");
$ri = $pdo->prepare("INSERT INTO recipe_ingredients (recipe_id, ingredient_id, linked_recipe_id, quantity, unit_id) VALUES (?, ?, ?, ?, ?)");
$ri->execute([1, $ing['strawberry'], null, 100, 9]);    // 100 × 15 g = 1500 g
$ri->execute([1, $ing['sugar'], null, 4, 4]);           // 4 cups × 236.588 mL × 0.85
$pdo->exec("INSERT INTO inventory (id, category, name, price, stock_quantity, size_value, size_unit_id, sku, product_type, track_inventory, avg_cost_cents) VALUES
  (21, 'Pantry', 'Strawberry Jam 8 oz', 8.00, 0, 8, 2, 'JAM8', 'kitchen_good', 1, NULL),
  (30, 'Pantry', 'Sugar 4 lb', 4.00, 10, 4, 1, 'SUG4', 'retail', 1, 300),
  (31, 'Pantry', 'Jar lids', 0.00, 100, NULL, NULL, 'LID', 'retail', 1, 10),
  (32, 'Pantry', 'Mason jars', 0.00, 100, NULL, NULL, 'JAR', 'retail', 1, NULL)");
bcrf_kitchen_product_save($pdo, 21, ['recipe_id' => 1, 'units_per_batch' => 12, 'shelf_life_days' => 365], 7);

$sugarG = 4 * 236.588 * 0.85;
$c = bcrf_kitchen_product_cost($pdo, 21);
$ok($c['status'] === 'incomplete' && str_contains($c['why'], 'strawberr') && str_contains($c['why'], 'sugar'), 'nothing costed: incomplete, names both: ' . $c['why']);
bcrf_kitchen_ingredient_stock_set($pdo, $ing['sugar'], 30);
$costs = bcrf_kitchen_ingredient_costs($pdo, [$ing['sugar'], $ing['strawberry'], $ing['pectin']]);
$ok($costs[$ing['sugar']]['source'] === 'stock' && $near($costs[$ing['sugar']]['cpg'], 300 / (4 * 453.59237), 0.0001), 'sugar: stock avg cost ÷ grams in 4 lb');
$ok($costs[$ing['strawberry']]['cpg'] === null && str_contains($costs[$ing['strawberry']]['why'], 'No cost'), 'strawberry: no cost yet');

// Manual cost
$throws(fn() => bcrf_kitchen_ingredient_cost_save($pdo, $ing['strawberry'], '3', '', 1, 7), 'manual cost needs an amount', 'how much');
$throws(fn() => bcrf_kitchen_ingredient_cost_save($pdo, $ing['pectin'], '2', '1', 4, 7), 'volume without density refused', 'grams');
$throws(fn() => bcrf_kitchen_ingredient_cost_save($pdo, $ing['pectin'], '2', '1', 9, 7), 'each without weight refused', 'grams');
$ok(bcrf_kitchen_ingredient_cost_save($pdo, $ing['strawberry'], '$3.00', '1', 1, 7), 'strawberry: $3 for 1 lb');
$costs = bcrf_kitchen_ingredient_costs($pdo, [$ing['strawberry']]);
$ok($costs[$ing['strawberry']]['source'] === 'manual' && $near($costs[$ing['strawberry']]['cpg'], 300 / 453.59237, 0.0001) && $costs[$ing['strawberry']]['manual'] === '$3.00 for 1 lb', 'manual cost per gram: ' . $costs[$ing['strawberry']]['manual']);
// Stock cost wins over manual when both exist
bcrf_kitchen_ingredient_cost_save($pdo, $ing['sugar'], '1', '1', 1, 7);
$ok(bcrf_kitchen_ingredient_costs($pdo, [$ing['sugar']])[$ing['sugar']]['source'] === 'stock', 'stock average cost beats manual');

$rc = bcrf_kitchen_recipe_cost($pdo, 1);
$expRecipe = 1500 * 300 / 453.59237 + $sugarG * 300 / (4 * 453.59237);
$ok($rc['complete'] && $near($rc['total'], $expRecipe) && $near($rc['total_g'], 1500 + $sugarG), 'recipe cost: ' . round($rc['total']) . '¢');

// Packaging
$throws(fn() => bcrf_kitchen_packaging_add($pdo, 21, ['name' => 'Jar']), 'packaging needs a cost or stock product', 'costs');
$throws(fn() => bcrf_kitchen_packaging_add($pdo, 21, ['name' => 'Jar', 'cost' => '0.80', 'qty_per_unit' => '0']), 'qty must be above 0', 'above 0');
$throws(fn() => bcrf_kitchen_packaging_add($pdo, 21, ['packaging_inventory_id' => 21]), 'product can\'t package itself', 'own packaging');
bcrf_kitchen_packaging_add($pdo, 21, ['name' => '8 oz jar', 'cost' => '0.80']);
bcrf_kitchen_packaging_add($pdo, 21, ['packaging_inventory_id' => 31]);
$lab = bcrf_kitchen_packaging_add($pdo, 21, ['name' => 'Label', 'cost' => '0.05', 'qty_per_unit' => '2']);
$pk = bcrf_kitchen_packaging($pdo, 21);
$ok(count($pk) === 3 && $pk[1]['name'] === 'Jar lids' && $pk[1]['source'] === 'stock' && $near($pk[2]['per_unit'], 10), 'packaging: manual, from stock, 2 labels per jar');
$c = bcrf_kitchen_product_cost($pdo, 21);
$expUnit = $expRecipe / 12 + 80 + 10 + 10;
$ok($c['status'] === 'ok' && $near($c['unit_cost'], $expUnit) && $c['price'] === 800 && $near($c['margin'], (800 - $expUnit) / 800 * 100), 'unit cost ' . round($c['unit_cost']) . '¢, margin ' . round($c['margin'], 1) . '%');

// Margin alert: a purchase raises sugar's average cost
$pdo->exec("UPDATE inventory SET avg_cost_cents = 20000 WHERE id = 30");
$c2 = bcrf_kitchen_product_cost($pdo, 21);
$ok($c2['status'] === 'low' && $c2['margin'] < 40 && str_contains($c2['why'], 'below the 40% alert'), 'sugar cost up: flagged low: ' . $c2['why']);
$ov = bcrf_kitchen_cost_overview($pdo);
$ok(count($ov) === 1 && $ov[0]['status'] === 'low', 'overview shows the low product');
$pdo->exec("UPDATE inventory SET avg_cost_cents = 300 WHERE id = 30");

// Packaging from stock with no average cost → incomplete
$jar = bcrf_kitchen_packaging_add($pdo, 21, ['packaging_inventory_id' => 32]);
$c3 = bcrf_kitchen_product_cost($pdo, 21);
$ok($c3['status'] === 'incomplete' && str_contains($c3['why'], 'Mason jars'), 'packaging with no average cost: incomplete');
$ok(bcrf_kitchen_packaging_remove($pdo, $jar, 21) && !bcrf_kitchen_packaging_remove($pdo, $jar, 21), 'packaging removed once');
$ok(!bcrf_kitchen_packaging_remove($pdo, $lab, 99), 'can\'t remove another product\'s packaging');

// No price / no units
$pdo->exec("UPDATE inventory SET price = 0 WHERE id = 21");
$ok(bcrf_kitchen_product_cost($pdo, 21)['status'] === 'no_price', 'no price');
$pdo->exec("UPDATE inventory SET price = 8.00 WHERE id = 21");
$pdo->exec("UPDATE kitchen_products SET units_per_batch = 0 WHERE inventory_id = 21");
$ok(bcrf_kitchen_product_cost($pdo, 21)['status'] === 'no_units', 'no units per batch');
$pdo->exec("UPDATE kitchen_products SET units_per_batch = 12 WHERE inventory_id = 21");

// A line made from another recipe costs that recipe's cost per gram
$ri->execute([2, $ing['sugar'], null, 1, 1]);           // Jam Base: 1 lb sugar
$ri->execute([1, null, 2, 4, 2]);                        // 4 oz of Jam Base in the jam
$rc2 = bcrf_kitchen_recipe_cost($pdo, 1);
$sub = array_values(array_filter($rc2['lines'], fn($l) => $l['linked_recipe_id'] === 2))[0];
$ok($sub['source'] === 'recipe' && $near($sub['cpg'], 300 / (4 * 453.59237), 0.0001) && $rc2['complete'], 'sub-recipe line costed per gram');
$pdo->exec("DELETE FROM recipe_ingredients WHERE recipe_id = 2");
$pdo->exec("INSERT INTO recipe_ingredients (recipe_id, ingredient_id, quantity, unit_id) VALUES (2, " . (int)$ing['pectin'] . ", 1, 2)");
$rc3 = bcrf_kitchen_recipe_cost($pdo, 1);
$ok(!$rc3['complete'] && in_array('Jam Base', $rc3['missing'], true), 'sub-recipe missing a cost: incomplete');
$pdo->exec("DELETE FROM recipe_ingredients WHERE linked_recipe_id = 2");

// Clearing a manual cost
bcrf_kitchen_ingredient_cost_save($pdo, $ing['sugar'], '', '', '', 7);
$ok($one("SELECT cost_cents FROM ingredients WHERE id = ?", [$ing['sugar']]) === null, 'blank price clears the manual cost');

// -- Batch keeps its cost --
bcrf_kitchen_save_version($pdo, 1, 7);
$pdo->exec("UPDATE ingredients SET allergens_checked_at = UTC_TIMESTAMP() WHERE 1");
if (bcrf_kitchen_labels_ready($pdo)) bcrf_kitchen_label_approve($pdo, (int)bcrf_kitchen_label_draft($pdo, 21, 'Strawberry Jam', 7)['id'], 7);
$b = bcrf_kitchen_batch_record($pdo, 21, ['batches' => 2, 'units_made' => 24, 'made_on' => date('Y-m-d', strtotime('-1 day'))], 7);
$exp = $expRecipe * 2 + 100 * 24;
$ok((int)$b['cost_cents'] === (int)round($exp) && (int)$b['unit_cost_cents'] === (int)round($exp / 24), 'batch cost kept: ' . $b['cost_cents'] . '¢, ' . $b['unit_cost_cents'] . '¢ each');
$pdo->exec("UPDATE inventory SET avg_cost_cents = 9999 WHERE id = 30");
$ok((int)bcrf_kitchen_batch($pdo, (int)$b['id'])['cost_cents'] === (int)round($exp), 'later cost changes don\'t change a recorded batch');
bcrf_kitchen_ingredient_cost_save($pdo, $ing['strawberry'], '', '', '', 7);
$b2 = bcrf_kitchen_batch_record($pdo, 21, ['batches' => 1, 'made_on' => date('Y-m-d', strtotime('-1 day'))], 7);
$ok($b2['cost_cents'] === null && $b2['unit_cost_cents'] === null, 'batch with a missing cost: no cost kept');

echo $fails ? "$fails of $n checks failed\n" : "All $n checks passed\n";
exit($fails ? 1 : 0);
