<?php
// tests/kitchen_labels_test.php — Farm OS Phase 4 slice 4 (Nov 13, 2026): label drafts and approval
// (includes/kitchen_labels.php, migration 0054) against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/kitchen_labels_test.php
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
require_once $root . '/includes/kitchen_labels.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (BcrfKitchenError $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};

$ok(bcrf_kitchen_labels_ready($pdo), 'migration 0054 ran');
(require $root . '/admin/migrations/0054_kitchen_labels.php')($pdo);
$ok(bcrf_kitchen_labels_ready($pdo) && $one("SELECT COUNT(*) FROM site_settings WHERE setting_key = 'kitchen_label_statement'") == 1, 'migration repeats safely; statement setting added');
$ok((bool)$pdo->query("SHOW COLUMNS FROM kitchen_batches LIKE 'label_id'")->fetch(), 'kitchen_batches.label_id added');

$pdo->exec("INSERT INTO site_settings (setting_key, setting_value) VALUES ('business_name', 'Test Farm'), ('address_street', '1 Road'), ('address_city', 'Old Fort'), ('address_state', 'NC'), ('address_zip', '28762')
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
$pdo->exec("INSERT INTO measurement_units (id, name, system_type, abbreviation, unit_type) VALUES (2, 'Ounce', 'US', 'oz', 'Weight'), (4, 'Cup', 'US', 'c', 'Volume'), (9, 'Whole', 'US', NULL, 'Count')");
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role) VALUES (7, 'Zac', 'M', 'z@example.com', 'administrator')");
$ins = $pdo->prepare("INSERT INTO ingredients (singular_name, plural_name, density_g_per_ml, weight_per_unit_g, allergens) VALUES (?, ?, ?, ?, ?)");
foreach ([['strawberry', 'strawberries', null, 15, ''], ['sugar', 'sugar', 0.85, null, ''], ['butter', 'butter', 0.95, null, 'milk']] as $row) $ins->execute($row);
$ing = array_column($pdo->query("SELECT id, singular_name FROM ingredients")->fetchAll(), 'id', 'singular_name');
$pdo->exec("INSERT INTO recipes (id, title, slug, date_published, status) VALUES (1, 'Strawberry Jam', 'strawberry-jam', '2026-11-12', 'published')");
$ri = $pdo->prepare("INSERT INTO recipe_ingredients (recipe_id, ingredient_id, quantity, unit_id) VALUES (?, ?, ?, ?)");
$ri->execute([1, $ing['strawberry'], 100, 9]);
$ri->execute([1, $ing['sugar'], 4, 4]);
$pdo->exec("INSERT INTO inventory (id, category, name, price, stock_quantity, size_value, size_unit_id, sku, product_type, track_inventory) VALUES
  (21, 'Pantry', 'Strawberry Jam 8 oz', 8.00, 0, 8, 2, 'JAM8', 'kitchen_good', 1),
  (22, 'Pantry', 'Mystery Jar', 5.00, 0, NULL, NULL, 'MYS', 'kitchen_good', 1)");

// -- Blockers --
$throws(fn() => bcrf_kitchen_label_draft($pdo, 21, 'Strawberry Jam', 7), 'not linked', 'Link this product');
bcrf_kitchen_product_save($pdo, 21, ['recipe_id' => 1, 'units_per_batch' => 12, 'shelf_life_days' => 365], 7);
bcrf_kitchen_product_save($pdo, 22, ['recipe_id' => 1, 'units_per_batch' => 12], 7);
$throws(fn() => bcrf_kitchen_label_draft($pdo, 21, 'Strawberry Jam', 7), 'no version', 'version');
$v1 = bcrf_kitchen_save_version($pdo, 1, 7);

// -- Draft --
$throws(fn() => bcrf_kitchen_label_draft($pdo, 21, '   ', 7), 'needs a name', 'product name');
$d = bcrf_kitchen_label_draft($pdo, 21, ' Strawberry   Jam ', 7);
$ok($d['status'] === 'draft' && $d['product_name'] === 'Strawberry Jam' && (int)$d['recipe_version_id'] === (int)$v1['version']['id'], 'draft from version 1');
$ok($d['statement'] === 'strawberry, sugar' && $d['contains_line'] === '' && $d['net_qty'] === 'Net Wt. 8 oz (227 g)', 'statement heaviest first, no allergens, net qty: ' . $d['net_qty']);
$ok($d['made_by'] === 'Made by: Test Farm, 1 Road, Old Fort, NC 28762', 'made by from Site Info: ' . $d['made_by']);
$d2 = bcrf_kitchen_label_draft($pdo, 21, 'Strawberry Preserves', 7);
$ok((int)$d2['id'] === (int)$d['id'] && $d2['product_name'] === 'Strawberry Preserves', 'redraft updates the open draft');
$s = bcrf_kitchen_label_state($pdo, 21);
$ok($s['approved'] === null && (int)$s['draft']['id'] === (int)$d['id'], 'state: draft waiting');
$ok(in_array(true, array_map(fn($i) => str_starts_with($i, 'NCDA'), $s['build']['issues']), true) && !bcrf_kitchen_label_hard_issues($s['build']['issues']), 'NCDA note is a warning only');

// No size: can draft, can't approve
$d22 = bcrf_kitchen_label_draft($pdo, 22, 'Mystery', 7);
$throws(fn() => bcrf_kitchen_label_approve($pdo, (int)$d22['id'], 7), 'no net quantity blocks approval', 'net quantity');

// -- Reject / approve --
$throws(fn() => bcrf_kitchen_label_reject($pdo, (int)$d['id'], 7, ' '), 'reject needs a reason', 'Say');
$r = bcrf_kitchen_label_reject($pdo, (int)$d['id'], 7, 'Call it jam');
$ok($r['status'] === 'rejected' && $r['decision_note'] === 'Call it jam', 'rejected with note');
$throws(fn() => bcrf_kitchen_label_approve($pdo, (int)$d['id'], 7), 'rejected can\'t be approved', 'Only a draft');
$d3 = bcrf_kitchen_label_draft($pdo, 21, 'Strawberry Jam', 7);
$ok((int)$d3['id'] !== (int)$d['id'], 'new draft after a rejection');
$a = bcrf_kitchen_label_approve($pdo, (int)$d3['id'], 7, 'looks right');
$ok($a['status'] === 'approved' && (int)$a['decided_by'] === 7 && $a['decision_note'] === 'looks right', 'approved');
$ok((int)bcrf_kitchen_label_state($pdo, 21)['approved']['id'] === (int)$a['id'], 'state: approved label');
$throws(fn() => bcrf_kitchen_label_draft($pdo, 21, 'Strawberry Jam', 7), 'same text as approved', 'already says');
$ov = array_column(bcrf_kitchen_label_overview($pdo), null, 'id');
$ok($ov[21]['status'] === 'approved' && $ov[22]['status'] === 'draft', 'overview statuses');

// New name: draft alongside the approved one; approving replaces it
$d4 = bcrf_kitchen_label_draft($pdo, 21, 'Strawberry Jam (small batch)', 7);
$a4 = bcrf_kitchen_label_approve($pdo, (int)$d4['id'], 7);
$ok($one("SELECT status FROM kitchen_labels WHERE id = ?", [$a['id']]) === 'superseded' && $a4['status'] === 'approved', 'approving a new label supersedes the old');

// -- Superseded by changes --
$pdo->exec("INSERT INTO recipe_ingredients (recipe_id, ingredient_id, quantity, unit_id) VALUES (1, " . (int)$ing['butter'] . ", 0.25, 4)");
$s = bcrf_kitchen_label_state($pdo, 21);
$ok($s['approved'] === null && $s['build']['blockers'] && in_array((int)$a4['id'], $s['superseded_now'], true), 'recipe changed: approved label superseded');
$ok(str_contains((string)$one("SELECT superseded_note FROM kitchen_labels WHERE id = ?", [$a4['id']]), 'changed since version 1'), 'superseded note says why');
bcrf_kitchen_save_version($pdo, 1, 7);
$d5 = bcrf_kitchen_label_draft($pdo, 21, 'Strawberry Jam', 7);
$ok($d5['contains_line'] === 'Milk' && str_contains($d5['statement'], 'butter'), 'new draft has the new allergen: ' . $d5['contains_line']);
$a5 = bcrf_kitchen_label_approve($pdo, (int)$d5['id'], 7);
$pdo->exec("UPDATE inventory SET size_value = 16 WHERE id = 21");
$s = bcrf_kitchen_label_state($pdo, 21);
$ok($s['approved'] === null && str_contains((string)$one("SELECT superseded_note FROM kitchen_labels WHERE id = ?", [$a5['id']]), 'size'), 'size changed: superseded');
$pdo->exec("UPDATE inventory SET size_value = 8 WHERE id = 21");
$a6 = bcrf_kitchen_label_approve($pdo, (int)bcrf_kitchen_label_draft($pdo, 21, 'Strawberry Jam', 7)['id'], 7);
$ok(bcrf_kitchen_label_statement_save($pdo, ' Made in a home kitchen. ') === true && bcrf_kitchen_label_statement_save($pdo, 'Made in a home kitchen.') === false, 'statement setting saved, trimmed');
$s = bcrf_kitchen_label_state($pdo, 21);
$ok($s['approved'] === null && str_contains((string)$one("SELECT superseded_note FROM kitchen_labels WHERE id = ?", [$a6['id']]), 'statement'), 'statement changed: superseded');
$d7 = bcrf_kitchen_label_draft($pdo, 21, 'Strawberry Jam', 7);
$ok($d7['extra'] === 'Made in a home kitchen.', 'new draft carries the statement');
// A draft goes stale too
$pdo->exec("UPDATE inventory SET size_value = 9 WHERE id = 21");
$throws(fn() => bcrf_kitchen_label_approve($pdo, (int)$d7['id'], 7), 'stale draft can\'t be approved', 'out of date');
$ok($one("SELECT status FROM kitchen_labels WHERE id = ?", [$d7['id']]) === 'superseded', 'stale draft superseded');
$ok(count(bcrf_kitchen_labels_for($pdo, 21)) === (int)$one("SELECT COUNT(*) FROM kitchen_labels WHERE inventory_id = 21") && $one("SELECT COUNT(*) FROM kitchen_labels") == 7, 'labels never deleted; history newest first');

echo $fails ? "$fails of $n checks failed\n" : "All $n checks passed\n";
exit($fails ? 1 : 0);
