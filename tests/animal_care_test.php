<?php
// tests/animal_care_test.php — Farm OS Phase 5 slice 2 (Nov 12, 2026): care and feeding (includes/animal_care.php,
// migration 0041) against a real, THROWAWAY MySQL 8 database: care types, logging for animals or a whole enclosure,
// feed out of stock with its cost, short stock, voiding (feed back into stock) and the per-animal lists.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/animal_care_test.php
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
$ok(bcrf_care_ready($pdo), 'tables exist');
(require $root . '/admin/migrations/0041_animal_care.php')($pdo);
$types = bcrf_care_types($pdo);
$ok(count($types) === 7, 'starter care types, not duplicated on repeat');
$byName = array_column($types, null, 'name');
$feeding = $byName['Feeding']['id']; $water = $byName['Water']['id']; $hoof = $byName['Hoof trim']['id'];
$ok($byName['Feeding']['is_feeding'] === 1 && $byName['Water']['is_feeding'] === 0 && $byName['Water']['quick'] === 1 && $byName['Hoof trim']['quick'] === 0, 'starter flags');
$ok(bcrf_care_feed_ids($pdo) === [], 'no feed products to start');

// -- Care types --
$dust = bcrf_care_type_save($pdo, 0, 'Dust bath', false, true, true, 80);
$throws(fn() => bcrf_care_type_save($pdo, 0, 'Water', false, true, true), 'duplicate type refused', 'already');
$throws(fn() => bcrf_care_type_save($pdo, 0, '', false, true, true), 'blank type refused', 'name');
bcrf_care_type_save($pdo, $dust, 'Dust bath', false, false, false, 80);
$ok(count(bcrf_care_types($pdo)) === 7 && count(bcrf_care_types($pdo, true)) === 8, 'switched-off type hidden');

// -- Seed: animals, enclosures, feed --
$pdo->exec("INSERT INTO classifications (class_name) VALUES ('Goat')");
$pdo->exec("INSERT INTO breeds (breed_name, slug) VALUES ('Nigerian Dwarf', 'nd')");
$ins = $pdo->prepare("INSERT INTO animals (name, breed_id, classification_id, date_of_birth, is_active) VALUES (?, 1, 1, '2023-01-01', 1)");
foreach (['Hazel', 'Bramble', 'Clover', 'Gone Gus'] as $nm) $ins->execute([$nm]);
[$hazel, $bramble, $clover, $gus] = array_map('intval', $pdo->query("SELECT id FROM animals ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$barn = bcrf_enclosure_save($pdo, 0, 'Goat Barn', null, '', true);
$empty = bcrf_enclosure_save($pdo, 0, 'Empty Pen', null, '', true);
bcrf_animals_move($pdo, [$hazel, $bramble, $gus], $barn);
bcrf_animal_set_status($pdo, $bramble, 'resting', 'limp');
bcrf_animal_set_status($pdo, $gus, 'sold', 'sold', 'Neighbor');
$pdo->exec("INSERT INTO inventory (category, name, price, stock_quantity, product_type, pricing_mode, unit, track_inventory, is_active, avg_cost_cents)
            VALUES ('Zoo', 'Goat feed 50 lb', 0, 10, 'retail', 'each', 'bag', 1, 0, 1850)");
$feed = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO inventory (category, name, price, stock_quantity, product_type, pricing_mode, unit, track_inventory, is_active)
            VALUES ('Zoo', 'Hay from the field', 0, 0, 'retail', 'each', 'bale', 0, 0)");
$hay = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO inventory (category, name, price, stock_quantity, product_type, pricing_mode, unit, track_inventory, is_active)
            VALUES ('Pantry', 'Jam', 8, 5, 'retail', 'each', 'each', 1, 1)");
$jam = (int)$pdo->lastInsertId();
bcrf_care_set_feed_ids($pdo, [$feed, $hay, 99999, $feed]);
$ok(bcrf_care_feed_ids($pdo) === [$feed, $hay], 'feed list keeps real products once');
$ok(array_column(bcrf_care_feed_products($pdo), 'name') === ['Goat feed 50 lb', 'Hay from the field'], 'feed picker');

// -- Setup picker: category filter and search (Nov 12, 2026) --
$pdo->exec("INSERT INTO inventory_categories (name, slug, sort_order) VALUES ('Pantry', 'pantry-t', 1), ('Feed', 'feed-t', 2)");
$catPantry = (int)$one("SELECT id FROM inventory_categories WHERE slug = 'pantry-t'");
$catFeed = (int)$one("SELECT id FROM inventory_categories WHERE slug = 'feed-t'");
$pdo->exec("INSERT INTO inventory (category, name, price, stock_quantity, product_type, pricing_mode, unit, track_inventory, is_active)
            VALUES ('Zoo', 'Alfalfa pellets', 0, 3, 'retail', 'each', 'bag', 1, 0), ('Zoo', 'Chicken scratch', 0, 3, 'retail', 'each', 'bag', 1, 0)");
$alf = (int)$one("SELECT id FROM inventory WHERE name = 'Alfalfa pellets'");
$scr = (int)$one("SELECT id FROM inventory WHERE name = 'Chicken scratch'");
$pdo->exec("UPDATE inventory SET category_id = $catFeed WHERE id IN ($alf, $scr, $feed)");
$pdo->exec("UPDATE inventory SET category_id = $catPantry WHERE id = $jam");
$ok(bcrf_care_feed_category($pdo) === $catFeed, 'picker opens on the Feed category');
$pk = bcrf_care_pick_products($pdo, $catFeed, '');
$ok(array_column($pk['ticked'], 'name') === ['Goat feed 50 lb', 'Hay from the field'], 'ticked products always first, any category');
$ok(array_column($pk['others'], 'name') === ['Alfalfa pellets', 'Chicken scratch'], 'Feed category shows unticked feed only — got ' . implode(', ', array_column($pk['others'], 'name')));
$ok(array_column(bcrf_care_pick_products($pdo, null, 'scrat')['others'], 'name') === ['Chicken scratch'], 'search across all categories');
$ok(bcrf_care_pick_products($pdo, $catPantry, 'scrat')['others'] === [], 'search inside a category');
$ok(count(bcrf_care_pick_products($pdo, null, '', 1)['others']) === 1 && bcrf_care_pick_products($pdo, null, '', 1)['more'], 'long lists are capped');
// Saving: hay was shown and unticked -> off; alfalfa ticked -> on; nothing off screen changes
bcrf_care_update_feed_ids($pdo, [$feed, $hay, $alf, $scr], [$feed, $alf]);
$ok(bcrf_care_feed_ids($pdo) === [$feed, $alf], 'save changes only what was shown — got ' . implode(',', bcrf_care_feed_ids($pdo)));
bcrf_care_update_feed_ids($pdo, [$scr], [$scr, $jam]);
$ok(bcrf_care_feed_ids($pdo) === [$feed, $alf, $scr], 'products not on screen keep their tick, and can\'t be ticked from outside');
bcrf_care_set_feed_ids($pdo, [$feed, $hay]);

// -- Logging: refusals --
$throws(fn() => bcrf_care_log($pdo, ['type' => 0, 'animals' => [$hazel]]), 'no type refused', 'what you did');
$throws(fn() => bcrf_care_log($pdo, ['type' => $dust, 'animals' => [$hazel]]), 'switched-off type refused', 'what you did');
$throws(fn() => bcrf_care_log($pdo, ['type' => $water]), 'nobody picked refused', 'animals');
$throws(fn() => bcrf_care_log($pdo, ['type' => $water, 'enclosure' => $empty]), 'empty enclosure refused', 'Nobody');
$throws(fn() => bcrf_care_log($pdo, ['type' => $water, 'animals' => [$gus]]), 'animal that left refused', 'left the farm');
$throws(fn() => bcrf_care_log($pdo, ['type' => $water, 'animals' => [$hazel, 12345]]), 'unknown animal refused', 'roster');
$throws(fn() => bcrf_care_log($pdo, ['type' => $feeding, 'animals' => [$hazel], 'feed_product' => $jam, 'feed_qty' => 1]), 'non-feed product refused', 'feed from the list');
$throws(fn() => bcrf_care_log($pdo, ['type' => $feeding, 'animals' => [$hazel], 'feed_product' => $feed, 'feed_qty' => '0']), 'zero amount refused', 'amount');
$throws(fn() => bcrf_care_log($pdo, ['type' => $feeding, 'animals' => [$hazel], 'feed_qty' => '2']), 'amount without feed refused', 'which feed');
$ok((int)$one("SELECT COUNT(*) FROM animal_care_logs") === 0, 'refusals wrote nothing');

// -- Logging: one animal, no feed --
$r = bcrf_care_log($pdo, ['type' => $hoof, 'animals' => [$clover], 'note' => ' trimmed all four '], 5);
$ok($r['animals'] === 1 && $r['stock_note'] === '', 'one animal logged');
$ok($one("SELECT note FROM animal_care_logs WHERE id = ?", [$r['id']]) === 'trimmed all four', 'note trimmed');

// -- Logging: whole enclosure, feed out of stock --
$r = bcrf_care_log($pdo, ['type' => $feeding, 'enclosure' => $barn, 'feed_product' => $feed, 'feed_qty' => '1.5'], 5);
$ok($r['animals'] === 2, 'enclosure covers its animals now, resting included, sold one not');
$names = $pdo->prepare("SELECT a.name FROM animal_care_log_animals la JOIN animals a ON a.id = la.animal_id WHERE la.log_id = ? ORDER BY a.name");
$names->execute([$r['id']]);
$ok($names->fetchAll(PDO::FETCH_COLUMN) === ['Bramble', 'Hazel'], 'right animals on the entry');
$row = $pdo->query("SELECT * FROM animal_care_logs WHERE id = " . (int)$r['id'])->fetch();
$ok((int)$row['enclosure_id'] === $barn && (float)$row['feed_qty'] === 1.5 && (int)$row['feed_cost_cents'] === 2775, 'enclosure, amount and cost (1.5 x $18.50) kept');
$ok((float)$one("SELECT stock_quantity FROM inventory WHERE id = ?", [$feed]) === 8.5, 'feed taken out of stock');
$adj = $pdo->query("SELECT * FROM inventory_adjustments WHERE id = " . (int)$row['stock_adjustment_id'])->fetch();
$ok($adj && $adj['reason'] === 'internal_use' && $adj['source_type'] === 'animal_care' && (int)$adj['source_id'] === (int)$r['id'] && (float)$adj['quantity_change'] === -1.5, 'ledger row points back to the entry');
$fedId = (int)$r['id'];

// -- Logging: enclosure plus an extra animal, untracked feed --
$r = bcrf_care_log($pdo, ['type' => $feeding, 'enclosure' => $barn, 'animals' => [$clover, $hazel], 'feed_product' => $hay, 'feed_qty' => '1'], 5);
$ok($r['animals'] === 3, 'enclosure plus ticked animals, no doubles');
$row = $pdo->query("SELECT * FROM animal_care_logs WHERE id = " . (int)$r['id'])->fetch();
$ok($row['stock_adjustment_id'] === null && $row['feed_cost_cents'] === null, 'unstocked feed: no stock change, no cost');

// -- Short stock never blocks --
$r = bcrf_care_log($pdo, ['type' => $feeding, 'animals' => [$clover], 'feed_product' => $feed, 'feed_qty' => '20'], 5);
$ok(strpos($r['stock_note'], 'short by 11.5') !== false, 'short stock noted, entry still saved');
$ok((float)$one("SELECT stock_quantity FROM inventory WHERE id = ?", [$feed]) === 0.0, 'stock stops at zero');
$shortId = (int)$r['id'];

// -- Voiding --
$throws(fn() => bcrf_care_void($pdo, $fedId, ' '), 'void needs a reason', 'why');
bcrf_care_void($pdo, $fedId, 'logged twice', 5);
$ok((float)$one("SELECT stock_quantity FROM inventory WHERE id = ?", [$feed]) === 1.5, 'void puts the feed back');
$ok($one("SELECT void_reason FROM animal_care_logs WHERE id = ?", [$fedId]) === 'logged twice', 'void reason kept, entry kept');
$throws(fn() => bcrf_care_void($pdo, $fedId, 'again'), 'void twice refused', 'already');
$throws(fn() => bcrf_care_void($pdo, 99999, 'x'), 'unknown entry refused', 'doesn');
bcrf_care_void($pdo, $shortId, 'wrong amount', 5);
$ok((float)$one("SELECT stock_quantity FROM inventory WHERE id = ?", [$feed]) === 10.0, 'void of a short entry returns only what was taken');

// -- Lists --
$recent = bcrf_care_recent($pdo);
$ok(count($recent) === 4 && count(bcrf_care_recent($pdo, 0, 50, false)) === 2, 'recent with and without voided');
$hz = bcrf_care_recent($pdo, $hazel);
$ok(count($hz) === 2 && $hz[0]['animals'] === ['Bramble', 'Clover', 'Hazel'] && $hz[0]['feed_name'] === 'Hay from the field', 'one animal\'s entries with names');
$last = bcrf_care_last_by_type($pdo, $clover);
$ok(isset($last[$hoof], $last[$feeding]) && !isset($last[$water]), 'last done by type');
$lastH = bcrf_care_last_by_type($pdo, $hazel);
$ok(isset($lastH[$feeding]), 'voided entries ignored but the hay one counts');

echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
