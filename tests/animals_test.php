<?php
// tests/animals_test.php — Farm OS Phase 5 slice 1 (Nov 12, 2026): roster, status and movement (includes/animals.php,
// migration 0040) against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/animals_test.php
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
require_once $root . '/includes/animals.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (BcrfAnimalError $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};

// -- Migration --
$ok(bcrf_animals_ready($pdo), 'tables and columns exist');
(require $root . '/admin/migrations/0040_animals_core.php')($pdo);
$ok(bcrf_animals_ready($pdo), 'safe to repeat');

// -- Seed: two classes, a breed, four animals (one is a draft, not published) --
$pdo->exec("INSERT INTO classifications (class_name) VALUES ('Goat'), ('Pig')");
$pdo->exec("INSERT INTO breeds (breed_name, slug) VALUES ('Nigerian Dwarf', 'nigerian-dwarf'), ('Kunekune', 'kunekune')");
$ins = $pdo->prepare("INSERT INTO animals (name, breed_id, classification_id, date_of_birth, is_active) VALUES (?, ?, ?, ?, ?)");
$ins->execute(['Hazel', 1, 1, '2023-04-10', 1]); $hazel = (int)$pdo->lastInsertId();
$ins->execute(['Bramble', 1, 1, '2024-06-01', 1]); $bramble = (int)$pdo->lastInsertId();
$ins->execute(['Clover', 1, 1, '2025-02-15', 1]); $clover = (int)$pdo->lastInsertId();
$ins->execute(['Truffle', 2, 2, '2022-09-01', 0]); $truffle = (int)$pdo->lastInsertId();
$ok($one("SELECT status FROM animals WHERE id = ?", [$hazel]) === 'active', 'existing animals start active');
$ok((int)$one("SELECT is_active FROM animals WHERE id = ?", [$truffle]) === 0, 'publish switch left as it was');

// -- Age --
$ok(bcrf_animal_age('2023-04-10', '2026-11-12') === '3 yrs 7 mos', 'age in years and months');
$ok(bcrf_animal_age('2026-10-01', '2026-11-12') === '1 mo', 'age in months');
$ok(bcrf_animal_age('2026-10-20', '2026-11-12') === '3 wks', 'age in weeks');
$ok(bcrf_animal_age('2027-01-01', '2026-11-12') === '' && bcrf_animal_age(null) === '', 'no age for future or missing dates');

// -- Identity --
bcrf_animal_save_identity($pdo, $hazel, ['sex' => 'female', 'tag' => ' Y-12 ', 'microchip' => '', 'date_of_birth' => '2023-04-01', 'birth_estimated' => '1']);
$a = bcrf_animal_get($pdo, $hazel);
$ok($a['sex'] === 'female' && $a['tag'] === 'Y-12' && $a['microchip'] === null && $a['date_of_birth'] === '2023-04-01' && (int)$a['birth_estimated'] === 1, 'identity saved and trimmed');
$throws(fn() => bcrf_animal_save_identity($pdo, $hazel, ['sex' => 'dragon', 'date_of_birth' => '2023-04-01']), 'bad sex refused', 'sex');
$throws(fn() => bcrf_animal_save_identity($pdo, $hazel, ['date_of_birth' => '2023-02-30']), 'bad date refused', 'birth date');
$throws(fn() => bcrf_animal_save_identity($pdo, $hazel, ['date_of_birth' => date('Y-m-d', strtotime('+3 days'))]), 'future birth date refused', 'future');

// -- Enclosures --
$barn = bcrf_enclosure_save($pdo, 0, 'Goat Barn', 2, 'Main barn', true);
$pen = bcrf_enclosure_save($pdo, 0, 'Pig Pen', null, '', true);
$old = bcrf_enclosure_save($pdo, 0, 'Old Shed', null, '', false);
$throws(fn() => bcrf_enclosure_save($pdo, 0, 'Goat Barn', null, '', true), 'duplicate name refused', 'already');
$throws(fn() => bcrf_enclosure_save($pdo, 0, '  ', null, '', true), 'blank name refused', 'name');
$throws(fn() => bcrf_enclosure_save($pdo, 0, 'X', 0, '', true), 'zero capacity refused', 'capacity');
$ok(count(bcrf_enclosures($pdo)) === 2 && count(bcrf_enclosures($pdo, true)) === 3, 'inactive enclosure hidden from the picker');
bcrf_enclosure_save($pdo, $barn, 'Goat Barn', 2, 'Main barn, east side', true);
$ok(bcrf_enclosure_get($pdo, $barn)['notes'] === 'Main barn, east side', 'enclosure updated');

// -- Movement --
$r = bcrf_animals_move($pdo, [$hazel, $bramble], $barn, 'morning move', 7);
$ok($r['moved'] === 2 && $r['warning'] === '', 'two moved, at capacity is fine');
$ok((int)$one("SELECT COUNT(*) FROM animal_locations WHERE enclosure_id = ? AND ended_at IS NULL", [$barn]) === 2, 'open stays written');
$r = bcrf_animals_move($pdo, [$hazel, $clover], $barn, '', 7);
$ok($r['moved'] === 1, 'animal already there is skipped');
$ok(strpos($r['warning'], 'capacity is 2') !== false && bcrf_enclosure_get($pdo, $barn)['animal_count'] === 3, 'over capacity warns, not blocks');
bcrf_animals_move($pdo, [$clover], $pen, '', 7);
$ok((int)$one("SELECT enclosure_id FROM animals WHERE id = ?", [$clover]) === $pen, 'moved again');
$ok((int)$one("SELECT COUNT(*) FROM animal_locations WHERE animal_id = ?", [$clover]) === 2 && (int)$one("SELECT COUNT(*) FROM animal_locations WHERE animal_id = ? AND ended_at IS NULL", [$clover]) === 1, 'old stay ended, one open');
bcrf_animals_move($pdo, [$clover], 0, '', 7);
$ok($one("SELECT enclosure_id FROM animals WHERE id = ?", [$clover]) === null && (int)$one("SELECT COUNT(*) FROM animal_locations WHERE animal_id = ? AND ended_at IS NULL", [$clover]) === 0, 'taken out of any enclosure');
$throws(fn() => bcrf_animals_move($pdo, [], $barn), 'nothing picked refused', 'at least one');
$throws(fn() => bcrf_animals_move($pdo, [$clover], $old), 'unused enclosure refused', 'in use');
$throws(fn() => bcrf_animals_move($pdo, [$clover, 9999], $pen), 'unknown animal refused', 'not on the roster');
$ok($one("SELECT enclosure_id FROM animals WHERE id = ?", [$clover]) === null, 'failed move changed nothing (rolled back)');
$ok(count(bcrf_animal_locations($pdo, $clover)) === 2, 'location history listed');

// -- Status: resting --
$throws(fn() => bcrf_animal_set_status($pdo, $hazel, 'active', ''), 'same status refused', 'already');
$throws(fn() => bcrf_animal_set_status($pdo, $hazel, 'napping', ''), 'unknown status refused', 'status');
$d = bcrf_animal_set_status($pdo, $hazel, 'resting', 'limping', '', '', 7);
$ok($d['unpublished'] === false && $d['left_enclosure'] === false, 'resting keeps page and enclosure');
$ok((int)$one("SELECT is_active FROM animals WHERE id = ?", [$hazel]) === 1, 'resting animal stays published');
$offc = bcrf_animals_off_contact($pdo);
$ok(count($offc) === 1 && $offc[0]['name'] === 'Hazel' && $offc[0]['enclosure_name'] === 'Goat Barn', 'resting shows for the gate with its enclosure');
bcrf_animal_set_status($pdo, $bramble, 'quarantined', '', '', '', 7);
$ok(count(bcrf_animals_off_contact($pdo)) === 2, 'quarantined shows too (reason optional)');

// -- Status: leaving --
$pdo->prepare("INSERT INTO tasks (title, status, related_type, related_id, created_at) VALUES (?, ?, 'animal', ?, UTC_TIMESTAMP())")->execute(['Hoof trim — Hazel', 'open', (string)$hazel]);
$pdo->prepare("INSERT INTO tasks (title, status, related_type, related_id, created_at) VALUES (?, ?, 'animal', ?, UTC_TIMESTAMP())")->execute(['Weigh Hazel', 'done', (string)$hazel]);
$pdo->prepare("INSERT INTO tasks (title, status, related_type, related_id, created_at) VALUES (?, ?, 'animal', ?, UTC_TIMESTAMP())")->execute(['Brush Bramble', 'open', (string)$bramble]);
$throws(fn() => bcrf_animal_set_status($pdo, $hazel, 'sold', ''), 'leaving needs a reason', 'why');
$throws(fn() => bcrf_animal_set_status($pdo, $hazel, 'sold', 'Sold at the fall sale'), 'sale needs who', 'who');
$d = bcrf_animal_set_status($pdo, $hazel, 'sold', 'Sold at the fall sale', 'Jane Doe, Marion', 'R-1042', 7);
$ok($d['unpublished'] && $d['left_enclosure'] && $d['tasks'] === 1, 'sold: unpublished, left enclosure, one open task cancelled');
$a = bcrf_animal_get($pdo, $hazel);
$ok($a['status'] === 'sold' && (int)$a['is_active'] === 0 && $a['enclosure_id'] === null && $a['status_changed_at'] !== null, 'animal row updated');
$ok($one("SELECT status FROM tasks WHERE title = 'Weigh Hazel'") === 'done' && $one("SELECT status FROM tasks WHERE title = 'Brush Bramble'") === 'open', 'done tasks and other animals\' tasks untouched');
$ok((int)$one("SELECT COUNT(*) FROM animal_locations WHERE animal_id = ? AND ended_at IS NULL", [$hazel]) === 0, 'enclosure stay ended');
$hist = bcrf_animal_status_history($pdo, $hazel);
$ok(count($hist) === 2 && $hist[0]['to_status'] === 'sold' && $hist[0]['other_party'] === 'Jane Doe, Marion' && $hist[0]['sale_ref'] === 'R-1042' && $hist[0]['from_status'] === 'resting', 'history has both changes, newest first');
$ok(count(bcrf_animals_off_contact($pdo)) === 1, 'sold animal no longer on the gate list');
$throws(fn() => bcrf_animals_move($pdo, [$hazel], $barn), 'animal that left can\'t be moved', 'left the farm');
$d = bcrf_animal_set_status($pdo, $truffle, 'deceased', 'Old age', '', '', 7);
$ok($d['unpublished'] === false && $d['tasks'] === 0, 'deceased draft: nothing to unpublish');
$throws(fn() => bcrf_animal_set_status($pdo, $truffle, 'active', ''), 'coming back needs a reason', 'why');
bcrf_animal_set_status($pdo, $truffle, 'active', 'Entered by mistake', '', '', 7);
$ok($one("SELECT status FROM animals WHERE id = ?", [$truffle]) === 'active' && (int)$one("SELECT is_active FROM animals WHERE id = ?", [$truffle]) === 0, 'undo allowed; page stays off');

// -- Roster lists --
$names = fn(array $rows) => implode(',', array_column($rows, 'name'));
$ok($names(bcrf_animals_list($pdo)) === 'Bramble,Clover,Truffle', 'on the farm hides the sold animal, sorted by class then name');
$ok($names(bcrf_animals_list($pdo, 'off')) === 'Bramble', 'off-contact filter');
$ok($names(bcrf_animals_list($pdo, 'gone')) === 'Hazel', 'left filter');
$ok(count(bcrf_animals_list($pdo, 'all')) === 4, 'everyone');
$ok($names(bcrf_animals_list($pdo, '', $barn)) === 'Bramble', 'enclosure filter');
$ok($names(bcrf_animals_list($pdo, 'all', 0, 'y-1')) === 'Hazel', 'search by tag');
$ok($names(bcrf_animals_list($pdo, 'all', 0, '100%')) === '', 'search wildcards are literal');
$ok(bcrf_enclosure_get($pdo, $barn)['animal_count'] === 1, 'sold animal no longer counts in the barn');

echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
