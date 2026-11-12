<?php
// tests/animal_breeding_test.php — Farm OS Phase 5 slice 6 (Nov 12, 2026): breeding (includes/animal_breeding.php, migration 0045)
// against a real, THROWAWAY MySQL 8 database: recording breedings, the due task, outcomes, births adding kids, parents.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/animal_breeding_test.php
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

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }
require_once $root . '/includes/animal_breeding.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (BcrfAnimalError $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};
$today = bcrf_breeding_today();
$day = fn(int $n) => bcrf_breeding_add_days($today, $n);

// -- Migration --
$ok(bcrf_breeding_ready($pdo), 'table and parent columns exist');
(require $root . '/admin/migrations/0045_animal_breeding.php')($pdo);
$ok(true, 'migration repeats safely');

// -- Gestation --
$ok(bcrf_gestation_days('Goat') === 150 && bcrf_gestation_days('Goats') === 150 && bcrf_gestation_days('Nigerian goat') === 150, 'goat gestation from several spellings');
$ok(bcrf_gestation_days('Sheep') === 147 && bcrf_gestation_days('') === null && bcrf_gestation_days('Emu') === null, 'sheep known, unknown is null');

// -- Animals --
$pdo->exec("INSERT INTO classifications (class_name) VALUES ('Goat'), ('Sheep'), ('Emu')");
$pdo->exec("INSERT INTO breeds (breed_name, slug) VALUES ('Nigerian Dwarf', 'nd')");
$ins = $pdo->prepare("INSERT INTO animals (name, breed_id, classification_id, date_of_birth, is_active, sex) VALUES (?, 1, ?, '2023-01-01', 1, ?)");
foreach ([['Hazel', 1, 'female'], ['Buck', 1, 'male'], ['Ram', 2, 'male'], ['Nameless', 1, 'unknown'], ['Ewe', 2, 'female'], ['Emma', 3, 'female']] as [$nm, $c, $s]) $ins->execute([$nm, $c, $s]);
[$hazel, $buck, $ram, $nameless, $ewe, $emma] = array_map('intval', $pdo->query("SELECT id FROM animals ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$barn = bcrf_enclosure_save($pdo, 0, 'Goat Barn', null, '', true);
bcrf_animals_move($pdo, [$hazel], $barn, '', 1);

// -- Recording a breeding --
$bred = $day(-140);
$throws(fn() => bcrf_breeding_add($pdo, $buck, ['sire_outside' => 'X', 'bred_on' => $bred]), 'male dam refused', 'female');
$throws(fn() => bcrf_breeding_add($pdo, $nameless, ['sire_outside' => 'X', 'bred_on' => $bred]), 'unknown-sex dam refused', 'female');
$throws(fn() => bcrf_breeding_add($pdo, 999, ['sire_outside' => 'X', 'bred_on' => $bred]), 'unknown dam refused', 'roster');
$throws(fn() => bcrf_breeding_add($pdo, $hazel, ['bred_on' => $bred]), 'no sire refused', 'sire');
$throws(fn() => bcrf_breeding_add($pdo, $hazel, ['sire_id' => $nameless, 'bred_on' => $bred]), 'non-male sire refused', 'male');
$throws(fn() => bcrf_breeding_add($pdo, $hazel, ['sire_id' => $ram, 'bred_on' => $bred]), 'other species sire refused', 'different species');
$throws(fn() => bcrf_breeding_add($pdo, $hazel, ['sire_id' => $buck, 'bred_on' => $day(1)]), 'future date refused', 'future');
$throws(fn() => bcrf_breeding_add($pdo, $hazel, ['sire_id' => $buck, 'bred_on' => '2026-02-30']), 'bad date refused', 'real');
$throws(fn() => bcrf_breeding_add($pdo, $hazel, ['sire_id' => $buck, 'bred_on' => $bred, 'method' => 'magic']), 'bad method refused', 'how');
$throws(fn() => bcrf_breeding_add($pdo, $hazel, ['sire_id' => $buck, 'bred_on' => $bred, 'expected_on' => $bred]), 'expected on bred date refused', 'after');
$b1 = bcrf_breeding_add($pdo, $hazel, ['sire_id' => $buck, 'sire_outside' => 'ignored', 'bred_on' => $bred, 'notes' => 'Seen bred'], 1);
$b = bcrf_breeding_get($pdo, $b1);
$ok($b['status'] === 'bred' && $b['expected_on'] === bcrf_breeding_add_days($bred, 150) && $b['sire_outside'] === null && $b['sire_name'] === 'Buck' && $b['method'] === 'natural', 'expected date worked out from goat gestation, sire on roster');
$ok($b['expected_on'] === $day(10), 'expected in 10 days');
$t = $pdo->query("SELECT * FROM tasks WHERE id = " . (int)$b['due_task_id'])->fetch();
$ok($t && $t['status'] === 'open' && $t['due_date'] === $day(3) && $t['priority'] === 'high' && $t['related_type'] === 'animal' && (int)$t['related_id'] === $hazel, 'due task a week before, high, linked to Hazel');
$ok(strpos($t['title'], 'Hazel due to give birth around ') === 0, 'task title: ' . $t['title']);
$throws(fn() => bcrf_breeding_add($pdo, $hazel, ['sire_id' => $buck, 'bred_on' => $bred]), 'second open breeding refused', 'already has an open');
$ok(bcrf_breeding_alert($pdo, $hazel) === 'Due to give birth around ' . date('M j', strtotime($day(10))), 'overview alert within 30 days');
$ok(bcrf_breeding_alert($pdo, $buck) === '', 'no alert for the sire');
$ok(count(bcrf_breeding_list($pdo, $buck)) === 1 && count(bcrf_breeding_list($pdo, $hazel)) === 1, 'listed for dam and sire');
$ok(count(bcrf_breeding_open($pdo)) === 1 && !bcrf_breeding_recent($pdo), 'open list has it, recent empty');
$ok(bcrf_breeding_line($b) === 'Bred ' . date('M j, Y', strtotime($bred)) . ' to Buck · expected ' . date('M j', strtotime($day(10))), 'line: ' . bcrf_breeding_line($b));

// Outside sire, AI, species without a known gestation
$b2 = bcrf_breeding_add($pdo, $ewe, ['sire_outside' => 'Neighbor\'s ram', 'bred_on' => $day(-20), 'method' => 'ai'], 1);
$b = bcrf_breeding_get($pdo, $b2);
$ok($b['sire_id'] === null && $b['sire_outside'] === 'Neighbor\'s ram' && $b['expected_on'] === bcrf_breeding_add_days($day(-20), 147), 'outside sire, sheep gestation');
$ok(strpos(bcrf_breeding_line($b), "to Neighbor's ram (outside) (artificial insemination)") !== false, 'outside AI line: ' . bcrf_breeding_line($b));
$ok(bcrf_breeding_alert($pdo, $ewe) === '', 'not due within 30 days, no alert');
$b3 = bcrf_breeding_add($pdo, $emma, ['sire_outside' => 'Big Bird', 'bred_on' => $day(-5)], 1);
$b = bcrf_breeding_get($pdo, $b3);
$ok($b['expected_on'] === null && $b['due_task_id'] === null, 'no gestation known: no date, no task');

// -- Outcomes --
$throws(fn() => bcrf_breeding_set($pdo, $b2, 'born', []), 'born not allowed through set', 'what happened');
$throws(fn() => bcrf_breeding_set($pdo, 999, 'open', []), 'unknown breeding refused', "doesn't exist");
$oldTask = (int)bcrf_breeding_get($pdo, $b2)['due_task_id'];
bcrf_breeding_set($pdo, $b2, 'confirmed', ['expected_on' => $day(20), 'notes' => 'Ultrasound'], 1);
$b = bcrf_breeding_get($pdo, $b2);
$ok($b['status'] === 'confirmed' && $b['expected_on'] === $day(20) && $b['notes'] === 'Ultrasound', 'confirmed with a new date');
$ok($one("SELECT status FROM tasks WHERE id = ?", [$oldTask]) === 'cancelled' && (int)$b['due_task_id'] !== $oldTask && $one("SELECT due_date FROM tasks WHERE id = ?", [(int)$b['due_task_id']]) === $day(13), 'old task cancelled, new task a week before the new date');
$throws(fn() => bcrf_breeding_set($pdo, $b2, 'open', ['outcome_on' => $day(-30)]), 'outcome before breeding refused', 'between');
bcrf_breeding_set($pdo, $b2, 'open', ['notes' => 'Came back into heat'], 1);
$b = bcrf_breeding_get($pdo, $b2);
$ok($b['status'] === 'open' && $b['outcome_on'] === $today && $b['notes'] === "Ultrasound\nCame back into heat" && $b['due_task_id'] === null, 'not pregnant: closed today, notes added');
$ok($one("SELECT COUNT(*) FROM tasks WHERE related_id = ? AND status = 'open'", [(string)$ewe]) == 0, 'no open task left for the ewe');
$throws(fn() => bcrf_breeding_set($pdo, $b2, 'confirmed', []), 'closed breeding refused', 'already closed');
$ok(count(bcrf_breeding_recent($pdo)) === 1 && strpos(bcrf_breeding_line(bcrf_breeding_get($pdo, $b2)), '· not pregnant ') !== false, 'shows in recent as not pregnant');
$b4 = bcrf_breeding_add($pdo, $ewe, ['sire_id' => $ram, 'bred_on' => $today], 1);
$ok(bcrf_breeding_get($pdo, $b4)['sire_name'] === 'Ram', 'can be bred again once closed');

// -- Birth --
$throws(fn() => bcrf_breeding_birth($pdo, $b1, ['born_on' => $today]), 'nothing to record refused', 'at least one');
$throws(fn() => bcrf_breeding_birth($pdo, $b1, ['born_on' => $day(1), 'kids' => [['name' => 'Pip']]]), 'future birth refused', 'not in the future');
$throws(fn() => bcrf_breeding_birth($pdo, $b1, ['born_on' => $bred, 'kids' => [['name' => 'Pip']]]), 'birth on breeding date refused', 'after the breeding');
$throws(fn() => bcrf_breeding_birth($pdo, $b1, ['born_on' => $today, 'lost' => 'two']), 'lost must be a number', 'number');
$throws(fn() => bcrf_breeding_birth($pdo, $b1, ['born_on' => $today, 'kids' => array_fill(0, 9, ['name' => 'K'])]), 'more than 8 refused', 'more than 8');
$dueTask = (int)bcrf_breeding_get($pdo, $b1)['due_task_id'];
$ids = bcrf_breeding_birth($pdo, $b1, ['born_on' => $today, 'kids' => [['name' => ' Pip ', 'sex' => 'female'], ['name' => '', 'sex' => 'male'], ['name' => 'Moss', 'sex' => 'wether?']], 'lost' => '1', 'notes' => 'Easy'], 1);
$ok(count($ids) === 2, 'two kids added, blank row skipped');
$pip = $pdo->query("SELECT * FROM animals WHERE id = " . $ids[0])->fetch();
$moss = $pdo->query("SELECT * FROM animals WHERE id = " . $ids[1])->fetch();
$ok($pip['name'] === 'Pip' && $pip['sex'] === 'female' && $moss['sex'] === 'unknown', 'names trimmed, bad sex becomes unknown');
$ok((int)$pip['dam_id'] === $hazel && (int)$pip['sire_id'] === $buck && (int)$pip['breed_id'] === 1 && (int)$pip['classification_id'] === 1 && $pip['date_of_birth'] === $today, 'kid has parents, breed, species, birthday');
$ok((int)$pip['is_active'] === 0 && $pip['status'] === 'active', 'kid off the website, on the roster');
$ok((int)$pip['enclosure_id'] === $barn && (int)$moss['enclosure_id'] === $barn, 'kids in the dam\'s enclosure');
$ok($one("SELECT reason FROM animal_status_history WHERE animal_id = ?", [$ids[0]]) === 'Born on the farm to Hazel', 'history row');
$b = bcrf_breeding_get($pdo, $b1);
$ok($b['status'] === 'born' && $b['outcome_on'] === $today && (int)$b['born_alive'] === 2 && (int)$b['born_lost'] === 1 && $b['notes'] === "Seen bred\nEasy", 'breeding closed as born, counts kept');
$ok($one("SELECT status FROM tasks WHERE id = ?", [$dueTask]) === 'done', 'due task marked done');
$ok(strpos(bcrf_breeding_line($b), '· born ' . date('M j', strtotime($today)) . ': 2 alive, 1 lost') !== false, 'born line: ' . bcrf_breeding_line($b));
$ok(count(bcrf_animal_offspring($pdo, $hazel)) === 2 && count(bcrf_animal_offspring($pdo, $buck)) === 2, 'kids listed for dam and sire');
$ok(bcrf_breeding_alert($pdo, $hazel) === '' && count(bcrf_breeding_open($pdo)) === 2, 'no alert after birth; ewe and emu still open');
$throws(fn() => bcrf_breeding_birth($pdo, $b1, ['born_on' => $today, 'kids' => [['name' => 'X']]]), 'second birth refused', 'already closed');
// All lost
$ids = bcrf_breeding_birth($pdo, $b3, ['born_on' => $today, 'lost' => '2'], 1);
$b = bcrf_breeding_get($pdo, $b3);
$ok($ids === [] && $b['status'] === 'lost' && (int)$b['born_lost'] === 2, 'none alive: closed as lost');

// -- Parents --
[$pip] = array_map('intval', $pdo->query("SELECT id FROM animals WHERE name = 'Pip'")->fetchAll(PDO::FETCH_COLUMN));
$throws(fn() => bcrf_animal_set_parents($pdo, $pip, $pip, 0), 'own dam refused', 'own dam');
$throws(fn() => bcrf_animal_set_parents($pdo, $pip, $buck, 0), 'male dam refused', "isn't female");
$throws(fn() => bcrf_animal_set_parents($pdo, $pip, 0, $hazel), 'female sire refused', "isn't male");
$throws(fn() => bcrf_animal_set_parents($pdo, $pip, $ewe, 0), 'other species dam refused', 'different species');
$throws(fn() => bcrf_animal_set_parents($pdo, $hazel, $pip, 0), 'own kid as dam refused', 'own kid');
bcrf_animal_set_parents($pdo, $nameless, $hazel, $buck);
$ok((int)$one("SELECT dam_id FROM animals WHERE id = ?", [$nameless]) === $hazel && (int)$one("SELECT sire_id FROM animals WHERE id = ?", [$nameless]) === $buck, 'parents set');
bcrf_animal_set_parents($pdo, $nameless, 0, 0);
$ok($one("SELECT dam_id FROM animals WHERE id = ?", [$nameless]) === null && $one("SELECT sire_id FROM animals WHERE id = ?", [$nameless]) === null, 'parents cleared');

// -- A dam who leaves --
bcrf_animal_set_status($pdo, $ewe, 'sold', 'Sold', 'Neighbor', '', 1);
$ok(count(bcrf_breeding_open($pdo)) === 0, 'sold dam drops off the Expecting list');
$ok($one("SELECT status FROM tasks WHERE id = ?", [(int)bcrf_breeding_get($pdo, $b4)['due_task_id']]) === 'cancelled', 'her due task is cancelled when she leaves');
$throws(fn() => bcrf_breeding_add($pdo, $ewe, ['sire_id' => $ram, 'bred_on' => $today]), 'sold dam refused', 'left the farm');

echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
