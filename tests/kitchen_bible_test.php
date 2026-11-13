<?php
// tests/kitchen_bible_test.php — Farm OS Phase 4 (Nov 13, 2026): the house Recipe Bible (includes/kitchen_bible.php,
// migration 0052) against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/kitchen_bible_test.php
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
require_once $root . '/includes/kitchen_bible.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };

$ok(bcrf_bible_ready($pdo), 'migration 0052 ran');
(require $root . '/admin/migrations/0052_recipe_bible.php')($pdo);
$ok(bcrf_bible_ready($pdo), 'migration repeats safely');

$pdo->exec("INSERT INTO measurement_units (id, name, system_type, abbreviation, unit_type) VALUES
  (1, 'Gram', 'Metric', 'g', 'Weight'), (4, 'Cup', 'US', 'c', 'Volume'), (9, 'Whole', 'US', NULL, 'Count')");
$ins = $pdo->prepare("INSERT INTO ingredients (singular_name, plural_name) VALUES (?, ?)");
foreach ([['flour', 'flour'], ['egg', 'eggs'], ['butter', 'butter'], ['sugar', 'sugar']] as $row) $ins->execute($row);
$ing = array_column($pdo->query("SELECT id, singular_name FROM ingredients")->fetchAll(), 'id', 'singular_name');
$pdo->exec("INSERT INTO recipes (id, title, slug, date_published, status, recipe_yield, instructions) VALUES
  (1, 'House Pie', 'house-pie', '2026-11-13', 'draft', '1 pie', '<ol><li onclick=\"x()\">Mix.</li><li>Bake.</li></ol><script>alert(1)</script>'),
  (2, 'Pie Crust', 'pie-crust', '2026-11-13', 'published', '1 crust', ''),
  (3, 'Web Cookies', 'web-cookies', '2026-11-13', 'published', '', '')");
$ok((int)$pdo->query("SELECT COUNT(*) FROM recipes WHERE in_house = 1")->fetchColumn() === 0, 'existing recipes start outside the Bible');
$ok(bcrf_bible_list($pdo) === [], 'empty Bible');

$ri = $pdo->prepare("INSERT INTO recipe_ingredients (recipe_id, ingredient_id, linked_recipe_id, quantity, unit_id, notes, ingredient_group) VALUES (?, ?, ?, ?, ?, ?, ?)");
// Entered in the order you need them; deliberately not alphabetical or by weight.
$ri->execute([1, null, 2, 1, 9, '', 'Crust']);
$ri->execute([1, $ing['sugar'], null, 0.5, 4, '', 'Filling']);
$ri->execute([1, $ing['egg'], null, 2, 9, 'beaten', 'Filling']);
$ri->execute([1, $ing['flour'], null, 1000, 1, '', 'Filling']);
$ri->execute([2, $ing['flour'], null, 1.5, 4, '', '']);
$ri->execute([2, $ing['butter'], null, 0.5, 4, 'cold', '']);

$ok(bcrf_bible_recipe($pdo, 1) === null, 'recipe not in the Bible is not shown');
$pdo->exec("UPDATE recipes SET in_house = 1 WHERE id IN (1, 2)");
$list = bcrf_bible_list($pdo);
$ok(array_column($list, 'title') === ['House Pie', 'Pie Crust'], 'list: Bible recipes only, by title');
$ok($list[0]['lines'] === 4 && $list[0]['status'] === 'draft' && $list[1]['status'] === 'published', 'list: line counts and website status');
$ok(array_column(bcrf_bible_list($pdo, 'crust'), 'title') === ['Pie Crust'], 'list: search');

$r = bcrf_bible_recipe($pdo, 1);
$ok(array_column($r['lines'], 'name') === ['Pie Crust', 'sugar', 'eggs', 'flour'], 'lines in entry order (the order you need them), plural by quantity');
$ok(array_column($r['lines'], 'group') === ['Crust', 'Filling', 'Filling', 'Filling'], 'group headings kept');
$ok($r['lines'][1]['qty_text'] === "\u{00BD}" && $r['lines'][1]['unit'] === 'c', 'half cup shows as ½ c');
$ok($r['lines'][2]['notes'] === 'beaten' && $r['lines'][2]['unit'] === 'Whole', 'notes kept; unit name when no abbreviation');
$ok($r['lines'][0]['linked_recipe_id'] === 2 && $r['lines'][0]['linked_in_bible'] === true, 'sub-recipe in the Bible links to it');
$ok(strpos($r['method_html'], '<script') === false && strpos($r['method_html'], 'onclick') === false && strpos($r['method_html'], '<li>Mix.</li>') !== false, 'method keeps formatting, drops scripts and attributes');

// Reordering lines (delete + re-insert in the new order, as the editor does) changes the cooking order.
$pdo->exec("DELETE FROM recipe_ingredients WHERE recipe_id = 2");
$ri->execute([2, $ing['butter'], null, 0.5, 4, 'cold', '']);
$ri->execute([2, $ing['flour'], null, 1.5, 4, '', '']);
$ok(array_column(bcrf_bible_recipe($pdo, 2)['lines'], 'name') === ['butter', 'flour'], 'reordered lines show in the new order');

// Batch size
$ok(bcrf_bible_multiplier('2') === 2.0 && bcrf_bible_multiplier('0.5') === 0.5 && bcrf_bible_multiplier('7') === 1.0 && bcrf_bible_multiplier('abc') === 1.0, 'only offered multipliers');
$r2 = bcrf_bible_recipe($pdo, 1, 2.0);
$ok($r2['lines'][1]['qty_text'] === '1' && $r2['lines'][2]['qty'] == 4 && $r2['lines'][3]['qty'] == 2000, 'doubling scales every line');
$rh = bcrf_bible_recipe($pdo, 1, 0.5);
$ok($rh['lines'][1]['qty_text'] === "\u{00BC}" && $rh['lines'][2]['name'] === 'egg', 'halving: ¼ c, one egg (singular)');

// Website and Bible are separate
$pdo->exec("UPDATE recipes SET status = 'draft' WHERE id = 2");
$ok(bcrf_bible_recipe($pdo, 2) !== null, 'taking a recipe off the website leaves it in the Bible');
$pdo->exec("UPDATE recipes SET in_house = 0 WHERE id = 1");
$ok(bcrf_bible_recipe($pdo, 1) === null && (string)$pdo->query("SELECT status FROM recipes WHERE id = 1")->fetchColumn() === 'draft', 'leaving the Bible does not touch website status');

echo $fails ? "$fails of $n checks failed\n" : "All $n checks passed\n";
exit($fails ? 1 : 0);
