<?php
// tests/animal_schedules_test.php — Farm OS Phase 5 slice 5 (Nov 12, 2026): species care schedules (includes/animal_schedules.php,
// migration 0044) against a real, THROWAWAY MySQL 8 database: due dates, task making, finishing a task logs the care,
// logging care closes the task, skip/cancel, schedule changes and turning off, animals that leave.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/animal_schedules_test.php
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
require_once $root . '/includes/animal_schedules.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (BcrfCareError $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};
$today = bcrf_tasks_today();
$day = fn(int $n) => bcrf_tasks_add_days($today, $n);
$tasksFor = fn(int $aid) => $pdo->query("SELECT t.* FROM tasks t JOIN animal_care_schedule_tasks s ON s.task_id = t.id WHERE s.animal_id = $aid ORDER BY t.id")->fetchAll();
// Back-date a care log (logged_at is UTC; noon farm time on that day).
$backdate = function (int $logId, string $date) use ($pdo) {
    $utc = (new DateTimeImmutable($date . ' 12:00', bcrf_tasks_tz()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $pdo->prepare("UPDATE animal_care_logs SET logged_at = ? WHERE id = ?")->execute([$utc, $logId]);
};

// -- Migration --
$ok(bcrf_sched_ready($pdo), 'tables exist');
(require $root . '/admin/migrations/0044_animal_care_schedules.php')($pdo);
$ok(bcrf_sched_every_text(56) === 'every 8 weeks' && bcrf_sched_every_text(7) === 'every week' && bcrf_sched_every_text(10) === 'every 10 days' && bcrf_sched_every_text(1) === 'every day', 'interval wording');

// -- Animals and a care type --
$pdo->exec("INSERT INTO classifications (class_name) VALUES ('Goat'), ('Sheep')");
$pdo->exec("INSERT INTO breeds (breed_name, slug) VALUES ('Nigerian Dwarf', 'nd')");
$ins = $pdo->prepare("INSERT INTO animals (name, breed_id, classification_id, date_of_birth, is_active) VALUES (?, 1, ?, '2023-01-01', 1)");
foreach ([['Hazel', 1], ['Bramble', 1], ['Clover', 2]] as [$nm, $c]) $ins->execute([$nm, $c]);
[$hazel, $bramble, $clover] = array_map('intval', $pdo->query("SELECT id FROM animals ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$hoof = (int)$one("SELECT id FROM animal_care_types WHERE name = 'Hoof trim'");
if (!$hoof) { $pdo->exec("INSERT INTO animal_care_types (name, active) VALUES ('Hoof trim', 1)"); $hoof = (int)$pdo->lastInsertId(); }

// -- Saving --
$throws(fn() => bcrf_sched_save($pdo, 0, ['classification_id' => 99, 'care_type_id' => $hoof, 'every' => '8', 'unit' => 'weeks', 'start_date' => $today]), 'unknown species', 'species');
$throws(fn() => bcrf_sched_save($pdo, 0, ['classification_id' => 1, 'care_type_id' => 999, 'every' => '8', 'unit' => 'weeks', 'start_date' => $today]), 'unknown care type', 'what');
$throws(fn() => bcrf_sched_save($pdo, 0, ['classification_id' => 1, 'care_type_id' => $hoof, 'every' => '0', 'unit' => 'weeks', 'start_date' => $today]), 'every 0 refused', 'how often');
$throws(fn() => bcrf_sched_save($pdo, 0, ['classification_id' => 1, 'care_type_id' => $hoof, 'every' => '200', 'unit' => 'weeks', 'start_date' => $today]), 'over 2 years refused', '2 years');
$throws(fn() => bcrf_sched_save($pdo, 0, ['classification_id' => 1, 'care_type_id' => $hoof, 'every' => '8', 'unit' => 'weeks', 'start_date' => 'soon']), 'bad first date', 'first due');

// Bramble was trimmed 50 days ago; Hazel never. First due for never-done goats: in 3 days.
$old = bcrf_care_log($pdo, ['type' => $hoof, 'animals' => [$bramble]], 1)['id'];
$backdate($old, $day(-50));
$sid = bcrf_sched_save($pdo, 0, ['classification_id' => 1, 'care_type_id' => $hoof, 'every' => '8', 'unit' => 'weeks', 'start_date' => $day(3), 'note' => 'Trimmers in the cabinet'], 1);
$s = bcrf_sched_get($pdo, $sid);
$ok((int)$s['every_days'] === 56 && (int)$s['active'] === 1 && $s['note'] === 'Trimmers in the cabinet', 'saved as 56 days');
$throws(fn() => bcrf_sched_save($pdo, 0, ['classification_id' => 1, 'care_type_id' => $hoof, 'every' => '4', 'unit' => 'weeks', 'start_date' => $today]), 'one per species and type', 'already');

// -- Tasks made on save --
$th = $tasksFor($hazel); $tb = $tasksFor($bramble);
$ok(count($th) === 1 && $th[0]['title'] === 'Hoof trim — Hazel' && $th[0]['due_date'] === $day(3) && $th[0]['related_type'] === 'animal' && $th[0]['related_id'] === (string)$hazel, 'never done: task on the first due date');
$ok(strpos($th[0]['description'], 'Every 8 weeks for goat. Not logged yet.') === 0 && strpos($th[0]['description'], 'Trimmers in the cabinet') !== false, 'task says how often and the note');
$ok(count($tb) === 1 && $tb[0]['due_date'] === $day(6) && strpos($tb[0]['description'], 'Last done ') !== false, 'done 50 days ago: due in 6 days (56 after)');
$ok($tasksFor($clover) === [], 'sheep get nothing');
$ok(bcrf_sched_generate($pdo) === ['made' => 0, 'closed' => 0], 'running again makes nothing new');
$for = bcrf_sched_for_animal($pdo, $bramble);
$ok(count($for) === 1 && $for[0]['next'] === $day(6) && $for[0]['last'] === $day(-50), 'animal page: next due and last done');

// -- Finishing the task logs the care --
$_SESSION = ['user_id' => 1, 'user_role' => 'administrator'];
bcrf_task_set($pdo, (int)$th[0]['id'], 'done', 'All four done');
$logs = $pdo->query("SELECT l.*, la.animal_id FROM animal_care_logs l JOIN animal_care_log_animals la ON la.log_id = l.id WHERE l.care_type_id = $hoof ORDER BY l.id")->fetchAll();
$ok(count($logs) === 2 && (int)$logs[1]['animal_id'] === $hazel && $logs[1]['note'] === 'From task: Hoof trim — Hazel', 'finishing the task logged the care for Hazel');
$ok(bcrf_sched_for_animal($pdo, $hazel)[0]['next'] === $day(56), 'next due counted from today');
$ok(bcrf_sched_generate($pdo)['made'] === 0, 'no new task until it is a week out');
$ok(bcrf_sched_generate($pdo, $day(50))['made'] === 1 && count($tasksFor($hazel)) === 2, 'a week before: next task made');

// -- Logging care another way closes the open task --
$tb = $tasksFor($bramble);
bcrf_care_log($pdo, ['type' => $hoof, 'animals' => [$bramble]], 1);
$r = bcrf_sched_generate($pdo);
$ok($r['closed'] === 1 && $one("SELECT status FROM tasks WHERE id = ?", [(int)$tb[0]['id']]) === 'done' && strpos($one("SELECT completion_note FROM tasks WHERE id = ?", [(int)$tb[0]['id']]), 'Logged in care') === 0, 'care logged on the Care page closes the task');
$ok(count($pdo->query("SELECT id FROM animal_care_logs WHERE care_type_id = $hoof")->fetchAll()) === 3, 'closing it logs nothing extra');

// -- Skipping or cancelling leaves that due date alone --
$pdo->exec("INSERT INTO animals (name, breed_id, classification_id, date_of_birth, is_active) VALUES ('Fern', 1, 1, '2024-01-01', 1)");
$fern = (int)$pdo->lastInsertId();
bcrf_sched_generate($pdo);
$tf = $tasksFor($fern);
$ok(count($tf) === 1 && $tf[0]['due_date'] === $day(3), 'a new goat gets a task');
bcrf_task_set($pdo, (int)$tf[0]['id'], 'cancel', 'Vet will do it');
$ok(bcrf_sched_generate($pdo)['made'] === 0 && count($tasksFor($fern)) === 1, 'cancelled: not remade for the same date');
$ok($one("SELECT COUNT(*) FROM animal_care_logs l JOIN animal_care_log_animals la ON la.log_id = l.id WHERE la.animal_id = ?", [$fern]) == 0, 'cancelling logs nothing');

// -- Changing the schedule rebuilds tasks nobody started --
$open = $tasksFor($hazel);
$openId = (int)end($open)['id'];
bcrf_sched_save($pdo, $sid, ['every' => '6', 'unit' => 'weeks', 'start_date' => $day(3), 'note' => '', 'active' => '1'], 1);
$ok($one("SELECT status FROM tasks WHERE id = ?", [$openId]) === 'cancelled', 'interval change: open task cancelled');
$ok((int)bcrf_sched_get($pdo, $sid)['every_days'] === 42 && bcrf_sched_for_animal($pdo, $hazel)[0]['next'] === $day(42), 'new interval');
$tf = $tasksFor($fern);
$ok(count($tf) === 1 && $tf[0]['status'] === 'cancelled', 'a task someone cancelled stays cancelled through a schedule change');
// Turning it off
bcrf_sched_save($pdo, $sid, ['every' => '6', 'unit' => 'weeks', 'start_date' => $day(3)], 1);
$s = bcrf_sched_get($pdo, $sid);
$ok((int)$s['active'] === 0 && $pdo->query("SELECT COUNT(*) FROM tasks t JOIN animal_care_schedule_tasks s ON s.task_id = t.id WHERE t.status = 'open'")->fetchColumn() == 0, 'off: open tasks cancelled');
$ok(bcrf_sched_generate($pdo, $day(60))['made'] === 0 && bcrf_sched_for_animal($pdo, $hazel) === [], 'off: nothing made, nothing listed');
bcrf_sched_save($pdo, $sid, ['every' => '6', 'unit' => 'weeks', 'start_date' => $day(3), 'active' => '1'], 1);
$ok(bcrf_sched_generate($pdo, $day(40))['made'] === 2 && count(array_filter($tasksFor($hazel), fn($t) => $t['status'] === 'open')) === 1, 'back on: tasks again when due');

// -- Animals that leave --
bcrf_animal_set_status($pdo, $fern, 'sold', 'Sold', 'A buyer', '', 1);
$ok(count(array_filter($tasksFor($fern), fn($t) => $t['status'] === 'open')) === 0, 'leaving cancels its schedule tasks');
$ok(bcrf_sched_generate($pdo, $day(60))['made'] >= 0 && count(array_filter($tasksFor($fern), fn($t) => $t['status'] === 'open')) === 0, 'no tasks for animals that left');
$ok((int)bcrf_sched_list($pdo)[0]['animal_count'] === 2, 'schedule counts goats on the farm');

// -- Other tasks about an animal log nothing --
$before = (int)$one("SELECT COUNT(*) FROM animal_care_logs");
$plain = bcrf_task_create($pdo, ['title' => 'Check Hazel', 'who' => 'anyone', 'related_type' => 'animal', 'related_id' => (string)$hazel]);
bcrf_task_set($pdo, $plain, 'done');
$ok((int)$one("SELECT COUNT(*) FROM animal_care_logs") === $before, 'a normal animal task logs no care');

// -- Care type turned off: the task still finishes --
$tb = array_values(array_filter($tasksFor($bramble), fn($t) => $t['status'] === 'open'));
if (!$tb) { bcrf_sched_generate($pdo, $day(40)); $tb = array_values(array_filter($tasksFor($bramble), fn($t) => $t['status'] === 'open')); }
$pdo->exec("UPDATE animal_care_types SET active = 0 WHERE id = $hoof");
$t = bcrf_task_set($pdo, (int)$tb[0]['id'], 'done');
$ok($t['status'] === 'done' && (int)$one("SELECT COUNT(*) FROM animal_care_logs") === $before, 'care type off: task finishes, nothing logged');

echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
