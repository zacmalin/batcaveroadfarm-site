<?php
// tests/kitchen_test.php — Farm OS Phase 4 slice 1 (Nov 12, 2026): ingredient label details and recipe weights (includes/kitchen.php,
// migration 0050) against a real, THROWAWAY MySQL 8 database: unit conversion, density and weight-of-one, heaviest-first order,
// sub-recipes, allergens carried up, blocked lines, saving an ingredient.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/kitchen_test.php
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
require_once $root . '/includes/kitchen.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (BcrfKitchenError $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};
$near = fn(?float $a, float $b) => $a !== null && abs($a - $b) < 0.05;

// -- Migration --
$ok(bcrf_kitchen_ready($pdo), 'migration 0050 ran');
(require $root . '/admin/migrations/0050_kitchen_ingredients.php')($pdo);
$ok(bcrf_kitchen_ready($pdo), 'migration repeats safely');

// -- Units (names as the farm set them up; one only this farm uses, with a conversion row) --
$pdo->exec("INSERT INTO measurement_units (id, name, system_type, abbreviation, unit_type) VALUES
  (1, 'Gram', 'Metric', 'g', 'Weight'), (2, 'Ounce', 'US', 'oz', 'Weight'), (3, 'Pound', 'US', 'lb', 'Weight'),
  (4, 'Cup', 'US', 'c', 'Volume'), (5, 'Tablespoon', 'US', 'tbsp', 'Volume'), (6, 'Teaspoon', 'US', 'tsp', 'Volume'),
  (7, 'Milliliter', 'Metric', 'mL', 'Volume'), (8, 'Clove', 'US', NULL, 'Count'), (9, 'Whole', 'US', NULL, 'Count'),
  (10, 'Stick', 'US', NULL, 'Weight'), (11, 'Jigger', 'US', NULL, 'Volume'), (12, 'Ounce (liquid)', 'US', 'oz', 'Volume'),
  (13, 'Pinches', 'US', NULL, NULL)");
$pdo->exec("INSERT INTO unit_conversions (from_unit_id, to_unit_id, conversion_factor) VALUES (11, 7, 44.36)");
$u = bcrf_kitchen_units($pdo, true);
$ok($u[1]['kind'] === 'mass' && $u[1]['factor'] == 1.0, 'gram');
$ok($u[3]['kind'] === 'mass' && $near($u[3]['factor'], 453.59), 'pound');
$ok($u[4]['kind'] === 'volume' && $near($u[4]['factor'], 236.59), 'cup');
$ok($u[5]['kind'] === 'volume' && $near($u[5]['factor'], 14.79), 'tablespoon');
$ok($u[8]['kind'] === 'count', 'clove is a count');
$ok($u[10]['kind'] === 'mass' && $u[10]['factor'] === null, 'a weight unit with no conversion stays unknown');
$ok($u[11]['kind'] === 'volume' && $near($u[11]['factor'], 44.36), 'a farm-only unit uses its conversion row');
$ok($u[12]['kind'] === 'volume' && $near($u[12]['factor'], 29.57), 'an ounce set up as volume is a fluid ounce');
$ok($u[13]['kind'] === 'volume' && $near($u[13]['factor'], 0.31), 'pinches are a pinch of volume');
$ok(bcrf_kitchen_unit_key('Tablespoons') === 'tablespoon' && bcrf_kitchen_unit_key('Fl. Oz.') === 'fl oz' && bcrf_kitchen_unit_key('lbs') === 'lbs', 'unit words');

// -- Line weights --
$g = bcrf_kitchen_line_grams($pdo, 2, 4, 0.53, null);
$ok($near($g['grams'], 2 * 236.588237 * 0.53) && $g['problem'] === '', '2 cups of flour by density');
$ok(bcrf_kitchen_line_grams($pdo, 2, 4, null, null)['problem'] === 'density', 'volume without density is blocked');
$ok(bcrf_kitchen_line_grams($pdo, 3, 8, null, null)['problem'] === 'each', 'count without weight of one is blocked');
$ok($near(bcrf_kitchen_line_grams($pdo, 3, 8, null, 5)['grams'], 15), '3 cloves at 5 g');
$ok($near(bcrf_kitchen_line_grams($pdo, 2, null, null, 50)['grams'], 100), 'no unit = count');
$ok(bcrf_kitchen_line_grams($pdo, 1, 10, null, null)['problem'] === 'unit', 'unconvertible weight unit is blocked');
$ok($near(bcrf_kitchen_line_grams($pdo, 8, 2, null, null)['grams'], 226.8), '8 oz');

// -- Ingredients --
$ins = $pdo->prepare("INSERT INTO ingredients (singular_name, plural_name, density_g_per_ml, weight_per_unit_g, allergens, sub_ingredients, label_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
foreach ([
    ['flour', 'flours', 0.53, null, 'wheat', 'wheat flour, niacin, reduced iron', 'enriched flour'],
    ['sugar', 'sugars', 0.85, null, '', null, null],
    ['butter', 'butters', null, 113, 'milk', 'cream, salt', null],     // by the stick (count)
    ['egg', 'eggs', null, 50, 'eggs', null, null],
    ['pecan', 'pecans', 0.45, null, 'tree_nuts:pecans', null, null],
    ['salt', 'salts', 1.2, null, null, null, null],                     // allergens never checked
    ['chocolate chips', 'chocolate chipss', 0.6, null, 'milk,soy', 'sugar, cocoa butter, milk, soy lecithin, vanilla', null],
    ['paprika', 'paprikas', 0.46, null, '', null, null],
    ['shrimp', 'shrimps', null, null, 'shellfish', null, null],          // shellfish without saying which
] as $row) $ins->execute($row);
$ing = array_column($pdo->query("SELECT id, singular_name FROM ingredients")->fetchAll(), 'id', 'singular_name');
$pdo->exec("INSERT INTO recipes (id, title, slug, date_published, status) VALUES (1, 'Pecan Cookies', 'pecan-cookies', '2026-11-12', 'published'),
  (2, 'Spice Mix', 'spice-mix', '2026-11-12', 'published'), (3, 'Spiced Cookies', 'spiced-cookies', '2026-11-12', 'published'),
  (4, 'Loop A', 'loop-a', '2026-11-12', 'published'), (5, 'Loop B', 'loop-b', '2026-11-12', 'published'),
  (6, 'Empty', 'empty', '2026-11-12', 'published')");
$ri = $pdo->prepare("INSERT INTO recipe_ingredients (recipe_id, ingredient_id, linked_recipe_id, quantity, unit_id, ingredient_group) VALUES (?, ?, ?, ?, ?, ?)");
// Pecan Cookies: flour 2 cups (250.8 g), sugar 1 cup (201.1 g), butter 2 sticks-as-count (226 g), egg 2 (100 g), pecans 1 cup (106.5 g),
// salt 1 tsp (5.9 g), and more flour in a second group (1/2 cup, 62.7 g) that must add up with the first.
foreach ([[1, $ing['flour'], null, 2, 4, 'Dough'], [1, $ing['sugar'], null, 1, 4, 'Dough'], [1, $ing['butter'], null, 2, 9, 'Dough'],
          [1, $ing['egg'], null, 2, null, 'Dough'], [1, $ing['pecan'], null, 1, 4, 'Dough'], [1, $ing['salt'], null, 1, 6, 'Dough'],
          [1, $ing['flour'], null, 0.5, 4, 'Dusting']] as $r) $ri->execute($r);
$res = bcrf_kitchen_resolve($pdo, 1);
$ok($res['problems'] === 0, 'every line has a weight');
$ok(count($res['lines']) === 7 && count($res['items']) === 6, 'flour counted once on the label');
$ok($res['items'][0]['name'] === 'enriched flour' && $near($res['items'][0]['grams'], 2.5 * 236.588237 * 0.53), 'heaviest first, flour added up, label name used');
$ok(array_column($res['items'], 'name') === ['enriched flour', 'butter', 'sugar', 'pecan', 'egg', 'salt'], 'order by weight: ' . implode(', ', array_column($res['items'], 'name')));
$ok(str_starts_with($res['statement'], 'enriched flour (wheat flour, niacin, reduced iron), butter (cream, salt), sugar'), 'statement with sub-ingredients: ' . $res['statement']);
$ok($res['contains'] === 'Milk, Eggs, Pecans, Wheat', 'contains line in the standard order: ' . $res['contains']);
$ok($res['unchecked'] === ['salt'] && !$res['ready'], 'salt not checked blocks it');
$ok($near(array_sum(array_column($res['items'], 'pct')), 100), 'percentages add to 100');

// -- Saving an ingredient --
$throws(fn() => bcrf_kitchen_ingredient_save($pdo, $ing['salt'], ['allergen' => []], 7), 'blank allergens is not "checked"', 'None of the nine');
$throws(fn() => bcrf_kitchen_ingredient_save($pdo, $ing['salt'], ['allergen' => ['milk'], 'no_allergens' => '1'], 7), 'both ticked', 'Pick one');
$throws(fn() => bcrf_kitchen_ingredient_save($pdo, $ing['shrimp'], ['allergen' => ['shellfish']], 7), 'shellfish must be named', 'which');
$throws(fn() => bcrf_kitchen_ingredient_save($pdo, $ing['salt'], ['no_allergens' => '1', 'density_g_per_ml' => 'heavy'], 7), 'density must be a number', 'number');
$throws(fn() => bcrf_kitchen_ingredient_save($pdo, $ing['salt'], ['no_allergens' => '1', 'density_g_per_ml' => '40'], 7), 'density in range', 'between');
$throws(fn() => bcrf_kitchen_ingredient_save($pdo, 9999, ['no_allergens' => '1'], 7), 'missing ingredient', 'no longer');
$ch = bcrf_kitchen_ingredient_save($pdo, $ing['salt'], ['no_allergens' => '1', 'density_g_per_ml' => '1.2', 'label_name' => 'Salt'], 7);
$ok($ch === ['allergens'], 'only allergens changed (label name same as the name is dropped): ' . implode(',', $ch));
$ok($one("SELECT allergens FROM ingredients WHERE id = ?", [$ing['salt']]) === '' && (int)$one("SELECT allergens_checked_by FROM ingredients WHERE id = ?", [$ing['salt']]) === 7, 'checked as none, and by whom');
$ok($one("SELECT label_name FROM ingredients WHERE id = ?", [$ing['salt']]) === null, 'no label name stored');
$ok(bcrf_kitchen_resolve($pdo, 1)['ready'], 'cookies are ready once salt is checked');
bcrf_kitchen_ingredient_save($pdo, $ing['shrimp'], ['allergen' => ['shellfish'], 'allergen_detail' => ['shellfish' => 'Shrimp']], 7);
$ok($one("SELECT allergens FROM ingredients WHERE id = ?", [$ing['shrimp']]) === 'shellfish:Shrimp', 'shellfish named');
bcrf_kitchen_ingredient_save($pdo, $ing['pecan'], ['allergen' => ['tree_nuts'], 'allergen_detail' => ['tree_nuts' => 'pecans'], 'sub_ingredients' => "Ingredients:\npecans,\r\n salt"], 7);
$ok($one("SELECT sub_ingredients FROM ingredients WHERE id = ?", [$ing['pecan']]) === 'Ingredients: pecans, salt', 'sub-ingredients kept on one line');
$ok(str_contains(bcrf_kitchen_resolve($pdo, 1)['statement'], 'pecan (pecans, salt)'), 'pasted "Ingredients:" heading dropped on the label');
bcrf_kitchen_ingredient_save($pdo, $ing['pecan'], ['allergens_unknown' => '1'], 7);
$ok($one("SELECT allergens FROM ingredients WHERE id = ?", [$ing['pecan']]) === null && $one("SELECT allergens_checked_at FROM ingredients WHERE id = ?", [$ing['pecan']]) === null, 'set back to not checked');
bcrf_kitchen_ingredient_save($pdo, $ing['pecan'], ['allergen' => ['tree_nuts'], 'allergen_detail' => ['tree_nuts' => 'pecans']], 7);

// -- Sub-recipe: Spice Mix (paprika 20 g, salt 10 g, shrimp?) used in Spiced Cookies --
$ri->execute([2, $ing['paprika'], null, 20, 1, null]);
$ri->execute([2, $ing['salt'], null, 10, 1, null]);
$ri->execute([3, $ing['flour'], null, 100, 1, null]);
$ri->execute([3, null, 2, 2, null, null]);             // 2 whole batches of spice mix = 60 g
$ri->execute([3, $ing['chocolate chips'], null, 50, 1, null]);
$res = bcrf_kitchen_resolve($pdo, 3);
$ok($res['problems'] === 0 && $near($res['total_g'], 210), 'sub-recipe by whole batches: ' . $res['total_g']);
$ok(array_column($res['items'], 'name') === ['enriched flour', 'Spice Mix', 'chocolate chips'], 'sub-recipe sorted by its weight');
$ok(str_contains($res['statement'], 'Spice Mix (paprika, salt)'), 'sub-recipe ingredients in parentheses: ' . $res['statement']);
$ok($res['contains'] === 'Milk, Wheat, Soy', 'allergens from chips and flour: ' . $res['contains']);
$ok($res['ready'], 'ready');
$pdo->exec("UPDATE recipe_ingredients SET unit_id = 5 WHERE recipe_id = 3 AND linked_recipe_id = 2");
$ok(bcrf_kitchen_resolve($pdo, 3)['lines'][1]['problem'] === 'sub_unit', 'sub-recipe by the spoon is blocked');
$pdo->exec("UPDATE recipe_ingredients SET unit_id = 2, quantity = 1 WHERE recipe_id = 3 AND linked_recipe_id = 2");
$ok($near(bcrf_kitchen_resolve($pdo, 3)['lines'][1]['grams'], 28.35), 'sub-recipe by weight');
$ri->execute([2, $ing['shrimp'], null, 5, 1, null]);
$pdo->exec("UPDATE ingredients SET allergens = NULL WHERE id = " . (int)$ing['paprika']);
$res = bcrf_kitchen_resolve($pdo, 3);
$ok($res['unchecked'] === ['paprika'], 'unchecked inside a sub-recipe is named, not the sub-recipe: ' . implode(',', $res['unchecked']));
$ok(!$res['ready'], 'not ready');
$pdo->exec("UPDATE ingredients SET allergens = '' WHERE id = " . (int)$ing['paprika']);
$cc = bcrf_kitchen_resolve($pdo, 3)['contains']; $ok($cc === 'Milk, Shrimp, Wheat, Soy', 'shellfish carried up from the sub-recipe: ' . $cc);
$pdo->exec("UPDATE recipe_ingredients SET unit_id = NULL WHERE recipe_id = 3 AND linked_recipe_id = 2");
$pdo->exec("UPDATE recipe_ingredients SET unit_id = 4 WHERE recipe_id = 2 AND ingredient_id = " . (int)$ing['shrimp']);
$ok(bcrf_kitchen_resolve($pdo, 3)['lines'][1]['problem'] === 'sub_blocked', 'batches of a sub-recipe that has a blocked line are blocked');

// -- Loops, empty recipes --
$ri->execute([4, null, 5, 1, null, null]);
$ri->execute([5, null, 4, 1, null, null]);
$res = bcrf_kitchen_resolve($pdo, 4);
$ok($res !== null && $res['problems'] === 1 && !$res['ready'], 'a loop is caught, not followed forever');
$ok(!bcrf_kitchen_resolve($pdo, 6)['ready'], 'a recipe with no lines is not ready');
$ok(bcrf_kitchen_resolve($pdo, 999) === null, 'missing recipe');

// -- Lists --
$list = bcrf_kitchen_recipe_list($pdo);
$byTitle = array_column($list, null, 'title');
$ok($byTitle['Pecan Cookies']['ready'] && !$byTitle['Spiced Cookies']['ready'] && count($list) === 6, 'recipe list: ' . json_encode($list));
$ok(count(bcrf_kitchen_recipe_list($pdo, 'cook')) === 2, 'recipe search');
$pdo->exec("UPDATE ingredients SET density_g_per_ml = NULL WHERE id = " . (int)$ing['sugar']);
$att = array_column(bcrf_kitchen_ingredient_list($pdo, 'attention'), null, 'singular_name');
$ok(isset($att['sugar']) && $att['sugar']['needs_density'], 'sugar needs a density again');
$ok(isset($att['shrimp']) && $att['shrimp']['needs_density'], 'shrimp by the cup needs a density');
$ok(!isset($att['butter']) && !isset($att['flour']), 'complete ingredients are not listed');
$ok(bcrf_kitchen_attention_count($pdo) === count($att), 'sidebar badge counts the Needs attention list: ' . bcrf_kitchen_attention_count($pdo));
$all = bcrf_kitchen_ingredient_list($pdo, 'all');
$ok(count($all) === 9, 'all ingredients');
$ok(count(bcrf_kitchen_ingredient_list($pdo, 'all', 'choc')) === 1, 'ingredient search');
$pdo->exec("INSERT INTO ingredients (singular_name, plural_name) VALUES ('saffron', 'saffrons')");
$ok(count(bcrf_kitchen_ingredient_list($pdo, 'unchecked')) === 1, 'unused unchecked ingredient listed under Not checked');
$ok(!isset(array_column(bcrf_kitchen_ingredient_list($pdo, 'attention'), null, 'singular_name')['saffron']), 'but not under Needs attention (not in a recipe)');

// -- Sulfites (Nov 13, 2026): their own flag, not one of the nine; "Contains sulfites." next to the Contains line --
$ok(bcrf_allergens_parse('milk,sulfites') === ['milk' => '', 'sulfites' => ''] && bcrf_allergens_only(['milk' => '', 'sulfites' => '']) === ['milk' => ''], 'sulfites parsed, kept apart from the nine');
$ok(bcrf_allergens_encode(['sulfites' => '', 'tree_nuts' => 'pecans']) === 'tree_nuts:pecans,sulfites', 'sulfites encoded after the nine');
$throws(fn() => bcrf_kitchen_ingredient_save($pdo, $ing['paprika'], ['sulfites' => '1'], 7), 'sulfites alone still needs the nine checked', 'None of the nine');
bcrf_kitchen_ingredient_save($pdo, $ing['paprika'], ['no_allergens' => '1', 'sulfites' => '1'], 7);
$ok($one("SELECT allergens FROM ingredients WHERE id = ?", [$ing['paprika']]) === 'sulfites', 'none of the nine + sulfites stored');
bcrf_kitchen_ingredient_save($pdo, $ing['paprika'], ['no_allergens' => '1', 'label_name' => 'smoked paprika'], 7);
$ok($one("SELECT allergens FROM ingredients WHERE id = ?", [$ing['paprika']]) === 'sulfites', 'saving without the sulfites field keeps it');
$res = bcrf_kitchen_resolve($pdo, 3);
$ok($res['sulfites'] === true && stripos($res['contains'], 'sulfite') === false, 'sulfites carried up from a sub-recipe, not in Contains: ' . $res['contains']);
$ok(bcrf_kitchen_resolve($pdo, 1)['sulfites'] === false, 'recipe without sulfites');
$ok(bcrf_label_contains_text('Milk, Wheat', true) === 'Contains: Milk, Wheat. Contains sulfites.' && bcrf_label_contains_text('', true) === 'Contains sulfites.'
    && bcrf_label_contains_text('Milk', false) === 'Contains: Milk.' && bcrf_label_contains_text('', false) === '', 'label allergen line');
$ok(bcrf_kitchen_allergen_text('sulfites') === 'None of the nine · Sulfites' && bcrf_kitchen_allergen_text('milk,sulfites') === 'Milk · Sulfites', 'sulfites in list text');
$ok(array_column(bcrf_kitchen_ingredient_list($pdo, 'all'), null, 'singular_name')['paprika']['sulfites'] === true, 'ingredient list knows sulfites');
bcrf_kitchen_ingredient_save($pdo, $ing['paprika'], ['no_allergens' => '1', 'sulfites' => '0'], 7);
$ok($one("SELECT allergens FROM ingredients WHERE id = ?", [$ing['paprika']]) === '', 'sulfites unticked');
$ok(bcrf_sulfite_hint('red wine vinegar') === 'wine' && bcrf_sulfite_hint('raisin') === 'dried fruit' && bcrf_sulfite_hint('lemon juice') === 'bottled lemon or lime juice'
    && bcrf_sulfite_hint('molasses') === 'molasses' && bcrf_sulfite_hint('apricot', 'dried apricots') === 'dried fruit', 'common sources suggested');
$ok(bcrf_sulfite_hint('golden raisins', '', 'raisins, sulfur dioxide') === 'sulfur dioxide' && bcrf_sulfite_hint('lime', '', 'lime juice, sodium metabisulfite') === 'sodium metabisulfite', 'a sulfiting agent on the package wins');
$ok(bcrf_sulfite_hint('flour') === '' && bcrf_sulfite_hint('sugar') === '' && bcrf_sulfite_hint('egg') === '', 'no hint for ordinary ingredients');

// -- Display helpers --
$ok(bcrf_kitchen_allergen_text(null) === 'Not checked' && bcrf_kitchen_allergen_text('') === 'None' && bcrf_kitchen_allergen_text('milk,tree_nuts:pecans') === 'Milk, Tree nuts (pecans)', 'allergen text');
$ok(bcrf_kitchen_qty(1.5) === '1 1/2' && bcrf_kitchen_qty(0.25) === '1/4' && bcrf_kitchen_qty(2) === '2' && bcrf_kitchen_qty(1.2) === '1.2', 'quantities');
$ok(bcrf_kitchen_grams(1500) === '1.5 kg' && bcrf_kitchen_grams(5.94) === '5.9 g' && bcrf_kitchen_grams(250.8) === '251 g' && bcrf_kitchen_grams(null) === '—', 'grams');

echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
