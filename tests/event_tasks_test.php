<?php
// tests/event_tasks_test.php — Farm OS Phase 3 slice 3 (Nov 11, 2026): checklist tasks for confirmed parties and bookings
// (includes/event_tasks.php, migration 0031) against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/event_tasks_test.php
// Refuses to run unless DB_NAME ends in "_test". Empties that database and builds it from every migration. Exit 0 = all passed.
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$dsn = 'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4';
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', $opts);
$root = dirname(__DIR__);
ini_set('log_errors', '0');

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }
require $root . '/includes/event_tasks.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$throws = function (callable $f, string $what, string $like = '') use ($ok) {
    try { $f(); $ok(false, "$what (no error)"); }
    catch (BcrfTaskError $e) { $ok($like === '' || stripos($e->getMessage(), $like) !== false, "$what [" . $e->getMessage() . "]"); }
};
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$rows = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchAll(); };
$as = function (string $role, int $uid) { $_SESSION = ['user_id' => $uid, 'user_role' => $role]; };
function hasViewAccess(string $role, string $view): bool {
    global $pdo;
    if ($role === 'administrator') return true;
    $st = $pdo->prepare("SELECT can_access FROM view_permissions WHERE role = ? AND view_name = ?");
    $st->execute([$role, $view]);
    return (int)$st->fetchColumn() === 1;
}

$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role, account_status) VALUES
  (1, 'Zac', 'Owner', 'o@x.test', 'administrator', 'active'), (3, 'Sam', 'Hand', 's@x.test', 'Staff', 'active')");
$pdo->exec("DELETE FROM site_hours");
$ins = $pdo->prepare("INSERT INTO site_hours (scope, day_of_week, block_order, open_time, close_time, is_closed) VALUES ('general', ?, 0, ?, ?, ?)");
foreach ([0, 2, 3, 4, 5, 6] as $d) $ins->execute([$d, '10:00:00', '16:00:00', 0]);
$ins->execute([1, null, null, 1]);
$pdo->exec("UPDATE task_recurrences SET active = 0");
$pdo->exec("DELETE FROM tasks");

// -- Migration and seeds --
$ok(bcrf_event_tasks_ready($pdo), 'migration 0031 ran');
$ok($rows("SELECT title FROM event_task_steps WHERE plan = 'party' ORDER BY day_offset, sort_order") === [['title' => 'Confirm the party'], ['title' => 'Party prep'], ['title' => 'Party day'], ['title' => 'Party clean-up']], 'four starter party steps');
$ok((int)$one("SELECT COUNT(*) FROM event_task_steps WHERE plan LIKE 'booking:%'") === 0, 'no booking types yet, so no group steps');
$pdo->exec("INSERT INTO booking_types (id, type_key, label, is_active, sort_order) VALUES (1, 'day_admission', 'Petting Zoo Day Admission', 1, 1), (2, 'group_visit', 'Farm Visit (groups of 6+)', 1, 2), (3, 'old_tour', 'Old tour', 0, 3)");
(require $root . '/admin/migrations/0031_event_tasks.php')($pdo);
$ok((int)$one("SELECT COUNT(*) FROM event_task_steps") === 4, 'seeds never added twice once steps exist');
$pdo->exec("DELETE FROM event_task_steps");
(require $root . '/admin/migrations/0031_event_tasks.php')($pdo);
$ok((int)$one("SELECT COUNT(*) FROM event_task_steps WHERE plan = 'booking:2'") === 3 && (int)$one("SELECT COUNT(*) FROM event_task_steps WHERE plan IN ('booking:1', 'booking:3')") === 0, 'group steps on the group type only (not day admission, not inactive)');
$ok(array_keys(bcrf_event_plans($pdo)) === ['party', 'booking:1', 'booking:2'], 'plans: parties, then active booking types');
$partyDay = $one("SELECT form_json FROM event_task_steps WHERE plan = 'party' AND title = 'Party day'");
$ok(count(json_decode($partyDay, true)) === 2, 'Party day asks guests and paid-in-full');

// -- A confirmed party --
$GLOBALS['bcrf_tasks_today'] = '2026-11-20';   // Friday
$pdo->exec("INSERT INTO event_inquiries (id, event_type, preferred_date, adult_count, child_count, birthday_age, customer_name, email, phone, status, special_requests, admin_notes)
            VALUES (2, 'birthday', '2026-12-05', 6, 8, 6, 'Emily Jenkins', 'e@x.test', '828-555-0100', 'quoted', 'Unicorn cake', '\$140 Dec 5th')");
$r = bcrf_event_tasks_sync($pdo);
$ok($r['made'] === 0, 'a quoted party gets nothing');
$pdo->exec("UPDATE event_inquiries SET status = 'confirmed' WHERE id = 2");
$r = bcrf_event_tasks_sync($pdo, 'event_inquiry', 2);
$ok($r['made'] === 4, 'confirmed party: 4 tasks');
$t = []; foreach ($rows("SELECT * FROM tasks WHERE related_type = 'event_inquiry' AND related_id = '2'") as $x) $t[strtok($x['title'], ':')] = $x;
$ok($t['Confirm the party']['due_date'] === '2026-11-28' && $t['Confirm the party']['due_time'] === '10:00:00', 'a week before at 10 AM');
$ok($t['Party prep']['due_date'] === '2026-12-04' && $t['Party prep']['due_time'] === '16:00:00', 'day before at closing (4 PM)');
$ok($t['Party day']['due_date'] === '2026-12-05' && $t['Party day']['due_time'] === '10:00:00' && $t['Party day']['priority'] === 'high', 'on the day at opening, high');
$ok($t['Party clean-up']['due_time'] === '16:00:00', 'clean-up at closing');
$ok($t['Party day']['title'] === 'Party day: Emily Jenkins' && str_contains($t['Party day']['description'], '14 guests (6 adults, 8 children)') && str_contains($t['Party day']['description'], 'Unicorn cake') && str_contains($t['Party day']['description'], '$140'), 'title and party details in the notes');
$ok(count(json_decode($t['Party day']['form_json'], true)) === 2 && count(json_decode($t['Party day']['checklist_json'], true)) === 5, 'questions and checklist copied');
$ok((int)$t['Party day']['event_step_id'] > 0, 'linked to its step');
$r = bcrf_event_tasks_sync($pdo);
$ok($r === ['made' => 0, 'moved' => 0, 'cancelled' => 0], 'running again changes nothing');
$c = bcrf_event_task_counts($pdo, 'event_inquiry', 2);
$ok($c === ['total' => 4, 'done' => 0, 'open' => 4], 'counts for the inquiry screen');
$as('administrator', 1);
$ok(count(bcrf_tasks_list($pdo, ['status' => 'all', 'related' => 'event_inquiry:2'])) === 4 && count(bcrf_tasks_list($pdo, ['status' => 'all', 'related' => 'booking:2'])) === 0, 'Everyone list filters by event');

// Date moved: untouched tasks follow, a started one stays
bcrf_task_check($pdo, (int)$t['Confirm the party']['id'], 0, true);
$pdo->exec("UPDATE event_inquiries SET preferred_date = '2026-12-06' WHERE id = 2");
$r = bcrf_event_tasks_sync($pdo);
$ok($r['moved'] === 3, 'three untouched tasks moved');
$ok($one("SELECT due_date FROM tasks WHERE id = ?", [$t['Party day']['id']]) === '2026-12-06' && $one("SELECT due_date FROM tasks WHERE id = ?", [$t['Party prep']['id']]) === '2026-12-05', 'party day and prep follow the new date');
$ok($one("SELECT due_date FROM tasks WHERE id = ?", [$t['Confirm the party']['id']]) === '2026-11-28', 'started task keeps its date');
$ok(str_contains((string)$one("SELECT description FROM tasks WHERE id = ?", [$t['Party day']['id']]), 'Sun, Dec 6'), 'moved task notes show the new date');

// Finished one stays; the rest cancel when declined
bcrf_task_set($pdo, (int)$t['Confirm the party']['id'], 'done', '', true);
$pdo->exec("UPDATE event_inquiries SET status = 'declined' WHERE id = 2");
$r = bcrf_event_tasks_sync($pdo, 'event_inquiry', 2);
$ok($r['cancelled'] === 3, 'declined: 3 open tasks cancelled');
$ok($one("SELECT status FROM tasks WHERE id = ?", [$t['Confirm the party']['id']]) === 'done', 'the finished one stays done');
$ok(str_contains((string)$one("SELECT completion_note FROM tasks WHERE id = ?", [$t['Party day']['id']]), 'Event declined'), 'cancel note says why');
$pdo->exec("UPDATE event_inquiries SET status = 'confirmed' WHERE id = 2");
$r = bcrf_event_tasks_sync($pdo);
$ok($r['made'] === 0, 're-confirmed: cancelled tasks are not duplicated (reopen them from the task)');

// Confirmed late: steps whose day passed are due today
$pdo->exec("INSERT INTO event_inquiries (id, event_type, preferred_date, adult_count, child_count, customer_name, email, status) VALUES (5, 'birthday', '2026-11-23', 2, 3, 'Late Larry', 'l@x.test', 'confirmed')");
bcrf_event_tasks_sync($pdo);
$late = $rows("SELECT title, due_date, due_time FROM tasks WHERE related_type = 'event_inquiry' AND related_id = '5' AND title LIKE 'Confirm%'")[0];
$ok($late['due_date'] === '2026-11-20' && $late['due_time'] === null, 'a step whose day passed is due today, no time');
// A party that already happened: only after-steps still to come (none here)
$pdo->exec("INSERT INTO event_inquiries (id, event_type, preferred_date, adult_count, child_count, customer_name, email, status) VALUES (6, 'birthday', '2026-11-19', 2, 3, 'Yesterday Yan', 'y@x.test', 'confirmed')");
bcrf_event_tasks_sync($pdo);
$ok((int)$one("SELECT COUNT(*) FROM tasks WHERE related_type = 'event_inquiry' AND related_id = '6'") === 0, 'yesterday\'s party gets no before-steps');
$pdo->exec("INSERT INTO event_inquiries (id, event_type, preferred_date, adult_count, child_count, customer_name, email, status) VALUES (7, 'birthday', '2026-10-01', 2, 3, 'Old Olive', 'o2@x.test', 'confirmed')");
bcrf_event_tasks_sync($pdo);
$ok((int)$one("SELECT COUNT(*) FROM tasks WHERE related_type = 'event_inquiry' AND related_id = '7'") === 0, 'old confirmed party ignored');
$pdo->exec("INSERT INTO event_inquiries (id, event_type, preferred_date, adult_count, child_count, customer_name, email, status) VALUES (8, 'birthday', NULL, 2, 3, 'No Date Ned', 'n@x.test', 'confirmed')");
bcrf_event_tasks_sync($pdo);
$ok((int)$one("SELECT COUNT(*) FROM tasks WHERE related_type = 'event_inquiry' AND related_id = '8'") === 0, 'a confirmed party with no date waits for one');

// -- Bookings --
$tok = fn() => bin2hex(random_bytes(32));
$pdo->prepare("INSERT INTO bookings (id, type_id, customer_name, email, party_size, booking_date, start_time, end_time, status, token) VALUES (40, 2, 'Oak School', 's@school.test', 24, '2026-12-02', '10:30:00', '12:30:00', 'confirmed', ?)")->execute([$tok()]);
$pdo->prepare("INSERT INTO bookings (id, type_id, customer_name, email, party_size, booking_date, start_time, end_time, status, token) VALUES (41, 1, 'Day Ticket', 'd@x.test', 3, '2026-12-02', '10:00:00', '16:00:00', 'confirmed', ?)")->execute([$tok()]);
$r = bcrf_event_tasks_sync($pdo, 'booking', 40);
$ok($r['made'] === 3, 'group booking: 3 tasks');
$b = []; foreach ($rows("SELECT * FROM tasks WHERE related_type = 'booking' AND related_id = '40'") as $x) $b[strtok($x['title'], ':')] = $x;
$ok($b['Group arriving']['due_date'] === '2026-12-02' && $b['Group arriving']['due_time'] === '10:00:00', 'arriving: 30 min before the 10:30 start');
$ok($b['Group visit wrap-up']['due_time'] === '13:00:00', 'wrap-up: 30 min after the 12:30 end');
$ok($b['Group visit prep']['due_date'] === '2026-12-01' && $b['Group visit prep']['due_time'] === '16:00:00', 'prep: day before at closing');
$ok(str_contains($b['Group arriving']['description'], 'Party of 24') && str_contains($b['Group arriving']['description'], '10:30 AM–12:30 PM'), 'booking details in the notes');
bcrf_event_tasks_sync($pdo);
$ok((int)$one("SELECT COUNT(*) FROM tasks WHERE related_type = 'booking' AND related_id = '41'") === 0, 'day admission booking: nothing');
$pdo->exec("UPDATE bookings SET start_time = '09:00:00', end_time = '11:00:00' WHERE id = 40");
bcrf_event_tasks_sync($pdo);
$ok($one("SELECT due_time FROM tasks WHERE id = ?", [$b['Group arriving']['id']]) === '08:30:00', 'a time change moves the task too');
$pdo->exec("UPDATE bookings SET status = 'cancelled' WHERE id = 40");
$r = bcrf_event_tasks_sync($pdo, 'booking', 40);
$ok($r['cancelled'] === 3 && str_contains((string)$one("SELECT completion_note FROM tasks WHERE id = ?", [$b['Group arriving']['id']]), 'Event cancelled'), 'cancelled booking: tasks cancelled');
$pdo->prepare("INSERT INTO bookings (id, type_id, customer_name, email, party_size, booking_date, start_time, end_time, status, token) VALUES (42, 2, 'Pine School', 'p@school.test', 12, '2026-12-03', '13:00:00', '15:00:00', 'confirmed', ?)")->execute([$tok()]);
bcrf_event_tasks_sync($pdo);
$pdo->exec("UPDATE bookings SET status = 'completed' WHERE id = 42");
$r = bcrf_event_tasks_sync($pdo);
$ok($r['cancelled'] === 0 && (int)$one("SELECT COUNT(*) FROM tasks WHERE related_type = 'booking' AND related_id = '42' AND status = 'open'") === 3, 'completed booking keeps its tasks (wrap-up may still be to do)');
$pdo->exec("UPDATE bookings SET status = 'no_show' WHERE id = 42");
$r = bcrf_event_tasks_sync($pdo);
$ok($r['cancelled'] === 3, 'no-show: open steps cancelled');

// -- Editing steps --
$as('Staff', 3);
$throws(fn() => bcrf_event_step_save($pdo, ['plan' => 'party', 'title' => 'X']), 'only managers change event checklists', 'Owner or Manager');
$as('administrator', 1);
$throws(fn() => bcrf_event_step_save($pdo, ['plan' => 'booking:99', 'title' => 'X']), 'unknown plan refused', 'which kind');
$throws(fn() => bcrf_event_step_save($pdo, ['plan' => 'party', 'title' => 'X', 'anchor' => 'start']), 'parties have no start time', 'no start time');
$throws(fn() => bcrf_event_step_save($pdo, ['plan' => 'party', 'title' => 'X', 'day_offset' => -90]), 'offset limits', '60 before');
$throws(fn() => bcrf_event_step_save($pdo, ['plan' => 'party', 'title' => 'X', 'questions' => 'Q (huh)']), 'bad question refused', 'question 1');
$pdo->exec("INSERT INTO event_inquiries (id, event_type, preferred_date, adult_count, child_count, customer_name, email, status) VALUES (9, 'birthday', '2026-12-12', 4, 6, 'Holly', 'h@x.test', 'confirmed')");
bcrf_event_tasks_sync($pdo);
$sid = bcrf_event_step_save($pdo, ['plan' => 'party', 'title' => 'Thank-you note', 'day_offset' => 1, 'anchor' => 'time', 'at_time' => '11:00', 'checklist' => "Thank-you sent\nAsked for a Google review", 'photo_rule' => 'optional', 'who' => 'user:1']);
$new = $rows("SELECT * FROM tasks WHERE event_step_id = ? AND related_id = '9'", [$sid]);
$ok(count($new) === 1 && $new[0]['due_date'] === '2026-12-13' && $new[0]['due_time'] === '11:00:00' && (int)$new[0]['assignee_user_id'] === 1 && $new[0]['photo_rule'] === 'optional', 'a new step reaches parties already confirmed');
$ok(bcrf_event_step_when(bcrf_event_step_get($pdo, $sid)) === 'The day after, 11:00 AM', 'step timing described');
$prep = (int)$one("SELECT id FROM event_task_steps WHERE plan = 'party' AND title = 'Party prep'");
$p = bcrf_event_step_get($pdo, $prep);
bcrf_event_step_save($pdo, ['title' => 'Party set-up', 'day_offset' => -2, 'anchor' => 'closing', 'checklist' => implode("\n", $p['lines']), 'questions' => '', 'priority' => 'normal', 'who' => 'anyone', 'active' => '1'], $prep);
$ok($one("SELECT title FROM tasks WHERE event_step_id = ? AND related_id = '9' AND status = 'open'", [$prep]) === 'Party set-up: Holly'
    && $one("SELECT due_date FROM tasks WHERE event_step_id = ? AND related_id = '9' AND status = 'open'", [$prep]) === '2026-12-10', 'editing a step rebuilds untouched upcoming tasks');
$ok(bcrf_event_step_get($pdo, $prep)['plan'] === 'party', 'a step stays on its plan when edited');
$grp = (int)$one("SELECT id FROM event_task_steps WHERE plan = 'booking:2' AND title = 'Group arriving'");
$ok(bcrf_event_step_when(bcrf_event_step_get($pdo, $grp)) === 'On the day, 30 min before the start', 'booking step timing described');

echo ($fails ? "$fails of $n checks FAILED\n" : "All $n checks passed\n");
exit($fails ? 1 : 0);
