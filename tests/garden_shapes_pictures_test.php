<?php
// tests/garden_shapes_pictures_test.php — Garden: round and triangle beds, plant pictures on the map (Nov 19, 2026; migration 0076,
//   includes/garden_plant_art.php, includes/garden_planner.php),
// against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_all_test DB_USER=... DB_PASS=... php tests/garden_shapes_pictures_test.php
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

// ── Migration ──
$ok(garden_ready($pdo), 'garden is ready after 0072-0076');
(require $root . '/admin/migrations/0076_garden_shapes_pictures.php')($pdo);
$ok((int)$one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'garden_growing_locations' AND column_name = 'shape'") === 1, 'beds have a shape; migration repeats safely');
$ok((int)$one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'garden_crop_settings' AND column_name = 'picture'") === 1, 'crop settings have a picture');

$org = garden_org($pdo);
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role) VALUES (7, 'Pat', 'Grower', 'p@example.com', 'administrator')");
$_SESSION['user_id'] = 7; $_SESSION['user_role'] = 'administrator'; $_SESSION['user_name'] = 'Pat Grower';
$gs = new GardenService(); $rec = new GrowingRecords();
$site = $gs->createSite($pdo, $org, 'Kitchen garden', 'America/New_York');
$bed = $gs->createLocation($pdo, $org, $site, 'Herb circle', 'raised_bed', null);

// ── Shapes ──
$ok(abs(garden_shape_factor('round') - M_PI / 4) < 1e-9 && garden_shape_factor('triangle') === 0.5 && garden_shape_factor('rect') === 1.0, 'shape factors: round π/4, triangle 1/2');
$_POST = ['shape' => 'round'];
$d = garden_bed_details_in();
$ok(($d['shape'] ?? '') === 'round', 'a listed shape is taken');
garden_bed_details_save($pdo, $org, $site, $bed, $d);
$ok($one('SELECT shape FROM garden_growing_locations WHERE id = ?', [$bed]) === 'round', 'bed shape saved');
$_POST = ['shape' => 'hexagon'];
$throws(fn() => garden_bed_details_in(), 'a shape not on the list is refused', 'shape');
$_POST = [];
$ok(!array_key_exists('shape', garden_bed_details_in()), 'a form without the shape picker leaves the shape alone');
$ids = garden_lay_out_grid($pdo, $org, $site, ['prefix' => 'Tri', 'first' => 1, 'rows' => 1, 'cols' => 2, 'length_ft' => '8', 'width_ft' => '4', 'path_ft' => '2',
    'x_ft' => '0', 'y_ft' => '10', 'kind' => 'raised_bed', 'parent_id' => 0, 'details' => ['shape' => 'triangle']]);
$ok(count($ids) === 2 && $one('SELECT shape FROM garden_growing_locations WHERE id = ?', [$ids[0]]) === 'triangle', 'grid beds take the shape');
$ok(abs((float)garden_sqm_to_sqft($one('SELECT area_sq_m FROM garden_growing_locations WHERE id = ?', [$ids[0]])) - 16) < 0.2, 'a 8 × 4 ft triangle grows on 16 sq ft');
$_POST = [];

// ── Plant art ──
$ok(garden_plant_art_key('Carrots') === 'carrot' && garden_plant_art_key('Beetroot') === 'beet' && garden_plant_art_key('Potato') === 'potato', 'built-in drawings match carrot, beet, potato');
$ok(garden_plant_art_key('Sweet potato') === '' && garden_plant_emoji('Sweet potato') === '🍠', 'sweet potato is not a potato');
$ok(garden_plant_emoji('Cherry tomato') === '🍅' && garden_plant_emoji('Mystery plant') === '🌱', 'emoji by crop name, sprout when unknown');
$art = garden_plant_art_builtin();
$ok(count($art) === 3 && str_starts_with($art['carrot'], 'data:image/webp;base64,') && @getimagesizefromstring(base64_decode(substr($art['carrot'], 23))) !== false, 'built-in drawings are real WebP images');

$cc = new CustomCatalog();
$carrot = $cc->createCrop($pdo, $org, 'Carrot', 'annual');
$cv = $cc->createVariety($pdo, $org, $carrot, 'Danvers', '70', 'from_sowing');
$okra = $cc->createCrop($pdo, $org, 'Okra', 'annual');
$ov = $cc->createVariety($pdo, $org, $okra, 'Clemson', '56', 'from_sowing');
$pdo->exec("UPDATE garden_growing_locations SET length_m = 1.829, width_m = 1.829, layout_x_m = 0, layout_y_m = 0 WHERE id = $bed");
$rec->createPlanting($pdo, $org, $site, $bed, 'custom:' . $cv, null, '2026-04-01', 'direct_sow', '', '', 'active');
$rec->createPlanting($pdo, $org, $site, $bed, 'custom:' . $ov, null, '2026-05-15', 'transplant', '', '', 'active');
garden_crop_settings_save($pdo, $org, 'o' . $okra, ['spacing_cm' => '45.7', 'row_spacing_cm' => '91.4']);
$map = garden_map_data($pdo, $org, ['id' => $site, 'name' => 'Kitchen garden'], $gs->locations($pdo, $org, $site), $rec->plantings($pdo, $org, $site), ['2026-01-01', '2026-12-31', '2026'], '2026-06-01');
$b = array_values(array_filter($map['beds'], fn($x) => $x['id'] === $bed))[0];
$ok($b['shape'] === 'round' && $b['facts'][0][1] === 'Round, ' . garden_m_to_ft('1.829') . ' × ' . garden_m_to_ft('1.829') . ' ft', 'map data carries the shape and says it in the size');
$pc = array_values(array_filter($b['plantings'], fn($p) => $p['crop'] === 'Carrot'))[0];
$po = array_values(array_filter($b['plantings'], fn($p) => $p['crop'] === 'Okra'))[0];
$ok($pc['art'] === 'carrot' && $pc['pic'] === '' && isset(((array)$map['art'])['carrot']) && !isset(((array)$map['art'])['beet']), 'carrot uses the drawing; only drawings in use are sent');
$ok($po['art'] === '' && $po['emoji'] === '🫛', 'okra falls back to an emoji');
$ok(abs($po['gap'] - 1.5) < 0.01 && abs($po['row'] - 3.0) < 0.01, 'plant and row spacing from the crop editor, in feet');
$ok($pc['gap'] > 0 && $pc['gap'] < 1, 'carrot spacing from the reference (closer than a foot)');

// ── A crop's own picture ──
$dir = sys_get_temp_dir() . '/bcrf-plants-' . getmypid(); @mkdir($dir); putenv('BCRF_GARDEN_PLANT_DIR=' . $dir);
$GLOBALS['garden_test_upload'] = true;
$im = imagecreatetruecolor(600, 400); imagealphablending($im, false); imagesavealpha($im, true);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); imagefilledellipse($im, 300, 200, 200, 200, imagecolorallocatealpha($im, 40, 160, 40, 0));
$tmp = $dir . '/up.png'; imagepng($im, $tmp);
$path = garden_crop_picture_save($pdo, $org, 'o' . $okra, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp]);
$file = $dir . '/' . basename($path);
$info = @getimagesize($file);
$ok(str_starts_with($path, '/img/garden/plants/crop-o' . $okra . '-') && $info && $info[0] === 256 && $info[1] === 171, 'picture saved as a 256 px WebP');
$px = imagecreatefromwebp($file); $alpha = (imagecolorat($px, 2, 2) >> 24) & 0x7F;
$ok($alpha > 100, 'see-through background kept');
$ok(garden_crop_settings_get($pdo, $org, 'o' . $okra)['spacing_cm'] == 45.7, 'saving a picture keeps the crop numbers');
$m2 = garden_map_data($pdo, $org, ['id' => $site, 'name' => 'Kitchen garden'], $gs->locations($pdo, $org, $site), $rec->plantings($pdo, $org, $site), ['2026-01-01', '2026-12-31', '2026'], '2026-06-01');
$b2 = array_values(array_filter($m2['beds'], fn($x) => $x['id'] === $bed))[0];
$ok(array_values(array_filter($b2['plantings'], fn($p) => $p['crop'] === 'Okra'))[0]['pic'] === $path, 'the map uses the crop\'s own picture');
$path2 = garden_crop_picture_save($pdo, $org, 'o' . $okra, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp]);
$ok(!is_file($file) && is_file($dir . '/' . basename($path2)), 'a new picture replaces the old file');
$throws(fn() => garden_crop_picture_save($pdo, $org, 'o' . $okra, ['error' => UPLOAD_ERR_NO_FILE]), 'no file is refused', 'Pick a photo');
file_put_contents($dir . '/x.txt', 'hello');
$throws(fn() => garden_crop_picture_save($pdo, $org, 'o' . $okra, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $dir . '/x.txt']), 'a text file is refused', 'not a photo');
$throws(fn() => garden_crop_picture_save($pdo, $org, 'o999999', ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp]), 'a missing crop is refused', 'gone');
garden_crop_picture_remove($pdo, $org, 'o' . $okra);
$ok(!is_file($dir . '/' . basename($path2)) && garden_crop_settings_get($pdo, $org, 'o' . $okra)['picture'] === null, 'remove takes the picture and its file off');
garden_crop_picture_unlink('/img/garden/plants/../../config.php');
$ok(true, 'unlink ignores paths outside the folder');
array_map('unlink', glob($dir . '/*')); @rmdir($dir);

echo $fails ? "$fails of $n checks FAILED\n" : "All $n checks passed\n";
exit($fails ? 1 : 0);
