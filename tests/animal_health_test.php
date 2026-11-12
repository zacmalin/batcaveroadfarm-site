<?php
// tests/animal_health_test.php — Farm OS Phase 5 slice 3 (Nov 12, 2026): health (includes/animal_health.php, migration 0042)
// against a real, THROWAWAY MySQL 8 database: medical records and follow-up tasks, medications and their dose tasks,
// stopping and leaving, vaccinations with next-due tasks, and the 14-day due list.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/animal_health_test.php
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
require_once $root . '/includes/animal_health.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (BcrfHealthError $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};
$today = bcrf_tasks_today();
$day = fn(int $n) => bcrf_tasks_add_days($today, $n);

// -- Migration --
$ok(bcrf_health_ready($pdo), 'tables exist');
(require $root . '/admin/migrations/0042_animal_health.php')($pdo);
$ok((int)$one("SELECT can_access FROM view_permissions WHERE role = 'moderator' AND view_name = 'animal_health'") === 1, 'managers get animal_health, once');

$pdo->exec("INSERT INTO classifications (class_name) VALUES ('Goat')");
$pdo->exec("INSERT INTO breeds (breed_name, slug) VALUES ('Nigerian Dwarf', 'nd')");
$ins = $pdo->prepare("INSERT INTO animals (name, breed_id, classification_id, date_of_birth, is_active) VALUES (?, 1, 1, '2023-01-01', 1)");
foreach (['Hazel', 'Bramble', 'Clover'] as $nm) $ins->execute([$nm]);
[$hazel, $bramble, $clover] = array_map('intval', $pdo->query("SELECT id FROM animals ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));

// -- Medical records --
$throws(fn() => bcrf_medical_save($pdo, 0, $hazel, ['kind' => 'x', 'title' => 'a', 'record_date' => $today]), 'bad kind', 'kind');
$throws(fn() => bcrf_medical_save($pdo, 0, $hazel, ['kind' => 'injury', 'title' => '', 'record_date' => $today]), 'no title', 'title');
$throws(fn() => bcrf_medical_save($pdo, 0, $hazel, ['kind' => 'injury', 'title' => 'a', 'record_date' => $day(1)]), 'future date', 'future');
$throws(fn() => bcrf_medical_save($pdo, 0, $hazel, ['kind' => 'injury', 'title' => 'a', 'record_date' => $today, 'follow_up_date' => $day(-1)]), 'follow-up before record', 'before');
$throws(fn() => bcrf_medical_save($pdo, 0, $hazel, ['kind' => 'injury', 'title' => 'a', 'record_date' => $today, 'cost' => 'lots']), 'bad cost', 'cost');
$throws(fn() => bcrf_medical_save($pdo, 0, 999, ['kind' => 'injury', 'title' => 'a', 'record_date' => $today]), 'unknown animal', 'roster');
$rec = bcrf_medical_save($pdo, 0, $hazel, ['kind' => 'injury', 'title' => 'Limping, back left', 'record_date' => $today, 'vet_name' => 'Dr. Lee',
                                          'diagnosis' => 'Sprain', 'treatment' => 'Rest', 'cost' => '$85.50', 'follow_up_date' => $day(5)], 1);
$r = $pdo->query("SELECT * FROM animal_medical_records WHERE id = $rec")->fetch();
$ok((int)$r['cost_cents'] === 8550 && $r['vet_name'] === 'Dr. Lee' && (int)$r['animal_id'] === $hazel, 'record saved, cost in cents');
$t = $pdo->query("SELECT * FROM tasks WHERE id = " . (int)$r['follow_up_task_id'])->fetch();
$ok($t && $t['due_date'] === $day(5) && $t['related_type'] === 'animal' && $t['related_id'] === (string)$hazel && strpos($t['title'], 'Follow-up: Limping') === 0 && strpos($t['title'], 'Hazel') !== false, 'follow-up task made on the date');
$oldTask = (int)$r['follow_up_task_id'];
bcrf_medical_save($pdo, $rec, $hazel, ['kind' => 'injury', 'title' => 'Limping, back left', 'record_date' => $today, 'follow_up_date' => $day(5), 'diagnosis' => 'Sprain, mild'], 1);
$ok((int)$one("SELECT follow_up_task_id FROM animal_medical_records WHERE id = ?", [$rec]) === $oldTask, 'same follow-up keeps its task');
$ok($one("SELECT cost_cents FROM animal_medical_records WHERE id = ?", [$rec]) === null && $one("SELECT updated_by FROM animal_medical_records WHERE id = ?", [$rec]) == 1, 'edit saves (cost cleared), marks who edited');
bcrf_medical_save($pdo, $rec, $hazel, ['kind' => 'injury', 'title' => 'Limping, back left', 'record_date' => $today, 'follow_up_date' => $day(9)], 1);
$newTask = (int)$one("SELECT follow_up_task_id FROM animal_medical_records WHERE id = ?", [$rec]);
$ok($newTask && $newTask !== $oldTask && $one("SELECT status FROM tasks WHERE id = ?", [$oldTask]) === 'cancelled' && $one("SELECT due_date FROM tasks WHERE id = ?", [$newTask]) === $day(9), 'moved follow-up: old task cancelled, new one made');
bcrf_medical_save($pdo, $rec, $hazel, ['kind' => 'injury', 'title' => 'Limping, back left', 'record_date' => $today, 'follow_up_date' => ''], 1);
$ok($one("SELECT follow_up_task_id FROM animal_medical_records WHERE id = ?", [$rec]) === null && $one("SELECT status FROM tasks WHERE id = ?", [$newTask]) === 'cancelled', 'follow-up removed: task cancelled');
$throws(fn() => bcrf_medical_save($pdo, $rec, $bramble, ['kind' => 'injury', 'title' => 'x', 'record_date' => $today]), 'record of another animal refused', 'exist');
$ok(count(bcrf_medical_list($pdo, $hazel)) === 1, 'listed');

// -- Medications --
$throws(fn() => bcrf_med_add($pdo, $hazel, ['name' => '', 'dose' => '2 ml', 'frequency' => 'daily', 'start_date' => $today]), 'no name', 'Which');
$throws(fn() => bcrf_med_add($pdo, $hazel, ['name' => 'Pen', 'dose' => '', 'frequency' => 'daily', 'start_date' => $today]), 'no dose', 'dose');
$throws(fn() => bcrf_med_add($pdo, $hazel, ['name' => 'Pen', 'dose' => '2', 'frequency' => 'hourly', 'start_date' => $today]), 'bad frequency', 'often');
$throws(fn() => bcrf_med_add($pdo, $hazel, ['name' => 'Pen', 'dose' => '2', 'frequency' => 'every_n', 'interval_days' => 1, 'start_date' => $today]), 'every 1 day refused', 'how many');
$throws(fn() => bcrf_med_add($pdo, $hazel, ['name' => 'Pen', 'dose' => '2', 'frequency' => 'daily', 'start_date' => $today, 'end_date' => $day(-1)]), 'end before start', 'before');
$med = bcrf_med_add($pdo, $hazel, ['name' => 'Penicillin', 'dose' => '2 ml', 'route' => 'injection', 'frequency' => 'twice_daily', 'start_date' => $today, 'end_date' => $day(4), 'notes' => 'Hold her collar'], 1);
$doses = $pdo->query("SELECT t.title, t.due_date, t.due_time, t.priority, t.description FROM animal_med_doses d JOIN tasks t ON t.id = d.task_id WHERE d.medication_id = $med ORDER BY t.due_date, t.due_time")->fetchAll();
$ok(count($doses) === 4, 'twice a day: four dose tasks for today and tomorrow');
$ok($doses[0]['title'] === 'Give Penicillin 2 ml (injection) — Hazel' && $doses[0]['due_time'] === '08:00:00' && $doses[1]['due_time'] === '17:00:00' && $doses[0]['priority'] === 'high' && $doses[0]['description'] === 'Hold her collar', 'dose task wording, times, notes');
$ok(bcrf_med_generate($pdo) === 0, 'running again makes nothing new');
$ok(bcrf_med_generate($pdo, null, $day(1)) === 2, 'next day tops up one more day');
$ok(bcrf_med_generate($pdo, null, $day(4)) === 2 && bcrf_med_generate($pdo, null, $day(5)) === 0, 'stops after the last day');
$every = bcrf_med_add($pdo, $clover, ['name' => 'Wormer', 'dose' => '5 ml', 'frequency' => 'every_n', 'interval_days' => 3, 'start_date' => $day(-1)], 1);
$ok((int)$one("SELECT COUNT(*) FROM animal_med_doses WHERE medication_id = ?", [$every]) === 0 && bcrf_med_generate($pdo, null, $day(2)) >= 1
    && $one("SELECT dose_date FROM animal_med_doses WHERE medication_id = ?", [$every]) === $day(2), 'every 3 days from yesterday: next dose in 2 days');
$asNeeded = bcrf_med_add($pdo, $clover, ['name' => 'Banamine', 'dose' => '1 ml', 'frequency' => 'as_needed', 'start_date' => $today], 1);
$ok((int)$one("SELECT COUNT(*) FROM animal_med_doses WHERE medication_id = ?", [$asNeeded]) === 0, 'as needed: no tasks');
$ok(bcrf_med_falls_on(['start_date' => '2026-11-01', 'end_date' => null, 'frequency' => 'weekly', 'interval_days' => null], '2026-11-15')
    && !bcrf_med_falls_on(['start_date' => '2026-11-01', 'end_date' => null, 'frequency' => 'weekly', 'interval_days' => null], '2026-11-14'), 'weekly falls on the same weekday');
// Stop: untouched future doses cancelled, a started one kept
$firstDose = (int)$one("SELECT task_id FROM animal_med_doses WHERE medication_id = ? ORDER BY dose_date, slot LIMIT 1", [$med]);
$pdo->prepare("UPDATE tasks SET status = 'done' WHERE id = ?")->execute([$firstDose]);
$c = bcrf_med_stop($pdo, $med, 'Vet said stop', 1);
$ok($c === 9 && $one("SELECT status FROM tasks WHERE id = ?", [$firstDose]) === 'done', 'stop cancels the nine open doses (today through day 5), keeps the done one');
$throws(fn() => bcrf_med_stop($pdo, $med, ''), 'stop twice refused', 'already');
$before = (int)$one("SELECT COUNT(*) FROM animal_med_doses WHERE medication_id = ?", [$med]);
bcrf_med_generate($pdo, null, $day(2));
$ok((int)$one("SELECT COUNT(*) FROM animal_med_doses WHERE medication_id = ?", [$med]) === $before, 'stopped med makes no more doses');
$ok((int)$one("SELECT COUNT(*) FROM tasks t JOIN animal_med_doses d ON d.task_id = t.id WHERE d.medication_id = ? AND t.status = 'open'", [$med]) === 0, 'no open dose tasks after stopping');
$ml = bcrf_med_list($pdo, $hazel);
$ok(count($ml) === 1 && !$ml[0]['active'] && $ml[0]['stop_reason'] === 'Vet said stop', 'list shows stopped');

// -- Leaving the farm stops medications --
$daily = bcrf_med_add($pdo, $bramble, ['name' => 'Vitamin B', 'dose' => '1 ml', 'frequency' => 'daily', 'start_date' => $today], 1);
bcrf_animal_set_status($pdo, $bramble, 'deceased', 'Old age', '', '', 1);
$ok($one("SELECT stopped_at FROM animal_medications WHERE id = ?", [$daily]) !== null, 'leaving stops the medication');
$ok((int)$one("SELECT COUNT(*) FROM tasks t JOIN animal_med_doses d ON d.task_id = t.id WHERE d.medication_id = ? AND t.status = 'open'", [$daily]) === 0, 'and its dose tasks are cancelled');
$throws(fn() => bcrf_med_add($pdo, $bramble, ['name' => 'X', 'dose' => '1', 'frequency' => 'daily', 'start_date' => $today]), 'no meds for animals that left', 'left');

// -- Vaccinations --
$throws(fn() => bcrf_vacc_add($pdo, $hazel, ['vaccine' => '', 'given_on' => $today]), 'no vaccine', 'Which');
$throws(fn() => bcrf_vacc_add($pdo, $hazel, ['vaccine' => 'CDT', 'given_on' => $day(1)]), 'future given date', 'future');
$throws(fn() => bcrf_vacc_add($pdo, $hazel, ['vaccine' => 'CDT', 'given_on' => $today, 'next_due' => $today]), 'next due not after', 'after');
$v1 = bcrf_vacc_add($pdo, $hazel, ['vaccine' => 'CDT', 'given_on' => $day(-360), 'given_by' => 'Casey', 'lot' => 'A12', 'next_due' => $day(5)], 1);
$due = (int)$one("SELECT due_task_id FROM animal_vaccinations WHERE id = ?", [$v1]);
$ok($due && $one("SELECT title FROM tasks WHERE id = ?", [$due]) === 'CDT vaccine — Hazel' && $one("SELECT due_date FROM tasks WHERE id = ?", [$due]) === $day(5), 'next due makes a task');
$v2 = bcrf_vacc_add($pdo, $clover, ['vaccine' => 'Rabies', 'given_on' => $day(-400), 'next_due' => $day(-35)], 1);
$v3 = bcrf_vacc_add($pdo, $clover, ['vaccine' => 'CDT', 'given_on' => $day(-100), 'next_due' => $day(60)], 1);
$d = bcrf_health_due($pdo);
$names = array_map(fn($v) => $v['animal_name'] . ' ' . $v['vaccine'], $d['vaccinations']);
$ok($names === ['Clover Rabies', 'Hazel CDT'], 'due list: overdue first, 14-day window, later ones left out');
$al = bcrf_health_alerts($pdo, $clover);
$ok(count($al) === 3 && strpos($al[0], 'Rabies vaccine overdue since') === 0 && strpos($al[1], 'On Banamine') === 0 && strpos($al[2], 'On Wormer') === 0, 'animal alerts: overdue vaccine, active medications');
// Given again: old due task done, falls off the due list, new task for the next one
bcrf_vacc_add($pdo, $hazel, ['vaccine' => 'CDT', 'given_on' => $today, 'next_due' => $day(365)], 1);
$ok($one("SELECT status FROM tasks WHERE id = ?", [$due]) === 'done' && $one("SELECT due_task_id FROM animal_vaccinations WHERE id = ?", [$v1]) === null, 'given again closes the old due task');
$ok(array_map(fn($v) => $v['animal_name'], bcrf_health_due($pdo)['vaccinations']) === ['Clover'], 'Hazel off the due list');
$ok((int)$one("SELECT COUNT(*) FROM tasks WHERE title = 'CDT vaccine — Hazel' AND status = 'open'") === 1, 'one open task for the next CDT');
$ok(count(bcrf_vacc_list($pdo, $hazel)) === 2, 'both shots listed');

// -- Follow-ups and medications on the due page --
bcrf_medical_save($pdo, 0, $clover, ['kind' => 'exam', 'title' => 'Annual check', 'record_date' => $today, 'follow_up_date' => $day(3)], 1);
$d = bcrf_health_due($pdo);
$ok(array_column($d['follow_ups'], 'title') === ['Annual check'], 'follow-ups listed');
$ok(array_column($d['medications'], 'name') === ['Banamine', 'Wormer'], 'active medications listed, as-needed included');
$ok(in_array('Wormer', array_column($d['medications'], 'name'), true) && !in_array('Vitamin B', array_column($d['medications'], 'name'), true), 'stopped meds and animals that left are not listed');

echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
