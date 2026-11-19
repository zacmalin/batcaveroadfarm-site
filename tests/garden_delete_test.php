<?php
// tests/garden_delete_test.php — Garden: delete any record and bed colors (Nov 19, 2026; includes/garden_delete.php, migration 0074),
// against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_all_test DB_USER=... DB_PASS=... php tests/garden_delete_test.php
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
require_once $root . '/admin/includes/audit.php';
require_once $root . '/includes/garden.php';

use Garden\Domain\GardenService;
use Garden\Domain\GrowingRecords;
use Garden\Domain\PlantingLifecycle;
use Garden\Domain\PropagationReports;
use Garden\Domain\SuccessionSchedules;
use Garden\Domain\CustomCatalog;
use Garden\Domain\SeedStock;
use Garden\Domain\SeedGermination;
use Garden\Domain\PlantingJournal;
use Garden\Domain\IrrigationService;
use Garden\Domain\CareApplications;
use Garden\Domain\CropHealth;
use Garden\Domain\SoilPerennials;
use Garden\Domain\SpecializedGrowing;

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (Throwable $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};
$count = fn(string $t) => (int)$one("SELECT COUNT(*) FROM `$t`");

// ── Migration ──
$ok(garden_ready($pdo), 'garden is ready after 0072-0074');
(require $root . '/admin/migrations/0074_garden_delete_colors.php')($pdo);
$ok((int)$one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'garden_growing_locations' AND column_name = 'color'") === 1, 'beds have a color; migration repeats safely');

$org = garden_org($pdo);
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role) VALUES (7, 'Pat', 'Grower', 'p@example.com', 'administrator')");
$_SESSION['user_id'] = 7; $_SESSION['user_role'] = 'administrator'; $_SESSION['user_name'] = 'Pat Grower';
$gs = new GardenService(); $rec = new GrowingRecords(); $life = new PlantingLifecycle(); $ss = new SeedStock();
$site = $gs->createSite($pdo, $org, 'Kitchen garden', 'America/New_York');
$area = $gs->createLocation($pdo, $org, $site, 'North block', 'field', null);
$bed = $gs->addChildLocation($pdo, $org, $site, $area, 'Bed 1', 'raised_bed', garden_sqft_to_sqm('32'));
$bed2 = $gs->createLocation($pdo, $org, $site, 'Bed 2', 'raised_bed', garden_sqft_to_sqm('32'));

// ── Bed colors ──
$_POST = ['color' => '#8a3324'];
$d = garden_bed_details_in();
$ok(($d['color'] ?? '') === '#8a3324', 'a listed color is taken');
garden_bed_details_save($pdo, $org, $site, $bed2, $d);
$ok($one('SELECT color FROM garden_growing_locations WHERE id = ?', [$bed2]) === '#8a3324', 'bed color saved');
$_POST = ['color' => '#123456'];
$throws(fn() => garden_bed_details_in(), 'a color not on the list is refused', 'color');
$_POST = [];
$ok(!array_key_exists('color', garden_bed_details_in()), 'a form without the picker leaves the color alone');
$_POST = ['color' => ''];
$ok(garden_bed_details_in()['color'] === null, '"None" clears the color');
$_POST = [];
$pdo->exec("UPDATE garden_growing_locations SET length_m = 2.438, width_m = 1.219, layout_x_m = 0, layout_y_m = 0 WHERE id = $bed2");
$areas = $gs->locations($pdo, $org, $site);
$map = garden_map_data($pdo, $org, ['id' => $site, 'name' => 'Kitchen garden'], $areas, $rec->plantings($pdo, $org, $site), ['2026-01-01', '2026-12-31', '2026'], '2026-06-01');
$b2 = array_values(array_filter($map['beds'], fn($b) => $b['id'] === $bed2))[0];
$ok($b2['color'] === '#8a3324' && in_array(['Color', 'Barn red'], $b2['facts'], true), 'map data carries the bed color and names it');
$ok(($map['legend']['bed'][0]['label'] ?? '') === 'Barn red', 'map legend lists bed colors');

// ── A planting with everything hanging off it ──
$cc = new CustomCatalog();
$crop = $cc->createCrop($pdo, $org, 'Okra', 'annual');
$var = $cc->createVariety($pdo, $org, $crop, 'Clemson Spineless', '56', 'from_sowing');
$lot = $rec->createSeedLot($pdo, $org, 'custom:' . $var, 'OK-1', 'Seed company', '2026-01-10', null, '');
$ss->startTracking($pdo, $org, $lot, '200', 'seeds', '2026-01-10', 7);
$pid = $rec->createPlanting($pdo, $org, $site, $bed, 'custom:' . $var, $lot, '2026-05-01', 'transplant', '40', 'plants', 'active');
$ss->record($pdo, $org, $lot, 'used', '-50', '2026-05-01', 'Planting #' . $pid, 7);
$bal = fn() => (float)(array_values(array_filter($ss->balances($pdo, $org), fn($b) => (int)$b['id'] === $lot))[0]['balance'] ?? 0);
$ok($bal() == 150, 'seed came off the packet (150 left)');
$life->record($pdo, $org, $site, $pid, 'germinated', '2026-05-08', '', '', '');
(new PlantingJournal())->logCare($pdo, $org, $site, $pid, '2026-05-10', 'weeding', 'pulled');
$health = new CropHealth();
$inc = $health->open($pdo, $org, $site, $pid, 'pest', 'Aphids', 'low', '2026-06-01', '');
$health->intervene($pdo, $org, $site, $inc, '2026-06-02', 'Soap spray', '', '', '', '');
$tray = (new PropagationReports())->createTray($pdo, $org, $site, $pid, 'Tray A', '72', '2026-04-01', '');
$sched = (new SuccessionSchedules())->create($pdo, $org, $site, $bed, 'custom:' . $var, '2026-05-01', 14, 3, 'direct_sow', '');
(new SuccessionSchedules())->linkPlanting($pdo, $org, $site, $sched, 1, $pid);
$loc = (int)$one("SELECT id FROM locations WHERE is_default = 1 LIMIT 1") ?: (int)$one("SELECT id FROM locations ORDER BY id LIMIT 1");
$pdo->exec("INSERT INTO inventory (id, category, name, price, stock_quantity, sku, product_type, track_inventory, is_active, unit) VALUES (51, 'Pantry', 'Okra (lb)', 4.00, 0, 'OKRA', 'produce', 1, 1, 'lb')");
[$h1] = garden_log_harvest($pdo, $org, $site, $pid, '2026-07-01', '12', 'lb', 'Farmstand', '', 51, '');
[$h2] = garden_log_harvest($pdo, $org, $site, $pid, '2026-07-02', '3', 'lb', 'Home', '', null, '');
$ok((float)$one('SELECT stock_quantity FROM inventory WHERE id = 51') == 12, 'harvest put 12 lb in stock');
(new IrrigationService())->createIrrigation($pdo, $org, $site, $bed, '2', '2026-05-01', 'Drip', '');
$plan = (int)$one('SELECT MAX(id) FROM garden_irrigation_plans');
(new IrrigationService())->recordWatering($pdo, $org, $site, $plan, '2026-05-02', '', '', '');
$soilId = (new SoilPerennials())->addSoil($pdo, $org, $site, $bed, '2026-04-01', '6.5', '');
$job = (new CareApplications())->record($pdo, $org, $site, [$bed, $bed2], 'mulching', '2026-05-01', 'Straw', '', '', '');
$job1 = (new CareApplications())->record($pdo, $org, $site, [$bed], 'soil_amendment', '2026-04-01', 'Compost', '', '', '');

// One line at a time first.
$st0 = (int)$one('SELECT COUNT(*) FROM garden_planting_lifecycle_events WHERE planting_id = ?', [$pid]);
$life->record($pdo, $org, $site, $pid, 'completed', '2026-08-01', '', '', '');
$ok($one('SELECT status FROM garden_plantings WHERE id = ?', [$pid]) === 'completed', 'planting marked Done');
$done = (int)$one("SELECT id FROM garden_planting_lifecycle_events WHERE planting_id = ? AND event_type = 'completed'", [$pid]);
$msg = garden_delete_record($pdo, $org, 'step', $done);
$ok($one('SELECT status FROM garden_plantings WHERE id = ?', [$pid]) === 'active' && $one('SELECT ended_on FROM garden_plantings WHERE id = ?', [$pid]) === null, 'deleting the Done step puts the planting back to Growing');
$ok((int)$one('SELECT COUNT(*) FROM garden_planting_lifecycle_events WHERE planting_id = ?', [$pid]) === $st0, 'the step is gone');
garden_delete_record($pdo, $org, 'harvest', $h1);
$ok((float)$one('SELECT stock_quantity FROM inventory WHERE id = 51') == 0, 'deleting a harvest takes its stock back off');
$ok((int)$one("SELECT COUNT(*) FROM inventory_adjustments WHERE source_type = 'garden_harvest' AND source_id = ? AND quantity_change < 0", [$h1]) === 1, 'the stock ledger shows it coming back off');
$ok((int)$one("SELECT COUNT(*) FROM audit_events WHERE action = 'delete' AND object_type = 'garden_planting_harvest_events'") === 1, 'the change log keeps what was deleted');
$throws(fn() => garden_delete_record($pdo, $org, 'harvest', $h1), 'deleting twice says it is gone', 'already gone');
$throws(fn() => garden_delete_record($pdo, $org, 'nonsense', 1), 'unknown kind refused', 'Pick');
garden_delete_record($pdo, $org, 'soil', $soilId);
$ok($count('garden_soil_observations') === 0, 'soil note deleted');

// The farm's own crop can't go while it's used.
$throws(fn() => garden_delete_record($pdo, $org, 'variety', $var), 'variety in use is refused', 'planting');
$throws(fn() => garden_delete_record($pdo, $org, 'crop', $crop), 'crop in use is refused', 'seed lot');

// A bed with a planting in it goes with everything under it (the planting, its lines, the tray, watering, a one-bed job).
garden_delete_record($pdo, $org, 'area', $area);
$ok((int)$one('SELECT COUNT(*) FROM garden_growing_locations WHERE id IN (?, ?)', [$area, $bed]) === 0, 'area and the bed inside it are gone');
$ok((int)$one('SELECT COUNT(*) FROM garden_plantings WHERE id = ?', [$pid]) === 0, 'its planting is gone');
foreach (['garden_planting_lifecycle_events', 'garden_planting_care_events', 'garden_planting_harvest_events', 'garden_crop_health_incidents',
          'garden_crop_health_interventions', 'garden_propagation_trays', 'garden_irrigation_plans', 'garden_irrigation_events', 'garden_succession_schedules', 'garden_succession_rounds'] as $t)
    $ok($count($t) === 0, "$t emptied with the bed");
$ok($bal() == 200, 'seed the planting used went back to the packet');
$ok((int)$one('SELECT COUNT(*) FROM garden_care_applications WHERE id = ?', [$job1]) === 0, 'a bed job only on that bed is gone');
$ok((int)$one('SELECT COUNT(*) FROM garden_care_application_locations WHERE application_id = ?', [$job]) === 1, 'a bed job on two beds keeps the other bed');
$ok((int)$one('SELECT COUNT(*) FROM garden_growing_locations WHERE id = ?', [$bed2]) === 1, 'the other bed stays');

// Now nothing uses the variety: it can go, and so can its crop.
$var2 = $cc->createVariety($pdo, $org, $crop, 'Red Burgundy', '60', 'from_sowing');
$throws(fn() => garden_delete_record($pdo, $org, 'crop', $crop), 'crop with a seed lot still refused', 'seed lot');
$gt = (new SeedGermination())->record($pdo, $org, $lot, '2026-01-01', '2026-01-08', '10', '9', '', '');
garden_delete_record($pdo, $org, 'seed_lot', $lot);
$ok($count('garden_seed_lots') === 0 && $count('garden_seed_stock_movements') === 0 && $count('garden_seed_germination_tests') === 0, 'seed lot, its count history and tests are gone');
garden_delete_record($pdo, $org, 'variety', $var2);
$ok((int)$one('SELECT COUNT(*) FROM garden_organization_varieties WHERE id = ?', [$var2]) === 0, 'an unused variety deletes');
garden_delete_record($pdo, $org, 'crop', $crop);
$ok($count('garden_organization_crops') === 0 && $count('garden_organization_varieties') === 0, 'crop and its varieties deleted');

// Starter-list crops come off this farm's list and can come back.
$tomato = (int)$one("SELECT id FROM garden_crops WHERE common_name = 'Tomato'");
$tvars = (int)$one('SELECT COUNT(*) FROM garden_varieties WHERE crop_id = ?', [$tomato]);
$before = count(garden_variety_options($pdo, $org));
$msg = garden_delete_record($pdo, $org, 'shared_crop', $tomato);
$ok(stripos($msg, 'off your list') !== false, 'starter crop is taken off the list, not deleted');
$ok(count(garden_variety_options($pdo, $org)) === $before - $tvars, 'its varieties leave the pickers');
$ok((int)$one('SELECT COUNT(*) FROM garden_varieties WHERE crop_id = ?', [$tomato]) === $tvars, 'the shared list itself is untouched');
garden_variety_unhide_all($pdo, $org);
$ok(count(garden_variety_options($pdo, $org)) === $before, 'bring them back restores the pickers');
$oneVar = (int)$one('SELECT id FROM garden_varieties ORDER BY id LIMIT 1');
garden_delete_record($pdo, $org, 'shared_variety', $oneVar);
$ok(count(garden_variety_options($pdo, $org)) === $before - 1, 'one starter variety can come off');
garden_variety_unhide_all($pdo, $org);

// A whole garden goes with all of it.
$site2 = $gs->createSite($pdo, $org, 'Field', 'America/New_York');
$fb = $gs->createLocation($pdo, $org, $site2, 'Row 1', 'row', null);
$fk = $gs->addChildLocation($pdo, $org, $site2, $fb, 'Row 1a', 'row', null);
$fp = $rec->createPlanting($pdo, $org, $site2, $fk, (string)$oneVar, null, '2026-05-01', 'direct_sow', '', '', 'active');
(new PlantingJournal())->logCare($pdo, $org, $site2, $fp, '2026-05-10', 'weeding', '');
garden_delete_record($pdo, $org, 'site', $site2);
$ok((int)$one('SELECT COUNT(*) FROM garden_growing_sites WHERE id = ?', [$site2]) === 0 && (int)$one('SELECT COUNT(*) FROM garden_growing_locations WHERE site_id = ?', [$site2]) === 0, 'garden and its beds are gone');
$ok((int)$one('SELECT COUNT(*) FROM garden_plantings WHERE id = ?', [$fp]) === 0 && $count('garden_planting_care_events') === 0, 'its plantings and care are gone');
$ok((int)$one('SELECT COUNT(*) FROM garden_growing_sites WHERE id = ?', [$site]) === 1, 'the other garden stays');

// Delete button: managers only.
$ok(str_contains(garden_delete_button('areas.php', 'area', $bed2, 'Sure?'), 'garden_delete'), 'Owner sees the Delete button');
$_SESSION['user_role'] = 'staff';
$ok(garden_delete_button('areas.php', 'area', $bed2, 'Sure?') === '', 'staff without garden setup don\'t');
$_SESSION['user_role'] = 'administrator';

echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
