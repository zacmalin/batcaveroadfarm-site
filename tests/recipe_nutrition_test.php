<?php
// tests/recipe_nutrition_test.php — one-step USDA lookup + recipe Nutrition Facts (Nov 13, 2026):
// USDA portion parsing (density, weight of one) and nutrients (includes/adapter_usda.php), and the recipe math
// with its "still needs USDA" list (includes/recipe_nutrition.php) against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/recipe_nutrition_test.php
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

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$near = fn($a, $b, $eps = 0.01) => $a !== null && abs((float)$a - (float)$b) < $eps;

// ── USDA parsing (no network) ────────────────────────────────────────────────
require_once $root . '/includes/adapter_usda.php';
$egg = ['description' => 'Egg, whole, raw, fresh', 'foodPortions' => [
    ['amount' => 1, 'modifier' => 'cup (4.86 large eggs)', 'gramWeight' => 243, 'measureUnit' => ['name' => 'undetermined']],
    ['amount' => 1, 'modifier' => 'medium', 'gramWeight' => 44, 'measureUnit' => ['name' => 'undetermined']],
    ['amount' => 1, 'modifier' => 'large', 'gramWeight' => 50, 'measureUnit' => ['name' => 'undetermined']],
    ['amount' => 1, 'modifier' => 'oz', 'gramWeight' => 28.35, 'measureUnit' => ['name' => 'undetermined']],
]];
$p = bcrf_usda_portions($egg);
$ok($p['weight'] == 50, 'egg: weight of one is the large egg (50 g)');
$ok($near($p['density'], 1.0271, 0.001), 'egg: density from the cup portion');
$ok(count(array_filter($p['portions'], fn($r) => $r['kind'] === 'weight')) === 1, 'oz portion is a weight, not a count');

$flour = ['description' => 'Wheat flour, white, all-purpose', 'foodPortions' => [
    ['value' => 1, 'amount' => 1, 'measureUnit' => ['name' => 'tablespoon'], 'modifier' => '', 'gramWeight' => 7.8],
    ['value' => 1, 'amount' => 1, 'measureUnit' => ['name' => 'cup'], 'modifier' => '', 'gramWeight' => 125],
    ['amount' => 1, 'measureUnit' => ['name' => 'undetermined'], 'modifier' => '', 'portionDescription' => 'Quantity not specified', 'gramWeight' => 30],
]];
$p = bcrf_usda_portions($flour);
$ok($near($p['density'], 0.5283, 0.001) && str_starts_with($p['density_from'], '1 cup'), 'flour: cup wins over tablespoon (Foundation shape)');
$ok($p['weight'] === null, 'flour: no weight of one');
$ok(count($p['portions']) === 2, '"Quantity not specified" is skipped');

$onion = ['description' => 'Onions, raw', 'foodPortions' => [
    ['amount' => 1, 'modifier' => 'large', 'gramWeight' => 150, 'measureUnit' => ['name' => 'undetermined']],
    ['amount' => 1, 'modifier' => 'medium (2-1/2" dia)', 'gramWeight' => 110, 'measureUnit' => ['name' => 'undetermined']],
    ['amount' => 2, 'modifier' => 'tbsp, chopped', 'gramWeight' => 20, 'measureUnit' => ['name' => 'undetermined']],
]];
$p = bcrf_usda_portions($onion);
$ok($p['weight'] == 110, 'onion: medium wins for weight of one');
$ok($near($p['density'], 20 / (2 * 14.7868), 0.001), 'onion: density from "2 tbsp" divides by the amount');

$nut = bcrf_usda_nutrients(['foodNutrients' => [
    ['nutrient' => ['id' => 2047], 'amount' => 364], ['nutrient' => ['id' => 1003], 'amount' => 10.3],
    ['nutrient' => ['id' => 1063], 'amount' => 0.3], ['nutrient' => ['id' => 1093], 'amount' => 2],
]]);
$ok($nut['calories_per_100g'] == 364 && $nut['sugar_per_100g'] == 0.3 && $nut['fat_per_100g'] === null, 'Foundation energy (2047) and sugars (1063) fall back');
$nut = bcrf_usda_nutrients(['foodNutrients' => [['nutrientId' => 1008, 'value' => 52], ['nutrientId' => 2047, 'value' => 99]]]);
$ok($nut['calories_per_100g'] == 52, 'search-hit shape: 1008 kcal wins');

// ── Recipe math against MySQL ────────────────────────────────────────────────
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }
require_once $root . '/includes/recipe_nutrition.php';

$pdo->exec("INSERT INTO measurement_units (id, name, system_type, abbreviation, unit_type) VALUES
  (1, 'Gram', 'Metric', 'g', 'Weight'), (4, 'Cup', 'US', 'c', 'Volume'), (5, 'Milliliter', 'Metric', 'ml', 'Volume'), (9, 'Whole', 'US', NULL, 'Count')");
$pdo->exec("INSERT INTO unit_conversions (from_unit_id, to_unit_id, conversion_factor) VALUES (4, 5, 236.588)");
$ins = $pdo->prepare("INSERT INTO ingredients (singular_name, plural_name, calories_per_100g, protein_per_100g, fat_per_100g, carbs_per_100g, density_g_per_ml, weight_per_unit_g) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$ins->execute(['flour', 'flour', 364, 10, 1, 76, 0.5283, null]);
$ins->execute(['egg', 'eggs', 143, 12.6, 9.5, 0.7, null, null]);    // no weight of one yet
$ins->execute(['butter', 'butter', null, null, null, null, null, null]); // no nutrition yet
$ing = array_column($pdo->query("SELECT id, singular_name FROM ingredients")->fetchAll(), 'id', 'singular_name');
$pdo->exec("INSERT INTO recipes (id, title, slug, date_published, status, recipe_yield) VALUES (1, 'Biscuits', 'biscuits', '2026-11-13', 'draft', '4')");
$ri = $pdo->prepare("INSERT INTO recipe_ingredients (recipe_id, ingredient_id, quantity, unit_id) VALUES (?, ?, ?, ?)");
$ri->execute([1, $ing['flour'], 2, 4]);     // 2 cups = 250 g
$ri->execute([1, $ing['egg'], 2, 9]);       // 2 whole
$ri->execute([1, $ing['butter'], 100, 1]);  // 100 g

$r = bcrf_recipe_nutrition($pdo, 1, false);
$ok($r['ok'] && $r['counted'] === 1 && $r['lines'] === 3, 'only flour counts at first');
$needs = array_column($r['missing'], 'need', 'name');
$ok(($needs['egg'] ?? '') === 'weight' && ($needs['butter'] ?? '') === 'nutrition', 'missing list: egg needs weight of one, butter needs nutrition');
$ok($r['calories'] === (int)round(2 * 236.588 * 0.5283 / 100 * 364 / 4), 'flour calories per serving (yield 4)');
$ok($pdo->query("SELECT calories FROM recipes WHERE id = 1")->fetchColumn() === null, 'check-only run saves nothing');

// What a USDA pick does: fill butter's nutrition and the egg's weight of one
$pdo->prepare("UPDATE ingredients SET calories_per_100g = 717, protein_per_100g = 0.9, fat_per_100g = 81, carbs_per_100g = 0.1 WHERE id = ?")->execute([$ing['butter']]);
$pdo->prepare("UPDATE ingredients SET weight_per_unit_g = 50 WHERE id = ?")->execute([$ing['egg']]);
$r = bcrf_recipe_nutrition($pdo, 1, true);
$ok($r['ok'] && $r['counted'] === 3 && !$r['missing'], 'all three count after the picks');
$want = (2 * 236.588 * 0.5283 / 100 * 364 + 100 / 100 * 717 + 100 / 100 * 143) / 4;
$ok($r['calories'] === (int)round($want), 'calories per serving add up (' . $r['calories'] . ')');
$row = $pdo->query("SELECT calories, nutrition_source, nutrition_servings FROM recipes WHERE id = 1")->fetch();
$ok((int)$row['calories'] === $r['calories'] && $row['nutrition_source'] === 'usda_local' && (int)$row['nutrition_servings'] === 4, 'saved to the recipe');

$ok(!bcrf_recipe_nutrition($pdo, 999, true)['ok'], 'unknown recipe is refused');
$pdo->exec("INSERT INTO recipes (id, title, slug, date_published, status) VALUES (2, 'Empty', 'empty', '2026-11-13', 'draft')");
$ok(!bcrf_recipe_nutrition($pdo, 2, true)['ok'], 'recipe with no lines is refused');

echo $fails ? "$fails of $n checks FAILED\n" : "All $n recipe nutrition checks passed\n";
exit($fails ? 1 : 0);
