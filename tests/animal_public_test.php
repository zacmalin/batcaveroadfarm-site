<?php
// tests/animal_public_test.php — Farm OS Phase 5 slice 9 (Nov 12, 2026): public profiles (includes/animal_public.php,
// migration 0048) against a real, THROWAWAY MySQL 8 database: words, fun facts, age line, publish rules, photos.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/animal_public_test.php
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
$_SESSION = ['user_id' => 1, 'user_role' => 'administrator'];
$imgDir = sys_get_temp_dir() . '/bcrf_public_test_' . getmypid();
putenv('BCRF_PUBLIC_IMG_DIR=' . $imgDir);
$GLOBALS['bcrf_public_test_upload'] = true;

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }
require_once $root . '/includes/animal_public.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (BcrfAnimalError $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};
$photo = function (int $w, int $h, string $type = 'jpeg') {
    $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, 120, 80, 40));
    $f = tempnam(sys_get_temp_dir(), 'pub'); $type === 'png' ? imagepng($im, $f) : imagejpeg($im, $f, 80);
    return ['name' => 'x.' . $type, 'tmp_name' => $f, 'error' => UPLOAD_ERR_OK, 'size' => filesize($f)];
};

// -- Migration --
$ok(bcrf_public_ready($pdo), 'tables exist');
(require $root . '/admin/migrations/0048_animal_public.php')($pdo);
$ok(bcrf_public_ready($pdo), 'migration repeats safely');

$pdo->exec("INSERT INTO classifications (class_name) VALUES ('Goat')");
$pdo->exec("INSERT INTO breeds (breed_name, slug) VALUES ('Nigerian Dwarf', 'nd')");
$pdo->exec("INSERT INTO animals (name, breed_id, classification_id, date_of_birth, is_active) VALUES ('Hazel Mae', 1, 1, '2023-03-03', 0), ('Bramble', 1, 1, '2024-06-01', 1)");
[$hazel, $bramble] = array_map('intval', $pdo->query("SELECT id FROM animals ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$ok($one("SELECT birthday_display FROM animals WHERE id = ?", [$hazel]) === 'age', 'existing animals default to showing age');

// -- Fun facts and age line --
$ok(bcrf_public_facts("- Loves chin scratches\r\n\r\n• Hates rain\n  * Climbs everything ") === ['Loves chin scratches', 'Hates rain', 'Climbs everything'], 'fun facts split by line, bullets dropped');
$ok(count(bcrf_public_facts(str_repeat("fact\n", 9))) === 6, 'up to 6 fun facts');
$ok(bcrf_public_age_line('2023-03-03', false, 'age', '2026-11-12') === '3 yrs old', 'age line shows whole years');
$ok(bcrf_public_age_line('2023-03-03', true, 'age', '2026-11-12') === 'About 3 yrs old', 'guessed birth date says About');
$ok(bcrf_public_age_line('2023-03-03', false, 'birthday', '2026-11-12') === 'Birthday: March 3 (3 yrs old)', 'birthday line');
$ok(bcrf_public_age_line('2023-03-03', true, 'birthday', '2026-11-12') === 'About 3 yrs old', 'guessed birthday falls back to age');
$ok(bcrf_public_age_line('2023-03-03', false, 'none', '2026-11-12') === '', 'none shows nothing');
$ok(bcrf_public_age_line('2026-10-01', false, 'age', '2026-11-12') === '1 mo old', 'young animal in months');

// -- Words --
$ok(bcrf_public_missing(bcrf_animal_get($pdo, $hazel)) === ['a main photo', 'a bio', 'a fun fact'], 'missing list for an empty page');
$throws(fn() => bcrf_public_save($pdo, $hazel, ['birthday_display' => 'zodiac']), 'bad age choice refused', 'age');
$throws(fn() => bcrf_public_save($pdo, $hazel, ['fun_fact' => str_repeat('x', 301)]), 'long fun fact refused', '300');
$throws(fn() => bcrf_public_save($pdo, 999, []), 'unknown animal refused', 'roster');
$ch = bcrf_public_save($pdo, $hazel, ['tagline' => ' Gentlest goat ', 'bio' => "Hazel came to us in 2023.\r\nShe loves kids.", 'fun_fact' => "- Loves chin scratches\n\nHates rain", 'birthday_display' => 'birthday', 'photo_alt_text' => '']);
$a = bcrf_animal_get($pdo, $hazel);
$ok($ch === ['tagline', 'bio', 'fun_fact', 'birthday_display'], 'changed fields reported');
$ok($a['tagline'] === 'Gentlest goat' && $a['bio'] === "Hazel came to us in 2023.\nShe loves kids." && $a['fun_fact'] === "Loves chin scratches\nHates rain" && $a['birthday_display'] === 'birthday', 'words saved and tidied');
$ok(bcrf_public_save($pdo, $hazel, ['tagline' => 'Gentlest goat', 'bio' => $a['bio'], 'fun_fact' => $a['fun_fact'], 'birthday_display' => 'birthday']) === [], 'saving the same words changes nothing');
$ok(bcrf_public_missing($a) === ['a main photo'], 'only the photo is missing now');

// -- Publish rules --
bcrf_public_set($pdo, $hazel, true);
$ok((int)$one("SELECT is_active FROM animals WHERE id = ?", [$hazel]) === 1, 'published');
$throws(fn() => bcrf_public_set($pdo, $hazel, true), 'publishing twice refused', 'already');
bcrf_public_set($pdo, $hazel, false);
$ok((int)$one("SELECT is_active FROM animals WHERE id = ?", [$hazel]) === 0, 'unpublished');
bcrf_animal_set_status($pdo, $bramble, 'sold', 'Sold to a neighbor', 'J. Smith', '', 1);
$ok((int)$one("SELECT is_active FROM animals WHERE id = ?", [$bramble]) === 0, 'leaving the farm takes the page off');
$throws(fn() => bcrf_public_set($pdo, $bramble, true), 'animal that left cannot be published', 'left the farm');
$ov = bcrf_public_overview($pdo);
$ok(count($ov) === 1 && (int)$ov[0]['id'] === $hazel && $ov[0]['missing'] === ['a main photo'], 'overview lists only animals on the farm, with what is missing');

// -- Photos --
$throws(fn() => bcrf_public_photo_add($pdo, $hazel, ['error' => UPLOAD_ERR_NO_FILE], '', false, 1), 'no file refused', 'Pick a photo');
$txt = tempnam(sys_get_temp_dir(), 'pub'); file_put_contents($txt, 'not a photo');
$throws(fn() => bcrf_public_photo_add($pdo, $hazel, ['tmp_name' => $txt, 'error' => UPLOAD_ERR_OK], '', false, 1), 'non-image refused', 'not a photo');
$r1 = bcrf_public_photo_add($pdo, $hazel, $photo(2400, 1200), 'Hazel in the barn', false, 1);
$a = bcrf_animal_get($pdo, $hazel);
$ok($r1['main'] && $a['photo_url'] === $r1['path'] && $a['photo_alt_text'] === 'Hazel in the barn', 'first photo becomes the main one, with its description');
$ok((bool)preg_match('#^/img/animals/hazel-mae-' . $hazel . '-[0-9a-f]{8}\.webp$#', $r1['path']), 'file named after the animal: ' . $r1['path']);
$sz = getimagesize($imgDir . '/' . basename($r1['path']));
$ok($sz && $sz[0] === 1600 && $sz[1] === 800 && $sz[2] === IMAGETYPE_WEBP, 'shrunk to 1600px WebP');
$ok(bcrf_public_missing($a) === [], 'nothing missing now');
$r2 = bcrf_public_photo_add($pdo, $hazel, $photo(800, 600, 'png'), 'Hazel eating', false, 1);
$r3 = bcrf_public_photo_add($pdo, $hazel, $photo(600, 800), '', false, 1);
$ph = bcrf_public_photos($pdo, $hazel);
$ok(!$r2['main'] && count($ph) === 2 && $ph[0]['path'] === $r2['path'] && $ph[1]['path'] === $r3['path'], 'more photos listed in order');
$ok((int)$ph[0]['width'] === 800 && (int)$ph[1]['height'] === 800 && $ph[1]['alt_text'] === null, 'sizes kept; blank description stored empty');
bcrf_public_photo_move($pdo, $hazel, (int)$ph[1]['id'], -1);
$ph = bcrf_public_photos($pdo, $hazel);
$ok($ph[0]['path'] === $r3['path'] && $ph[1]['path'] === $r2['path'], 'photo moved earlier');
bcrf_public_photo_move($pdo, $hazel, (int)$ph[0]['id'], -1);
$ok(bcrf_public_photos($pdo, $hazel)[0]['path'] === $r3['path'], 'moving the first one earlier does nothing');
bcrf_public_photo_alt($pdo, $hazel, (int)$ph[0]['id'], ' Hazel napping ');
$ok($one("SELECT alt_text FROM animal_photos WHERE id = ?", [$ph[0]['id']]) === 'Hazel napping', 'description changed');
bcrf_public_photo_make_main($pdo, $hazel, (int)$ph[1]['id']);
$a = bcrf_animal_get($pdo, $hazel); $ph2 = bcrf_public_photos($pdo, $hazel);
$ok($a['photo_url'] === $r2['path'] && $a['photo_alt_text'] === 'Hazel eating', 'extra photo made main');
$ok(count($ph2) === 2 && in_array($r1['path'], array_column($ph2, 'path'), true), 'old main photo moved to the extra photos');
$r4 = bcrf_public_photo_add($pdo, $hazel, $photo(300, 300), 'New main', true, 1);
$a = bcrf_animal_get($pdo, $hazel); $ph3 = bcrf_public_photos($pdo, $hazel);
$ok($a['photo_url'] === $r4['path'] && count($ph3) === 3 && $ph3[0]['path'] === $r2['path'], 'upload as main puts the old main first in the list');
$throws(fn() => bcrf_public_photo_make_main($pdo, $bramble, (int)$ph3[0]['id']), 'another animal\'s photo refused', 'not found');
bcrf_public_photo_remove($pdo, $hazel, (int)$ph3[0]['id']);
$ok(count(bcrf_public_photos($pdo, $hazel)) === 2 && is_file($imgDir . '/' . basename($r2['path'])), 'photo taken off the page; file kept');
for ($i = 0; $i < BCRF_PUBLIC_PHOTO_MAX - 2; $i++) bcrf_public_photo_add($pdo, $hazel, $photo(50, 50), '', false, 1);
$throws(fn() => bcrf_public_photo_add($pdo, $hazel, $photo(50, 50), '', false, 1), 'photo limit', 'Up to');

// -- Migration 0049: blank main photo descriptions filled from the roster --
$pdo->exec("INSERT INTO classifications (class_name) VALUES ('Donkey')");
$pdo->exec("INSERT INTO breeds (breed_name, slug) VALUES ('Miniature Donkey', 'md'), ('Unknown', 'unk'), ('Alpine', 'alp')");
$pdo->exec("INSERT INTO animals (name, breed_id, classification_id, date_of_birth, is_active, photo_url, photo_alt_text) VALUES
    ('Jack', 2, 2, '2020-01-01', 1, '/img/animals/donkey1.webp', NULL), ('Pip', 3, 1, '2021-01-01', 1, '/img/p.webp', ''),
    ('Ace', 4, 1, '2021-01-01', 1, '/img/a.webp', NULL), ('Kept', 1, 1, '2021-01-01', 1, '/img/k.webp', 'Kept in the sun'),
    ('NoPhoto', 1, 1, '2021-01-01', 1, NULL, NULL)");
(require $root . '/admin/migrations/0049_animal_photo_alt.php')($pdo);
$alt = fn(string $n) => $one("SELECT photo_alt_text FROM animals WHERE name = ?", [$n]);
$ok($alt('Jack') === 'Jack, a Miniature Donkey at Bat Cave Road Farm', '0049: breed already names the species — got ' . $alt('Jack'));
$ok($alt('Pip') === 'Pip, a goat at Bat Cave Road Farm', '0049: unknown breed uses the species — got ' . $alt('Pip'));
$ok($alt('Ace') === 'Ace, an Alpine goat at Bat Cave Road Farm', '0049: "an" before a vowel — got ' . $alt('Ace'));
$ok($alt('Kept') === 'Kept in the sun' && $alt('NoPhoto') === null, '0049: written descriptions and photo-less animals left alone');

// -- Public page reads only public fields --
$page = file_get_contents($root . '/petting-zoo/animals/animal.php');
$ok(!preg_match('/animal_(medical|med|vacc|weights|expenses|care)|incident_|microchip|\btag\b,/i', $page), 'public page reads nothing private');

foreach (glob($imgDir . '/*') as $f) @unlink($f); @rmdir($imgDir);
echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
