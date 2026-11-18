<?php
// tests/garden_test.php — Garden in Farm OS (Nov 18, 2026): migration 0072, the garden classes, harvest into stock,
// garden beds as task links, and the menu, against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_all_test DB_USER=... DB_PASS=... php tests/garden_test.php
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
require_once $root . '/includes/garden.php';
require_once $root . '/includes/stock.php';
require_once $root . '/includes/record_tools.php';
require_once $root . '/includes/admin_nav.php';

use Garden\Domain\GardenService;
use Garden\Domain\GrowingRecords;
use Garden\Domain\SeedStock;
use Garden\Domain\CustomCatalog;
use Garden\Domain\PlantingJournal;
use Garden\Domain\FieldWorkflows;

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (Throwable $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};

// ── Migration ──
$ok(garden_ready($pdo), 'migration 0072 ran');
$ok((int)$one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'garden\\_%'") === 29, '29 garden tables');
(require $root . '/admin/migrations/0072_garden.php')($pdo);
$ok((int)$one("SELECT COUNT(*) FROM garden_organizations") === 1, 'migration repeats safely (one farm row)');
$ok((int)$one("SELECT COUNT(*) FROM garden_crops") >= 3, 'starter crops are in');
$ok((int)$one("SELECT COUNT(*) FROM view_permissions WHERE role = 'moderator' AND view_name IN ('garden', 'garden_manage')") === 2, 'Managers get both garden permissions');
$ok($one("SELECT COLUMN_COMMENT FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'garden_plantings' AND column_name = 'created_at'") === 'UTC', 'timestamps are UTC');
$ok($one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'garden_planting_harvest_events' AND column_name IN ('product_id', 'stock_added')") == 2, 'harvest rows can point at a product');
$ok($one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'garden_seed_lots' AND column_name = 'vendor_id'") == 1, 'seed lots can point at a vendor');
$org = garden_org($pdo);
$ok($org === 1, 'one garden org row');

// ── Units ──
$ok(garden_num(100, 0) === '100', 'whole numbers keep their zeros (100 sq ft)');
$ok(garden_num('12.500', 3) === '12.5', 'trailing decimal zeros dropped');
$ok(garden_ft_to_m('10') === '3.048', '10 ft = 3.048 m');
$ok(garden_sqm_to_sqft('9.290') === '100', '9.29 m² = 100 sq ft');
$ok(garden_f_to_c('212') === '100.0', '212 °F = 100 °C');

// ── Beds, crops, plantings ──
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role) VALUES (7, 'Pat', 'Grower', 'p@example.com', 'administrator')");
$_SESSION['user_id'] = 7;
$gs = new GardenService();
$site = $gs->createSite($pdo, $org, 'Kitchen garden', 'America/New_York');
$bed = $gs->createLocation($pdo, $org, $site, 'Bed 1', 'raised_bed', garden_sqft_to_sqm('100'));
$bed2 = $gs->createLocation($pdo, $org, $site, 'Bed 2', 'raised_bed', null);
$ok($bed > 0 && $bed2 > 0, 'site and beds saved');
$cc = new CustomCatalog();
$crop = $cc->createCrop($pdo, $org, 'Okra', 'annual');
(new Garden\Domain\CropGrowingProfiles())->updatePrivateCropTaxonomy($pdo, $org, $crop, 'Malvaceae', '');
$var = $cc->createVariety($pdo, $org, $crop, 'Clemson Spineless', '56', 'from_sowing');
$opts = garden_variety_options($pdo, $org);
$ok(in_array('custom:' . $var, array_column($opts, 'value'), true), 'own variety shows in the picker');
$rec = new GrowingRecords();
$lot = $rec->createSeedLot($pdo, $org, 'custom:' . $var, 'OK-1', 'Seed company', '2026-01-10', null, '');
$ss = new SeedStock();
$ss->startTracking($pdo, $org, $lot, '200', 'seeds', '2026-01-10', 7);
$pid = $rec->createPlanting($pdo, $org, $site, $bed, 'custom:' . $var, $lot, '2026-05-01', 'direct_sow', '40', 'plants', 'active');
$ss->record($pdo, $org, $lot, 'used', '-50', '2026-05-01', 'Planting #' . $pid, 7);
$bal = array_values(array_filter($ss->balances($pdo, $org), fn($b) => (int)$b['id'] === $lot))[0] ?? [];
$ok(isset($bal['balance']) && (float)$bal['balance'] == 150, 'seed used comes off the lot (200 - 50 = 150)');
$hist = (new FieldWorkflows())->rotationHistory($pdo, $org, $site);
$ok(($hist[0]['crop_family'] ?? '') === 'Malvaceae', 'own crop family shows in rotation');
$throws(fn() => $gs->retireLocation($pdo, $org, $site, $bed), 'bed with a growing planting can\'t be retired', 'planting');
$gs->retireLocation($pdo, $org, $site, $bed2);
$ok((int)$one("SELECT active FROM garden_growing_locations WHERE id = ?", [$bed2]) === 0, 'empty bed retires');

// ── Harvest into stock ──
$loc = (int)$one("SELECT id FROM locations WHERE is_default = 1 LIMIT 1") ?: (int)$one("SELECT id FROM locations ORDER BY id LIMIT 1");
$ok($loc > 0, 'a stock location exists');
$pdo->exec("INSERT INTO inventory (id, category, name, price, stock_quantity, sku, product_type, track_inventory, is_active, unit) VALUES
  (51, 'Pantry', 'Okra (lb)', 4.00, 0, 'OKRA', 'produce', 1, 1, 'lb'),
  (52, 'Pantry', 'Farm mug', 12.00, 0, 'MUG', 'retail', 0, 1, 'each')");
$prods = garden_harvest_products($pdo);
$ok(isset($prods[51]) && !isset($prods[52]), 'only stock-tracked products are offered');
[$hid, $added] = garden_log_harvest($pdo, $org, $site, $pid, '2026-07-01', '12.5', 'lb', 'Farmstand', '', 51, '');
$ok($added === '12.500', 'harvest amount goes into stock when no stock amount is typed');
$ok((float)$one("SELECT stock_quantity FROM inventory WHERE id = 51") == 12.5, 'product stock went up 12.5');
$ok($one("SELECT reason FROM inventory_adjustments WHERE product_id = 51 ORDER BY id DESC LIMIT 1") === 'harvest', 'ledger reason is harvest');
$ok($one("SELECT source_type FROM inventory_adjustments WHERE product_id = 51 ORDER BY id DESC LIMIT 1") === 'garden_harvest', 'ledger points back at the harvest');
$ok((int)$one("SELECT product_id FROM garden_planting_harvest_events WHERE id = ?", [$hid]) === 51, 'harvest row remembers the product');
$ok(bcrf_stock_reason_label('harvest') === 'Picked in the garden', 'stock reason reads well');
[$hid2, $added2] = garden_log_harvest($pdo, $org, $site, $pid, '2026-07-03', '30', 'pods', '', '', 51, '2');
$ok($added2 === '2.000' && (float)$one("SELECT stock_quantity FROM inventory WHERE id = 51") == 14.5, 'stock amount can differ from the picked amount');
[$hid3, $added3] = garden_log_harvest($pdo, $org, $site, $pid, '2026-07-04', '3', 'lb', 'Home', '', null, '');
$ok($added3 === null && (float)$one("SELECT stock_quantity FROM inventory WHERE id = 51") == 14.5, 'harvest without a product leaves stock alone');
$before = (int)$one("SELECT COUNT(*) FROM garden_planting_harvest_events");
$throws(fn() => garden_log_harvest($pdo, $org, $site, $pid, '2026-07-05', '-1', 'lb', '', '', 51, '1'), 'bad harvest amount is refused', 'positive');
$ok((int)$one("SELECT COUNT(*) FROM garden_planting_harvest_events") === $before && (float)$one("SELECT stock_quantity FROM inventory WHERE id = 51") == 14.5, 'a refused harvest changes nothing');
$throws(fn() => garden_log_harvest($pdo, $org, $site, $pid, '2026-07-05', '1', 'lb', '', '', 52, ''), 'untracked product refused', 'stocked product');
$ok(count((new PlantingJournal())->harvestEvents($pdo, $org, $site)) === 3, 'three harvests listed');

// ── Tasks on a bed ──
$ok(isset(bcrf_record_task_kinds()['garden_area']), 'a task can link to a garden bed');
$ok(bcrf_record_name($pdo, 'garden_area', $bed) === 'Bed 1', 'linked bed shows its name');
$ok(bcrf_record_url($pdo, 'garden_area', $bed) === '/admin/garden/areas.php?site=' . $site . '#area-' . $bed, 'linked bed opens on Beds & areas');
$pdo->prepare("INSERT INTO tasks (title, status, related_type, related_id, created_at) VALUES ('Weed bed 1', 'open', 'garden_area', ?, UTC_TIMESTAMP())")->execute([(string)$bed]);
$ok(count(garden_tasks($pdo, [$bed])) === 1, 'Today lists the bed\'s open task');

// ── Menu ──
$keys = array_keys(bcrf_admin_nav_sections());
$ai = array_search('animals', $keys, true);
$ok($ai !== false && ($keys[$ai + 1] ?? '') === 'garden', 'Garden sits right under Animals in the menu');
$hrefs = [];
foreach (bcrf_admin_nav_sections()['garden']['items'] as $it) foreach ($it['tabs'] as $t) $hrefs[] = parse_url($t[1], PHP_URL_PATH);
foreach (array_unique($hrefs) as $href) $ok(is_file($root . $href), "menu page exists: $href");

echo $fails ? "$fails of $n checks failed\n" : "All $n garden checks passed\n";
exit($fails ? 1 : 0);
