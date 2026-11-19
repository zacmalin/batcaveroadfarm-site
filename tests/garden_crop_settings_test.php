<?php
// tests/garden_crop_settings_test.php — Garden: farm frost dates, crop editor, reference numbers, OpenPlantDB copy, frost and seed
//   bells, packet photos, yield per sq ft, suggested dates (Nov 19, 2026; migration 0075),
// against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_all_test DB_USER=... DB_PASS=... php tests/garden_crop_settings_test.php
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
$ok(garden_ready($pdo), 'garden is ready after 0072-0075');
(require $root . '/admin/migrations/0075_garden_farm_frost_crop_settings.php')($pdo);
$ok($count('garden_crop_settings') === 0 && $count('garden_reference_plants') === 0, '0075 repeats safely');

$org = garden_org($pdo);
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role) VALUES (7, 'Pat', 'Grower', 'p@example.com', 'administrator')");
$_SESSION['user_id'] = 7; $_SESSION['user_role'] = 'administrator';
$gs = new GardenService(); $rec = new GrowingRecords(); $ss = new SeedStock(); $cc = new CustomCatalog();
$site = $gs->createSite($pdo, $org, 'Kitchen garden', 'America/New_York');
$site2 = $gs->createSite($pdo, $org, 'Frost pocket', 'America/New_York');
$bed = $gs->createLocation($pdo, $org, $site, 'Bed 1', 'raised_bed', garden_sqft_to_sqm('32'));
$gh = $gs->createLocation($pdo, $org, $site, 'Hoop house', 'greenhouse', null);
$siteRow = fn(int $id) => $pdo->query("SELECT * FROM garden_growing_sites WHERE id = $id")->fetch();

// ── Frost dates once for the farm ──
$ok(garden_farm_frost($pdo, $org) === [null, null], 'no farm frost dates yet');
$throws(fn() => garden_farm_frost_save($pdo, $org, '2026-11-01', '2026-04-01'), 'spring after fall is refused');
garden_farm_frost_save($pdo, $org, '2026-04-20', '2026-10-25');
$ok(garden_farm_frost($pdo, $org) === ['2000-04-20', '2000-10-25'], 'farm frost dates keep month and day');
$f = garden_frost_for($pdo, $org, $siteRow($site), 2027);
$ok($f['spring'] === '2027-04-20' && $f['fall'] === '2027-10-25' && $f['from'] === 'farm', 'a garden without its own dates uses the farm dates in any year');
$ok(garden_plan_year('10-25', '2026-11-19') === 2027 && garden_plan_year('10-25', '2026-06-01') === 2026, 'plan year rolls over after fall frost');
garden_site_frost_save($pdo, $org, $site2, '2026-05-05', '2026-10-10');
$f2 = garden_frost_for($pdo, $org, $siteRow($site2), 2027);
$ok($f2['spring'] === '2027-05-05' && $f2['from'] === 'garden', 'a garden can keep its own dates');
garden_site_frost_save($pdo, $org, $site2, '', '');
$ok(garden_frost_for($pdo, $org, $siteRow($site2), 2027)['from'] === 'farm', 'clearing a garden\'s dates goes back to the farm');

// ── Reference matching (built-in table) ──
$t = garden_reference_match('Tomatoes');
$ok($t && $t['start_indoors_weeks'] > 0 && $t['frost_tolerance'] === 'tender' && $t['source'] === GARDEN_REFERENCE_SOURCE, 'Tomatoes match the built-in tomato row');
$ok(garden_reference_match('Green bean') !== null && garden_reference_match('Chard') !== null, 'aliases match (bean, chard)');
$g = garden_reference_match('Garlic');
$ok($g && $g['plant_out_from'] === 'fall' && $g['plant_out_days'] < 0, 'garlic is planted before the fall frost');
$ok(garden_reference_match('Dragonfruit') === null, 'unknown crop has no reference');

// ── Crop settings: input, save, fill ──
$okra = $cc->createCrop($pdo, $org, 'Okra', 'annual');
$okraVar = $cc->createVariety($pdo, $org, $okra, 'Clemson Spineless', '56', 'from_sowing');
$key = 'o' . $okra;
$in = garden_crop_settings_in(['season' => 'warm', 'start_indoors_weeks' => '', 'plant_out_days' => '14', 'plant_out_when' => 'after', 'plant_out_from' => 'spring',
    'days_to_harvest' => '60', 'spacing_in' => '12', 'row_spacing_in' => '36', 'sow_depth_in' => '0.5', 'germ_temp_min_f' => '70', 'germ_temp_max_f' => '95',
    'sun_exposure' => 'full_sun', 'water_need' => 'moderate', 'frost_tolerance' => 'tender', 'soil_ph_min' => '6', 'soil_ph_max' => '7', 'notes' => 'Pick daily']);
$ok($in['plant_out_days'] === 14 && abs($in['spacing_cm'] - 30.5) < 0.1 && abs($in['germ_temp_min_c'] - 21.1) < 0.1, 'inches and °F become cm and °C');
$throws(fn() => garden_crop_settings_in(['plant_out_days' => '10', 'plant_out_when' => 'before', 'soil_ph_min' => '9', 'soil_ph_max' => '5']), 'pH backwards is refused', 'pH');
garden_crop_settings_save($pdo, $org, $key, $in);
$s = garden_crop_settings_get($pdo, $org, $key);
$ok((int)$s['plant_out_days'] === 14 && $s['source'] === null, 'typed-in settings saved with no source');
$plan = garden_crop_plan($s, garden_frost_for($pdo, $org, $siteRow($site), 2027));
$ok($plan['out'] === '2027-05-04' && $plan['ready'] === '2027-07-03' && $plan['how'] === 'direct', 'plan: sow 14 days after frost, ready 60 days later');
garden_crop_reference_fill($pdo, $org, $key, 'Okra', true);
$ok(garden_crop_settings_get($pdo, $org, $key)['start_indoors_weeks'] === null, 'fill blanks keeps "sown outside" when timing is set');
$ok((int)garden_crop_settings_get($pdo, $org, $key)['days_to_harvest'] === 60, 'fill blanks keeps typed numbers');

$tom = (int)$one("SELECT id FROM garden_crops WHERE common_name = 'Tomato'");
$n = garden_crop_reference_fill($pdo, $org, 's' . $tom, 'Tomato', true);
$ts = garden_crop_settings_get($pdo, $org, 's' . $tom);
$ok($n > 3 && $ts['start_indoors_weeks'] > 0 && $ts['source'] === GARDEN_REFERENCE_SOURCE, 'fill from the built-in table for a starter crop');
$pdo->exec("UPDATE garden_crop_settings SET days_to_harvest = 1 WHERE crop_id = $tom");
garden_crop_reference_fill($pdo, $org, 's' . $tom, 'Tomato', false);
$ok((int)garden_crop_settings_get($pdo, $org, 's' . $tom)['days_to_harvest'] > 1, '"use all" replaces typed numbers');
$throws(fn() => garden_crop_reference_fill($pdo, $org, $key, 'Dragonfruit'), 'fill with no reference says so', 'nothing');

// ── OpenPlantDB copy ──
$csv = sys_get_temp_dir() . '/opdb_test.csv';
$fh = fopen($csv, 'w');
fputcsv($fh, ['slug', 'common_name', 'category', 'season', 'sow_method', 'frost_tolerance', 'sun', 'water', 'maturity_from', 'days_to_germination_min', 'days_to_germination_max',
              'germination_soil_temp_f_min', 'germination_soil_temp_f_max', 'days_to_maturity_min', 'days_to_maturity_max', 'spacing_in_min', 'spacing_in_max', 'planting_anchor',
              'planting_weeks_from_anchor_min', 'planting_weeks_from_anchor_max'], ',', '"', '');
fputcsv($fh, ['tomato', 'Tomato', 'vegetable', 'warm', 'indoor-start', 'tender', 'full', 'moderate', 'transplant', 5, 10, 70, 85, 60, 85, 24, 36, 'last_frost', -8, -6], ',', '"', '');
fputcsv($fh, ['carrot', 'Carrot', 'vegetable', 'cool', 'direct', 'half-hardy', 'full', 'moderate', 'seed', 10, 21, 45, 85, 60, 80, 2, 3, 'last_frost', -3, 2], ',', '"', '');
fputcsv($fh, ['okra', 'Okra', 'vegetable', 'warm', 'direct', 'tender', 'full', 'low', 'seed', 7, 14, 75, 95, 50, 65, 12, 18, 'last_frost', 2, 4], ',', '"', '');
fputcsv($fh, ['marigold', 'Marigold', 'flower', 'warm', 'direct', 'tender', 'partial', 'low', 'seed', 5, 7, 70, 75, 50, 60, 8, 12, 'last_frost', 0, 1], ',', '"', '');
for ($i = 0; $i < 20; $i++) fputcsv($fh, ['filler-' . $i, 'Filler ' . $i, 'herb', 'cool', 'direct', 'hardy', 'full', 'low', 'seed', 5, 9, 50, 70, 40, 50, 6, 6, 'soil_workable', 0, 2], ',', '"', '');
fclose($fh);
$bad = sys_get_temp_dir() . '/opdb_bad.csv';
file_put_contents($bad, "a,b\n1,2\n");
$throws(fn() => garden_opdb_import_file($pdo, $bad, 'plants.csv'), 'a file that is not OpenPlantDB is refused', 'did not look like');
$ok(garden_opdb_import_file($pdo, $csv, 'plants.csv') === 24, 'CSV loads 24 plants');
$ok(garden_opdb_status($pdo)['count'] === 24, 'status counts the copy');
$ok(garden_opdb_import_file($pdo, $csv, 'plants.csv') === 24 && $count('garden_reference_plants') === 24, 'loading again replaces, not adds');
$c = garden_reference_match('Carrots', $pdo);
$ok($c && $c['source'] === GARDEN_OPDB_SOURCE && $c['germination_min_days'] === 10 && $c['water_need'] === 'moderate' && $c['days_to_harvest'] === 70, 'carrot: OpenPlantDB numbers win');
$ok($c['plant_out_days'] === -4 && $c['plant_out_from'] === 'spring', 'carrot timing from OpenPlantDB (-3..+2 weeks -> -4 days)');
$ok(abs($c['spacing_cm'] - 6.4) < 0.1 && $c['frost_tolerance'] === 'half_hardy', 'spacing middle in cm, frost tolerance underscored');
$tm = garden_reference_match('Tomato', $pdo);
$ok($tm['start_indoors_weeks'] === 7 && $tm['plant_out_days'] !== null && $tm['days_to_harvest'] === 73, 'tomato: 7 weeks indoors from OpenPlantDB, plant-out from built-in');
$mg = garden_reference_match('Marigold', $pdo);
$ok($mg && $mg['source'] === GARDEN_OPDB_SOURCE && $mg['sun_exposure'] === 'partial_shade' && $mg['row_spacing_cm'] === null, 'marigold only in OpenPlantDB');
$fl = garden_reference_match('Filler 3', $pdo);
$ok($fl['plant_out_days'] === -21, '"soil workable" anchor counts about 4 weeks before last frost');
$ok($tm['source'] === GARDEN_OPDB_SOURCE . ' + ' . GARDEN_REFERENCE_SOURCE && str_contains(garden_reference_credit($tm), 'OpenPlantDB') && str_contains(garden_reference_credit($tm), 'Growli'), 'tomato borrows plant-out from the built-in table; credit names both');
$ok(!str_contains(garden_reference_credit($mg), 'Growli') && str_contains(garden_reference_credit($t), 'Growli'), 'credit names only the source used');
$json = sys_get_temp_dir() . '/opdb_test.json';
$recs = [];
for ($i = 0; $i < 25; $i++) $recs[] = ['slug' => 'j' . $i, 'common_name' => 'Json plant ' . $i, 'category' => 'vegetable', 'days_to_maturity' => ['min' => 30, 'max' => 40], 'spacing_in' => ['min' => 4, 'max' => null],
    'planting' => ['anchor' => 'first_frost', 'weeks_from_anchor' => ['min' => -6, 'max' => -4]]];
file_put_contents($json, json_encode($recs));
$ok(garden_opdb_import_file($pdo, $json, 'plants.json') === 25, 'JSON array loads too');
$jp = garden_reference_match('Json plant 2', $pdo);
$ok($jp['plant_out_from'] === 'fall' && $jp['plant_out_days'] === -35 && $jp['days_to_harvest'] === 35 && abs($jp['spacing_cm'] - 10.2) < 0.1, 'JSON ranges, fall anchor, open-ended range');
file_put_contents($json, json_encode(['plants' => $recs]));
$ok(garden_opdb_import_file($pdo, $json, 'plants.json') === 25, 'JSON object with a plants list loads');
garden_opdb_import_file($pdo, $csv, 'plants.csv');
@unlink($csv); @unlink($bad); @unlink($json);

// ── Usage, suggested dates ──
$lot = $rec->createSeedLot($pdo, $org, 'custom:' . $okraVar, 'OK-1', 'Seed company', '2026-01-10', null, '');
$ss->startTracking($pdo, $org, $lot, '100', 'seeds', '2026-01-10', 7);
$pid = $rec->createPlanting($pdo, $org, $site, $bed, 'custom:' . $okraVar, $lot, '2026-05-04', 'direct_sow', '40', 'plants', 'active');
$u = garden_crop_usage($pdo, $org, $key);
$ok($u['active'] === 1 && $u['lots'] === 1, 'usage counts plantings and seed lots');
$plans = garden_variety_plans($pdo, $org, $siteRow($site), '2026-11-19');
$ok(($plans['custom:' . $okraVar]['out'] ?? '') === '2027-05-04' && ($plans['custom:' . $okraVar]['ready'] ?? '') === '2027-06-29', 'suggested dates use the variety\'s 56 days, next season');
$plans = garden_variety_plans($pdo, $org, $siteRow($site), '2026-03-01');
$ok(($plans['custom:' . $okraVar]['out'] ?? '') === '2026-05-04', 'before frost, suggests this year');

// ── Frost watch ──
$tomVar = (int)$one("SELECT id FROM garden_varieties WHERE crop_id = ?", [$tom]);
$rec->createPlanting($pdo, $org, $site, $gh, (string)$tomVar, null, '2026-05-01', 'transplant', '4', 'plants', 'active');
$cab = $cc->createCrop($pdo, $org, 'Kale', 'annual');
$cabVar = $cc->createVariety($pdo, $org, $cab, 'Red Russian', '50', 'from_sowing');
garden_crop_settings_save($pdo, $org, 'o' . $cab, ['frost_tolerance' => 'hardy']);
$rec->createPlanting($pdo, $org, $site, $bed, 'custom:' . $cabVar, null, '2026-09-01', 'transplant', '6', 'plants', 'active');
$per = fn(int $t) => [['name' => 'Tonight', 'start' => '2026-11-19T18:00:00-05:00', 'day' => false, 'temp' => $t], ['name' => 'Friday', 'start' => '2026-11-20T06:00:00-05:00', 'day' => true, 'temp' => 50]];
$ok(garden_frost_watch($pdo, $org, $per(40)) === null, 'no warning at 40°F');
$w = garden_frost_watch($pdo, $org, $per(33));
$ok($w && $w['date'] === '2026-11-19' && $w['crops'] === ['Okra in Bed 1'], 'tender okra warned; greenhouse tomato and hardy kale skipped');
garden_crop_settings_save($pdo, $org, $key, ['frost_tolerance' => 'half_hardy']);
$ok(garden_frost_watch($pdo, $org, $per(31)) === null, 'half-hardy not warned at 31°F');
$ok(garden_frost_watch($pdo, $org, $per(27)) !== null, 'half-hardy warned at 27°F');
garden_crop_settings_save($pdo, $org, $key, ['frost_tolerance' => 'tender']);

// ── Low seed ──
$ok(garden_seed_low($pdo, $org) === [], 'no low seed yet');
$throws(fn() => garden_seed_remind_save($pdo, $org, $lot, 'lots'), 'reminder must be a number', 'amount');
garden_seed_remind_save($pdo, $org, $lot, '50');
$ok(garden_seed_low($pdo, $org) === [], '100 left is above 50');
$ss->record($pdo, $org, $lot, 'used', '-60', '2026-05-04', 'Sowing', 7);
$low = garden_seed_low($pdo, $org);
$ok(count($low) === 1 && $low[0]['left'] == 40.0, 'low seed found at 40 left');
if (!function_exists('bcrf_notify')) require_once $root . '/includes/notifications.php';
$pdo->exec("INSERT INTO site_settings (setting_key, setting_value) VALUES ('wx_cache', '') ON DUPLICATE KEY UPDATE setting_value = ''");
$a1 = garden_notify_sweep($pdo);
$a2 = garden_notify_sweep($pdo);
$ok($a1 === 1 && $a2 === 0 && (int)$one("SELECT COUNT(*) FROM notifications WHERE kind = 'garden_seed_low'") === 1, 'one low-seed bell, not repeated');
$ss->record($pdo, $org, $lot, 'used', '-40', '2026-05-05', 'Sowing', 7);
$ok(garden_notify_sweep($pdo) === 1 && str_starts_with((string)$one("SELECT title FROM notifications WHERE kind = 'garden_seed_low' ORDER BY id DESC LIMIT 1"), 'Out of seed'), 'a new use rings again, "Out of seed" at 0');

// ── Packet photos ──
$dir = sys_get_temp_dir() . '/garden_seeds_' . getmypid();
putenv('BCRF_GARDEN_IMG_DIR=' . $dir);
$GLOBALS['garden_test_upload'] = true;
$img = imagecreatetruecolor(2400, 1200); imagefill($img, 0, 0, imagecolorallocate($img, 200, 120, 40));
$jpg = sys_get_temp_dir() . '/packet.jpg'; imagejpeg($img, $jpg, 80);
$p1 = garden_seed_photo_save($pdo, $org, $lot, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $jpg], 'front');
$f1 = $dir . '/' . basename($p1);
$ok(is_file($f1) && getimagesize($f1)[0] === 1600, 'front photo saved as WebP, 1600 px wide');
$p2 = garden_seed_photo_save($pdo, $org, $lot, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $jpg], 'front');
$ok(!is_file($f1) && is_file($dir . '/' . basename($p2)), 'a new front photo replaces the old file');
$txt = sys_get_temp_dir() . '/packet.txt'; file_put_contents($txt, 'not a photo');
$throws(fn() => garden_seed_photo_save($pdo, $org, $lot, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $txt], 'back'), 'a text file is refused', 'not a photo');
garden_seed_photo_unlink('/img/garden/seeds/../../config.php');
$ok(true, 'unlink ignores paths outside the pattern');
garden_seed_photo_remove($pdo, $org, $lot, 'front');
$ok(!is_file($dir . '/' . basename($p2)) && $one('SELECT photo_front FROM garden_seed_lots WHERE id = ?', [$lot]) === null, 'remove clears the photo and file');
@unlink($jpg); @unlink($txt); @rmdir($dir);

// ── Yield per square foot ──
$pdo->prepare('UPDATE garden_plantings SET allocated_area_sq_m = ? WHERE id = ?')->execute([garden_sqft_to_sqm('10'), $pid]);
$pdo->prepare("INSERT INTO garden_planting_harvest_events (organization_id, planting_id, harvested_on, quantity, loss_quantity, unit) VALUES (?, ?, '2026-07-10', 12, 2, 'lb'), (?, ?, '2026-07-20', 5, NULL, 'lb')")->execute([$org, $pid, $org, $pid]);
$y = garden_yield_per_area($pdo, $org, $site);
$ok(count($y['plantings']) === 1 && abs($y['plantings'][0]['per_sqft'] - 1.5) < 0.01, '15 lb usable on 10 sq ft = 1.5 lb/sq ft');
$ok(count($y['beds']) === 1 && $y['beds'][0]['bed'] === 'Bed 1' && abs($y['beds'][0]['sqft'] - 10) < 0.01, 'bed total');

// ── Delete removes the crop's settings ──
$pea = $cc->createCrop($pdo, $org, 'Pea', 'annual');
garden_crop_reference_fill($pdo, $org, 'o' . $pea, 'Pea');
$ok((int)$one('SELECT COUNT(*) FROM garden_crop_settings WHERE organization_crop_id = ?', [$pea]) === 1, 'pea has settings');
garden_delete_record($pdo, $org, 'crop', $pea);
$ok((int)$one('SELECT COUNT(*) FROM garden_crop_settings WHERE organization_crop_id = ?', [$pea]) === 0, 'deleting a crop removes its settings');

echo ($fails ? "FAILED $fails of $n\n" : "All $n passed\n");
exit($fails ? 1 : 0);
