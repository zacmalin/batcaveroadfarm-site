<?php
// tests/garden_planner_test.php — Garden bed planner (Nov 19, 2026): migration 0073, bed details, planting time spans, rotation
// warnings, beds shared at the same time, time-aware bed space, lay out many beds, season windows and the map data,
// against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_all_test DB_USER=... DB_PASS=... php tests/garden_planner_test.php
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

use Garden\Domain\GardenService;
use Garden\Domain\GrowingRecords;
use Garden\Domain\PlantingLifecycle;
use Garden\Domain\CustomCatalog;

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (Throwable $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};

// ── Migration ──
$ok(garden_ready($pdo), 'garden is ready after 0072 + 0073');
foreach (['orientation', 'sun_exposure', 'irrigation', 'ground_cover', 'soil_type', 'notes'] as $c)
    $ok((int)$one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'garden_growing_locations' AND column_name = ?", [$c]) === 1, "beds have $c");
$ok((int)$one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'garden_plantings' AND column_name IN ('expected_end_on','ended_on')") === 2, 'plantings have expected and real end dates');
(require $root . '/admin/migrations/0073_garden_planner.php')($pdo);
$ok((int)$one("SELECT COUNT(*) FROM garden_crops WHERE scientific_name = 'Capsicum annuum'") === 1, 'migration repeats safely (one pepper)');
$ok($one("SELECT crop_family FROM garden_crops WHERE common_name = 'Cabbage'") === 'Brassicaceae', 'starter crops carry their family');
$ok((int)$one("SELECT v.days_to_maturity FROM garden_varieties v JOIN garden_crops c ON c.id = v.crop_id WHERE c.common_name = 'Radish'") === 28, 'starter varieties carry days to maturity');
$ok((int)$one("SELECT COUNT(*) FROM garden_crops") >= 30, 'about 35 common crops');

$org = garden_org($pdo);
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role) VALUES (7, 'Pat', 'Grower', 'p@example.com', 'administrator')");
$_SESSION['user_id'] = 7;
$_SESSION['user_role'] = 'administrator';
$gs = new GardenService();
$site = $gs->createSite($pdo, $org, 'Kitchen garden', 'America/New_York');
$var = fn(string $crop) => (string)$one("SELECT v.id FROM garden_varieties v JOIN garden_crops c ON c.id = v.crop_id WHERE c.common_name = ? ORDER BY v.id LIMIT 1", [$crop]);

// ── Bed details ──
$bed = $gs->createLocation($pdo, $org, $site, 'Bed 1', 'raised_bed', garden_sqft_to_sqm('32'));
(new Garden\Domain\GrowingAreaLayout())->save($pdo, $org, $site, $bed, garden_ft_to_m('8'), garden_ft_to_m('4'), '0', '0', '0');
$_POST = ['orientation' => '', 'sun_exposure' => 'full_sun', 'irrigation' => 'drip', 'ground_cover' => 'landscape_fabric', 'soil_type' => 'clay_loam', 'bed_notes' => 'Spigot at the east end'];
garden_bed_details_save($pdo, $org, $site, $bed, garden_bed_details_in());
$row = $pdo->query("SELECT * FROM garden_growing_locations WHERE id = $bed")->fetch();
$ok($row['sun_exposure'] === 'full_sun' && $row['irrigation'] === 'drip' && $row['ground_cover'] === 'landscape_fabric' && $row['soil_type'] === 'clay_loam', 'bed details saved');
$ok($row['orientation'] === null && $row['notes'] === 'Spigot at the east end', 'empty choice stays empty; notes saved');
$ok(garden_bed_runs($row) === 'East–west (from the map)', 'an unturned 8×4 bed runs east–west');
$row['rotation_degrees'] = 90; $ok(garden_bed_runs($row) === 'North–south (from the map)', 'turned 90° it runs north–south');
$row['orientation'] = 'ne_sw'; $ok(garden_bed_runs($row) === 'Northeast–southwest', 'a typed direction wins');
$_POST = ['sun_exposure' => 'moon'];
$throws(fn() => garden_bed_details_in(), 'a made-up sun choice is refused', 'sun');
$_POST = [];

// ── Spans ──
$ok(garden_planting_span(['planted_on' => '2026-04-01', 'status' => 'active', 'days_to_maturity' => 60])[1] === '2026-06-21', 'end = planted + 60 days + 21 days of picking');
$ok(garden_planting_span(['planted_on' => '2026-04-01', 'status' => 'active', 'expected_end_on' => '2026-07-04', 'days_to_maturity' => 60])[1] === '2026-07-04', 'a typed end wins');
$ok(garden_planting_span(['planted_on' => '2026-04-01', 'status' => 'completed', 'ended_on' => '2026-06-10', 'expected_end_on' => '2026-07-04'])[1] === '2026-06-10', 'the real end wins over the expected one');
$ok(garden_planting_span(['planted_on' => '2026-04-01', 'status' => 'active', 'lifecycle' => 'perennial'])[1] === null, 'a perennial stays until closed');
$ok(garden_planting_span(['planted_on' => '2026-04-01', 'status' => 'active'])[2] === true, 'a worked-out end is marked as a guess');
$ok(garden_spans_touch('2026-04-01', '2026-05-01', '2026-05-01', '2026-06-01') && !garden_spans_touch('2026-04-01', '2026-04-30', '2026-05-01', '2026-06-01'), 'spans touching on one day count; a day apart do not');
$ok(garden_season_window('2026-winter', '2026-11-19') === ['2026-12-01', '2027-02-28', 'Winter 2026'], 'winter runs into the next February');
$ok(garden_season_window('nonsense', '2026-11-19')[0] === '2026-11-19', 'an unknown season shows today');
$ok(isset(garden_season_options([], '2026-11-19')['2027-spring']), 'next year can be planned on the map');

// ── Rotation ──
$rec = new GrowingRecords();
$tom25 = $rec->createPlanting($pdo, $org, $site, $bed, $var('Tomato'), null, '2025-05-01', 'transplant', null, '', 'active');
$rec->setPlantingStatus($pdo, $org, $site, $tom25, 'completed');
$ok($one("SELECT ended_on FROM garden_plantings WHERE id = ?", [$tom25]) !== null, 'marking Done fills in when it came out');
$pdo->exec("UPDATE garden_plantings SET ended_on = '2025-09-15' WHERE id = $tom25");
$pep26 = $rec->createPlanting($pdo, $org, $site, $bed, $var('Pepper'), null, '2026-05-01', 'transplant', null, '', 'active');
$all = $rec->plantings($pdo, $org, $site);
$w = garden_rotation_warnings($all);
$ok(isset($w[$pep26]) && $w[$pep26][0]['year'] === 2025 && $w[$pep26][0]['crop'] === 'Tomato', 'pepper after tomato in the same bed is flagged (both nightshades)');
$ok(!isset($w[$tom25]), 'the earlier planting is not flagged');
$ok(str_contains(garden_rotation_text($w[$pep26]), 'Nightshades') && str_contains(garden_rotation_text($w[$pep26]), '2025'), 'the note names the family and the year');
$bean = $rec->createPlanting($pdo, $org, $site, $bed, $var('Bean'), null, '2026-09-01', 'direct_sow', null, '', 'planned');
$ok(!isset(garden_rotation_warnings($rec->plantings($pdo, $org, $site))[$bean]), 'beans after nightshades are fine');
$pdo->exec("UPDATE garden_plantings SET planted_on = '2022-05-01', ended_on = '2022-09-01' WHERE id = $tom25");
$ok(!isset(garden_rotation_warnings($rec->plantings($pdo, $org, $site))[$pep26]), 'a family 4 years back is long enough ago');
$pdo->exec("UPDATE garden_plantings SET planted_on = '2025-05-01', ended_on = '2025-09-15' WHERE id = $tom25");

// ── Sharing a bed at the same time ──
$cap = [$bed => (float)garden_sqft_to_sqm('32')];
$life = new PlantingLifecycle();
$lett = $rec->createPlanting($pdo, $org, $site, $bed, $var('Lettuce'), null, '2026-05-10', 'direct_sow', null, '', 'active');
$all = $rec->plantings($pdo, $org, $site);
$ov = garden_bed_overlaps($all, $cap)[$bed] ?? [];
$ok(count($ov) >= 1 && in_array($pep26, $ov[0]['ids'], true) && in_array($lett, $ov[0]['ids'], true) && !$ov[0]['measured'], 'pepper and lettuce in the bed at once, space not set: flagged');
$life->allocateArea($pdo, $org, $site, $pep26, garden_sqft_to_sqm('16'));
$life->allocateArea($pdo, $org, $site, $lett, garden_sqft_to_sqm('16'));
$all = $rec->plantings($pdo, $org, $site);
$ok(!isset(garden_bed_overlaps(array_filter($all, fn($p) => in_array((int)$p['id'], [$pep26, $lett], true)), $cap)[$bed]), '16 + 16 sq ft in a 32 sq ft bed fits: no flag');
$throws(fn() => $life->allocateArea($pdo, $org, $site, $lett, garden_sqft_to_sqm('20')), 'bed space past the bed at the same time is refused', 'same time');
// The fall beans come after both are out, so they can take the whole bed.
garden_planting_set_end($pdo, $org, $site, $pep26, '2026-08-20');
garden_planting_set_end($pdo, $org, $site, $lett, '2026-07-01');
$life->allocateArea($pdo, $org, $site, $bean, garden_sqft_to_sqm('32'));
$ok((float)$one("SELECT allocated_area_sq_m FROM garden_plantings WHERE id = ?", [$bean]) > 2.9, 'a fall crop after the summer ones can use the whole bed');
$ok(garden_peak_area([['2026-01-01', '2026-01-31', 2], ['2026-01-15', '2026-02-15', 3], ['2026-02-01', null, 4]]) == 7.0, 'peak bed space = most in use on any one day');
$warn = garden_planting_warnings($rec->plantings($pdo, $org, $site), $pep26, $cap);
$ok(count($warn) === 1 && str_contains($warn[0], 'Same family'), 'the planting page gets the rotation note');
$throws(fn() => garden_end_date_in('2026-04-01', '2026-05-01'), 'an end before the planting date is refused', 'before');
$ok(garden_end_date_in('', '2026-05-01') === null, 'empty end = work it out');

// ── Done/Failed steps record when it came out ──
$okra = $rec->createPlanting($pdo, $org, $site, $bed, $var('Okra'), null, '2026-06-01', 'direct_sow', null, '', 'active');
$life->record($pdo, $org, $site, $okra, 'failed', '2026-06-20', '', '', 'Deer');
$ok($one("SELECT ended_on FROM garden_plantings WHERE id = ?", [$okra]) === '2026-06-20' && $one("SELECT status FROM garden_plantings WHERE id = ?", [$okra]) === 'failed', 'a Failed step closes it on that day');
$rec->setPlantingStatus($pdo, $org, $site, $okra, 'active');
$ok($one("SELECT ended_on FROM garden_plantings WHERE id = ?", [$okra]) === null, 'reopening clears when it came out');
$rec->setPlantingStatus($pdo, $org, $site, $okra, 'failed');

// ── Lay out many ──
$ids = garden_lay_out_grid($pdo, $org, $site, ['prefix' => 'Bed', 'first' => 10, 'rows' => 2, 'cols' => 3, 'length_ft' => '8', 'width_ft' => '4', 'path_ft' => '3',
                                              'x_ft' => '0', 'y_ft' => '10', 'kind' => 'raised_bed', 'parent_id' => 0, 'upright' => false,
                                              'details' => ['sun_exposure' => 'full_sun', 'irrigation' => 'drip', 'orientation' => null, 'ground_cover' => null, 'soil_type' => null, 'notes' => null]]);
$ok(count($ids) === 6, 'a 2 × 3 grid makes 6 beds');
$b15 = $pdo->query("SELECT * FROM garden_growing_locations WHERE name = 'Bed 15'")->fetch();
$ok($b15 && garden_m_to_ft($b15['layout_x_m']) === '22' && garden_m_to_ft($b15['layout_y_m']) === '17', 'Bed 15 is third across, second down (22 ft, 17 ft)');
$ok($b15['sun_exposure'] === 'full_sun' && $b15['irrigation'] === 'drip', 'grid beds get the shared details');
$throws(fn() => garden_lay_out_grid($pdo, $org, $site, ['prefix' => 'Bed', 'first' => 12, 'rows' => 1, 'cols' => 1, 'length_ft' => '8', 'width_ft' => '4', 'path_ft' => '', 'x_ft' => '', 'y_ft' => '', 'kind' => 'raised_bed', 'parent_id' => 0]), 'a name already in use is refused', 'already');
$before = (int)$one("SELECT COUNT(*) FROM garden_growing_locations");
$throws(fn() => garden_lay_out_grid($pdo, $org, $site, ['prefix' => 'Row', 'first' => 1, 'rows' => 1, 'cols' => 3, 'length_ft' => '8', 'width_ft' => '4', 'path_ft' => '1', 'x_ft' => '', 'y_ft' => '', 'kind' => 'nope', 'parent_id' => 0]), 'a bad type stops the whole grid');
$ok((int)$one("SELECT COUNT(*) FROM garden_growing_locations") === $before, 'nothing half-made after a refusal');
// Long rows up and down inside a 40 × 30 ft field.
$field = $gs->createLocation($pdo, $org, $site, 'North field', 'field', null);
(new Garden\Domain\GrowingAreaLayout())->save($pdo, $org, $site, $field, garden_ft_to_m('40'), garden_ft_to_m('30'), garden_ft_to_m('0'), garden_ft_to_m('30'), '0');
$rows = garden_lay_out_grid($pdo, $org, $site, ['prefix' => 'Row', 'first' => 1, 'rows' => 1, 'cols' => 8, 'length_ft' => '28', 'width_ft' => '3', 'path_ft' => '2', 'x_ft' => '1', 'y_ft' => '1', 'kind' => 'row', 'parent_id' => $field, 'upright' => true, 'details' => []]);
$r1 = $pdo->query("SELECT * FROM garden_growing_locations WHERE id = " . (int)$rows[0])->fetch();
$ok(count($rows) === 8 && (int)$r1['rotation_degrees'] === 90 && (int)$r1['parent_id'] === $field, 'upright rows are turned 90° and sit in the field');
$throws(fn() => garden_lay_out_grid($pdo, $org, $site, ['prefix' => 'Row', 'first' => 20, 'rows' => 1, 'cols' => 12, 'length_ft' => '28', 'width_ft' => '3', 'path_ft' => '2', 'x_ft' => '1', 'y_ft' => '1', 'kind' => 'row', 'parent_id' => $field, 'upright' => true, 'details' => []]), 'rows that run past the field are refused', 'outside');

// ── Map data ──
$areas = $gs->locations($pdo, $org, $site);
$all = $rec->plantings($pdo, $org, $site);
$siteRow = ['id' => $site, 'name' => 'Kitchen garden'];
$m = garden_map_data($pdo, $org, $siteRow, $areas, $all, garden_season_window('2026-summer', '2026-11-19'), '2026-11-19');
$b1 = array_values(array_filter($m['beds'], fn($b) => $b['id'] === $bed))[0];
$crops = array_column($b1['plantings'], 'crop');
$ok(in_array('Pepper', $crops, true) && in_array('Lettuce', $crops, true) && !in_array('Tomato', $crops, true), 'summer 2026 map shows pepper and lettuce, not last year\'s tomato');
$ok($b1['len'] == 8 && $b1['wid'] == 4 && $b1['placed'], 'bed size comes through in feet');
$ok((bool)array_filter($b1['warnings'], fn($w) => str_contains($w, 'Same family')), 'the bed carries the rotation note');
$ok(in_array(['Sun', 'Full sun (6+ hours)'], $b1['facts'], true) && in_array(['Ground cover', 'Landscape fabric'], $b1['facts'], true), 'bed facts are listed');
$ok((bool)array_filter($m['legend']['family'], fn($l) => str_starts_with($l['label'], 'Solanaceae')), 'legend lists the families shown');
$m25 = garden_map_data($pdo, $org, $siteRow, $areas, $all, garden_season_window('2025', '2026-11-19'), '2026-11-19');
$b1y = array_values(array_filter($m25['beds'], fn($b) => $b['id'] === $bed))[0];
$ok(array_column($b1y['plantings'], 'crop') === ['Tomato'], '2025 map shows only the tomato');
$ok(garden_family_color('Solanaceae') === garden_family_color('solanaceae') && garden_family_color('Madeupaceae') !== '', 'family colors ignore case and cover any family');
$json = json_encode($m);
$ok($json !== false && strlen($json) > 100, 'map data turns into JSON');

// ── Pages and assets ──
foreach (['admin/assets/garden.js', 'admin/assets/garden.css', 'includes/garden_planner.php'] as $f) $ok(is_file($root . '/' . $f), "$f exists");
$src = file_get_contents($root . '/admin/garden/areas.php');
$ok(!preg_match('/\sstyle\s*=/i', $src) && !preg_match('/<script(?![^>]*type="application\/json")/i', $src), 'areas.php has no inline style or script (strict policy)');
$src = file_get_contents($root . '/admin/garden/plantings.php');
$ok(!preg_match('/\sstyle\s*=/i', $src) && !preg_match('/<script(?![^>]*type="application\/json")/i', $src), 'plantings.php has no inline style or script');

echo $fails ? "$fails of $n checks failed\n" : "All $n garden planner checks passed\n";
exit($fails ? 1 : 0);
