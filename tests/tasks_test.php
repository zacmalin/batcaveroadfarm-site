<?php
// tests/tasks_test.php — tasks and chores (Farm OS Phase 3 slice 1, Nov 11, 2026) against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/tasks_test.php
// Refuses to run unless DB_NAME ends in "_test". Empties that database, builds it from every migration, then
// checks chore days, open-days-only, seasons, the nightly generator, checklists, statuses and who sees what.
// Exit 0 = all passed. Never touches a real database.
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
(require $root . '/admin/migrations/0029_tasks.php')($pdo);   // safe to repeat

require $root . '/includes/tasks.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$throws = function (callable $f, string $what, string $like = '') use ($ok) {
    try { $f(); $ok(false, "$what (no error)"); }
    catch (BcrfTaskError $e) { $ok($like === '' || stripos($e->getMessage(), $like) !== false, "$what [" . $e->getMessage() . "]"); }
};
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$as = function (string $role, int $uid) { $_SESSION = ['user_id' => $uid, 'user_role' => $role]; };
function hasViewAccess(string $role, string $view): bool {   // stand-in for admin/includes/permission_checks.php
    global $pdo;
    if ($role === 'administrator') return true;
    $st = $pdo->prepare("SELECT can_access FROM view_permissions WHERE role = ? AND view_name = ?");
    $st->execute([$role, $view]);
    return (int)$st->fetchColumn() === 1;
}

// People: an owner, a manager, two farm hands, a shopkeeper
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role, account_status) VALUES
  (1, 'Zac', 'Owner', 'o@x.test', 'administrator', 'active'), (2, 'Mia', 'Manager', 'm@x.test', 'moderator', 'active'),
  (3, 'Sam', 'Hand', 's@x.test', 'Staff', 'active'), (4, 'Ann', 'Hand', 'a@x.test', 'Staff', 'active'), (5, 'Kim', 'Shop', 'k@x.test', 'Shopkeep', 'active')");
// Hours: closed Monday (1); Tue-Sun 10:00-4:00; Saturday two blocks 9-12, 1-5
$pdo->exec("DELETE FROM site_hours");
$ins = $pdo->prepare("INSERT INTO site_hours (scope, day_of_week, block_order, open_time, close_time, is_closed) VALUES ('general', ?, ?, ?, ?, ?)");
foreach ([0, 2, 3, 4, 5] as $d) $ins->execute([$d, 0, '10:00:00', '16:00:00', 0]);
$ins->execute([1, 0, null, null, 1]);
$ins->execute([6, 0, '09:00:00', '12:00:00', 0]); $ins->execute([6, 1, '13:00:00', '17:00:00', 0]);

// -- Migration --
$ok(bcrf_tasks_ready($pdo), 'migration 0029 ran');
$ok((int)$one("SELECT COUNT(*) FROM task_recurrences") === 4, 'four starter chores, once (ran twice)');
$ok((int)$one("SELECT COUNT(*) FROM task_recurrences WHERE assignee_user_id IS NULL AND assignee_role IS NULL") === 4, 'starter chores go to anyone on the farm');
$ok((int)$one("SELECT open_days_only FROM task_recurrences WHERE seed_key = 'opening'") === 1 && (int)$one("SELECT open_days_only FROM task_recurrences WHERE seed_key = 'morning_feed'") === 0, 'feeding every day; opening only on open days');
$ok(count(json_decode($one("SELECT checklist_json FROM task_recurrences WHERE seed_key = 'evening_feed'"), true)) === 7, 'evening checklist seeded');
$ok(hasViewAccess('Staff', 'tasks') && hasViewAccess('Shopkeep', 'tasks') && !hasViewAccess('Media', 'tasks'), 'tasks view rows for Staff and Shopkeep, not Media');
$ok(hasViewAccess('moderator', 'tasks_manage') && !hasViewAccess('Staff', 'tasks_manage'), 'only managers manage chores');
$pdo->exec("UPDATE view_permissions SET can_access = 0 WHERE role = 'Shopkeep' AND view_name = 'tasks'");
(require $root . '/admin/migrations/0029_tasks.php')($pdo);
$ok(!hasViewAccess('Shopkeep', 'tasks'), 're-running keeps a permission the owner changed');
$pdo->exec("UPDATE view_permissions SET can_access = 1 WHERE role = 'Shopkeep' AND view_name = 'tasks'");
$pdo->exec("UPDATE task_recurrences SET start_date = '2026-11-01'");

// -- Generation: Thu Nov 12, 2026 → Wed Nov 18 (Mon Nov 16 closed) --
$GLOBALS['bcrf_tasks_today'] = '2026-11-12'; $GLOBALS['bcrf_tasks_time'] = '06:00:00';
$made = bcrf_tasks_generate($pdo);
$ok($made === 7 + 6 + 6 + 7, "7 days of feeding, 6 open days of opening/closing ($made made)");
$ok(bcrf_tasks_generate($pdo) === 0 && bcrf_tasks_generate($pdo, null, true) === 0, 'generating again makes nothing new');
$ok((int)$one("SELECT COUNT(*) FROM tasks t JOIN task_recurrences r ON r.id = t.recurrence_id WHERE r.seed_key = 'opening' AND t.due_date = '2026-11-16'") === 0, 'no opening checklist on closed Monday');
$ok($one("SELECT t.due_time FROM tasks t JOIN task_recurrences r ON r.id = t.recurrence_id WHERE r.seed_key = 'opening' AND t.due_date = '2026-11-14'") === '09:00:00', 'Saturday opening due at 9:00 (first block)');
$ok($one("SELECT t.due_time FROM tasks t JOIN task_recurrences r ON r.id = t.recurrence_id WHERE r.seed_key = 'closing' AND t.due_date = '2026-11-14'") === '17:00:00', 'Saturday closing due at 5:00 (last block)');
$ok($one("SELECT t.due_time FROM tasks t JOIN task_recurrences r ON r.id = t.recurrence_id WHERE r.seed_key = 'morning_feed' AND t.due_date = '2026-11-12'") === '07:30:00', 'morning feed at 7:30');
$ok((string)$one("SELECT MIN(due_date) FROM tasks") === '2026-11-12', 'past days are never filled in');
$pdo->exec("INSERT INTO booking_blackouts (d_date, reason) VALUES ('2026-11-26', 'Thanksgiving')");
$GLOBALS['bcrf_tasks_today'] = '2026-11-20';
$made = bcrf_tasks_generate($pdo);
$ok((int)$one("SELECT COUNT(*) FROM tasks t JOIN task_recurrences r ON r.id = t.recurrence_id WHERE r.seed_key = 'opening' AND t.due_date = '2026-11-26'") === 0
    && (int)$one("SELECT COUNT(*) FROM tasks t JOIN task_recurrences r ON r.id = t.recurrence_id WHERE r.seed_key = 'morning_feed' AND t.due_date = '2026-11-26'") === 1, 'blackout day: no opening, animals still fed');
$ok((string)$one("SELECT MAX(due_date) FROM tasks") === '2026-11-26' && (int)$one("SELECT COUNT(*) FROM tasks t JOIN task_recurrences r ON r.id = t.recurrence_id WHERE r.seed_key = 'morning_feed'") === 14, 'a missed night leaves a gap, never back-fills (Nov 19 skipped)');

// -- Falls-on rules --
$r = ['start_date' => '2026-11-01', 'end_date' => null, 'freq' => 'every_n', 'interval_n' => 3, 'weekdays' => null, 'month_day' => null, 'open_days_only' => 0, 'season_start' => null, 'season_end' => null];
$ok(bcrf_rec_falls_on($pdo, $r, '2026-11-04') && !bcrf_rec_falls_on($pdo, $r, '2026-11-05') && bcrf_rec_falls_on($pdo, $r, '2026-11-07'), 'every 3 days from the start');
$ok(!bcrf_rec_falls_on($pdo, $r, '2026-10-29'), 'nothing before the start date');
$w = ['freq' => 'weekly', 'interval_n' => 1, 'weekdays' => '2,5'] + $r;
$ok(bcrf_rec_falls_on($pdo, $w, '2026-11-17') && bcrf_rec_falls_on($pdo, $w, '2026-11-20') && !bcrf_rec_falls_on($pdo, $w, '2026-11-18'), 'Tuesdays and Fridays');
$w2 = ['interval_n' => 2] + $w;
$ok(bcrf_rec_falls_on($pdo, $w2, '2026-11-03') && !bcrf_rec_falls_on($pdo, $w2, '2026-11-10') && bcrf_rec_falls_on($pdo, $w2, '2026-11-17'), 'every other Tuesday');
$m = ['freq' => 'monthly', 'month_day' => 31] + $r;
$ok(bcrf_rec_falls_on($pdo, $m, '2026-11-30') && !bcrf_rec_falls_on($pdo, $m, '2026-11-29') && bcrf_rec_falls_on($pdo, $m, '2026-12-31'), 'monthly on the 31st = last day of a short month');
$m2 = ['month_day' => 29] + $m;
$ok(bcrf_rec_falls_on($pdo, $m2, '2027-02-28') && bcrf_rec_falls_on($pdo, $m2, '2028-02-29'), 'monthly 29th in February');
$s = ['freq' => 'daily', 'season_start' => '11-15', 'season_end' => '02-28'] + $r;
$ok(bcrf_rec_falls_on($pdo, $s, '2026-12-25') && bcrf_rec_falls_on($pdo, $s, '2027-01-10') && !bcrf_rec_falls_on($pdo, $s, '2026-11-10') && !bcrf_rec_falls_on($pdo, $s, '2027-03-01'), 'winter season wraps the new year');
$e = ['freq' => 'daily', 'end_date' => '2026-11-10'] + $r;
$ok(bcrf_rec_falls_on($pdo, $e, '2026-11-10') && !bcrf_rec_falls_on($pdo, $e, '2026-11-11'), 'stops after the end date');
$ok(bcrf_rec_describe(['freq' => 'weekly', 'interval_n' => 1, 'weekdays' => '5,2', 'due_anchor' => 'time', 'due_time' => '07:30:00', 'open_days_only' => 1]) === 'Every Tue, Fri at 7:30 AM, open days only', 'describe weekly');
$ok(bcrf_rec_describe(['freq' => 'monthly', 'month_day' => 22, 'due_anchor' => 'closing']) === 'Monthly on the 22nd, at closing', 'describe monthly');
$ok(bcrf_rec_describe(['freq' => 'daily', 'due_anchor' => 'time', 'due_time' => null, 'season_start' => '04-01', 'season_end' => '10-31']) === 'Every day, Apr 1 – Oct 31', 'describe season');

// -- Who sees what --
$GLOBALS['bcrf_tasks_today'] = '2026-11-20'; $GLOBALS['bcrf_tasks_time'] = '08:00:00';
$as('Staff', 3);
$mine = bcrf_tasks_mine($pdo);
$ok(count($mine['today']) === 4, 'Sam sees all 4 of today\'s chores (anyone on the farm)');
$ok(count($mine['overdue']) === 7 + 6 + 6 + 7, 'unfinished days from Nov 12-18 show as overdue');
$late = array_values(array_filter($mine['today'], fn($t) => $t['overdue']));
$ok(count($late) === 1 && $late[0]['title'] === 'Morning feed & water', 'at 8:00, only the 7:30 morning feed is late today');
$cnt = bcrf_tasks_counts($pdo);
$ok($cnt['today'] === 4 && $cnt['overdue'] === count($mine['overdue']), 'dashboard counts match My Tasks');
$ok(!bcrf_tasks_is_manager(), 'farm hand is not a manager');

// One-off tasks with assignees
$as('moderator', 2);
$ok(bcrf_tasks_is_manager(), 'manager role manages');
$tA = bcrf_task_create($pdo, ['title' => 'Trim goat hooves', 'checklist' => "- Clippers\n- Hoof block\n\n[ ] Treats", 'priority' => 'high', 'due_date' => '2026-11-21', 'who' => 'user:4']);
$tB = bcrf_task_create($pdo, ['title' => 'Restock jam shelf', 'due_date' => '2026-11-20', 'due_time' => '2:30 PM', 'who' => 'role:Shopkeep']);
$tC = bcrf_task_create($pdo, ['title' => 'Fix the duck pond hose', 'who' => 'anyone']);
$ok(bcrf_task_get($pdo, $tA)['checklist'][2]['t'] === 'Treats' && count(bcrf_task_get($pdo, $tA)['checklist']) === 3, 'checklist lines cleaned (bullets and boxes stripped, blanks dropped)');
$ok(bcrf_task_get($pdo, $tB)['due_time'] === '14:30:00', '2:30 PM stored as 14:30');
$throws(fn() => bcrf_task_create($pdo, ['title' => '  ']), 'a task needs a name', 'name');
$throws(fn() => bcrf_task_create($pdo, ['title' => 'x', 'due_time' => '09:00']), 'time without a date', 'needs a due date');
$throws(fn() => bcrf_task_create($pdo, ['title' => 'x', 'due_date' => '2026-02-30']), 'impossible date', 'not a real day');
$throws(fn() => bcrf_task_create($pdo, ['title' => 'x', 'who' => 'user:999']), 'unknown person', 'staff list');
$throws(fn() => bcrf_task_create($pdo, ['title' => 'x', 'who' => 'role:Media']), 'unknown group', 'group');
$as('Staff', 3);
$ids = fn() => array_map(fn($t) => (int)$t['id'], array_merge(...array_values(bcrf_tasks_mine($pdo))));
$ok(!in_array($tA, $ids(), true) && !in_array($tB, $ids(), true) && in_array($tC, $ids(), true), "Sam doesn't see Ann's or the shop's task, does see anyone's");
$throws(fn() => bcrf_task_set($pdo, $tA, 'done'), "Sam can't finish Ann's task", 'not yours');
$as('Staff', 4);
$ok(in_array($tA, $ids(), true), 'Ann sees her task');
$as('Shopkeep', 5);
$ok(in_array($tB, $ids(), true) && !in_array($tA, $ids(), true), 'shopkeeper sees the shop task');
$ok(count(bcrf_tasks_mine($pdo)['someday']) === 1, 'no-date task is under "no date"');

// -- Checklist and statuses --
$as('Staff', 4);
$t = bcrf_task_check($pdo, $tA, 0, true);
$ok($t['status'] === 'in_progress' && $t['checked'] === 1 && (int)$t['checklist'][0]['by'] === 4, 'ticking an item starts the task and records who');
$throws(fn() => bcrf_task_check($pdo, $tA, 9, true), 'bad checklist index', 'reload');
$throws(fn() => bcrf_task_set($pdo, $tA, 'done'), 'done with items left needs a confirm', '2 checklist items');
$t = bcrf_task_set($pdo, $tA, 'done', 'Two goats left for Tuesday', true);
$ok($t['status'] === 'done' && (int)$t['completed_by'] === 4 && $t['completion_note'] === 'Two goats left for Tuesday', 'finish anyway with a note');
$throws(fn() => bcrf_task_check($pdo, $tA, 1, true), 'closed task checklist is locked', 'Reopen');
$throws(fn() => bcrf_task_set($pdo, $tA, 'cancel'), "a farm hand can't cancel a manager's task", 'already closed');
$t = bcrf_task_set($pdo, $tA, 'reopen');
$ok($t['status'] === 'in_progress' && $t['completed_at'] === null, 'reopen goes back to started (items ticked)');
$as('Staff', 3);
$throws(fn() => bcrf_task_set($pdo, $tC, 'skip', 'rain'), 'only chore days can be skipped', 'chore day');
$throws(fn() => bcrf_task_set($pdo, $tC, 'cancel'), "farm hand can't cancel someone else's task", 'manager');
$own = bcrf_task_create($pdo, ['title' => 'Call feed store', 'due_date' => '2026-11-20']);
$ok(bcrf_task_set($pdo, $own, 'cancel', 'Got it at TSC')['status'] === 'cancelled', 'you can cancel a task you made');
$throws(fn() => bcrf_task_update($pdo, $tC, ['title' => 'x']), "can't edit someone else's task", 'manager');
$feed = (int)$one("SELECT t.id FROM tasks t JOIN task_recurrences r ON r.id = t.recurrence_id WHERE r.seed_key = 'morning_feed' AND t.due_date = '2026-11-20'");
$throws(fn() => bcrf_task_set($pdo, $feed, 'skip'), 'skip needs a reason', 'why');
$sk = (int)$one("SELECT t.id FROM tasks t JOIN task_recurrences r ON r.id = t.recurrence_id WHERE r.seed_key = 'closing' AND t.due_date = '2026-11-21'");
$ok(bcrf_task_set($pdo, $sk, 'skip', 'Closed early for the storm')['status'] === 'skipped', 'skip one chore day');
foreach (range(0, 8) as $i) bcrf_task_check($pdo, $feed, $i, true);
$ok(bcrf_task_set($pdo, $feed, 'done')['status'] === 'done', 'all ticked: done without a confirm');
$pdo->exec("UPDATE tasks SET completed_at = '2026-11-20 15:00:00' WHERE id IN ($feed, $sk)");   // the test clock is pinned to Nov 20
$ok(count(bcrf_tasks_mine($pdo)['done']) === 2, 'done today list shows both (done and skipped)');
$ok((int)$one("SELECT active FROM task_recurrences WHERE seed_key = 'morning_feed'") === 1, "finishing a day doesn't touch the chore");

// -- Editing chores --
$as('Staff', 3);
$throws(fn() => bcrf_rec_save($pdo, ['title' => 'x'], 1), "farm hand can't edit chores", 'Owner or Manager');
$as('administrator', 1);
$rid = (int)$one("SELECT id FROM task_recurrences WHERE seed_key = 'evening_feed'");
$touched = (int)$one("SELECT id FROM tasks WHERE recurrence_id = ? AND due_date = '2026-11-22'", [$rid]);
bcrf_task_check($pdo, $touched, 0, true);
$rec = bcrf_rec_get($pdo, $rid);
bcrf_rec_save($pdo, ['title' => 'Evening feed & lock-up', 'checklist' => implode("\n", array_merge($rec['lines'], ['Check the heat lamp'])), 'priority' => 'high', 'who' => 'role:Staff',
                     'freq' => 'daily', 'due_anchor' => 'time', 'due_time' => '17:00', 'start_date' => '2026-11-01'], $rid);
$ok((int)$one("SELECT COUNT(*) FROM tasks WHERE recurrence_id = ? AND due_date >= '2026-11-20'", [$rid]) === 7, 'edit rebuilds the 7 days ahead');
$ok($one("SELECT due_time FROM tasks WHERE recurrence_id = ? AND due_date = '2026-11-23'", [$rid]) === '17:00:00'
    && $one("SELECT assignee_role FROM tasks WHERE recurrence_id = ? AND due_date = '2026-11-23'", [$rid]) === 'Staff', 'untouched days take the new time and group');
$ok((int)$one("SELECT id FROM tasks WHERE recurrence_id = ? AND due_date = '2026-11-22'", [$rid]) === $touched
    && $one("SELECT due_time FROM tasks WHERE id = ?", [$touched]) === '17:30:00', 'a day someone started is left alone');
$ok(count(json_decode($one("SELECT checklist_json FROM tasks WHERE recurrence_id = ? AND due_date = '2026-11-24'", [$rid]), true)) === 8, 'new checklist line on rebuilt days');
$ok((int)$one("SELECT COUNT(*) FROM tasks WHERE recurrence_id = ? AND due_date < '2026-11-20' AND status = 'open'", [$rid]) > 0, 'old overdue days are kept (history)');
bcrf_rec_set_active($pdo, $rid, false);
$ok((int)$one("SELECT COUNT(*) FROM tasks WHERE recurrence_id = ? AND due_date >= '2026-11-20'", [$rid]) === 1, 'pause removes untouched days, keeps the started one');
$ok(bcrf_tasks_generate($pdo, null, true) === 0, 'a paused chore makes nothing');
bcrf_rec_set_active($pdo, $rid, true);
$ok((int)$one("SELECT COUNT(*) FROM tasks WHERE recurrence_id = ? AND due_date >= '2026-11-20'", [$rid]) === 7, 'restart fills the week again');
$throws(fn() => bcrf_rec_save($pdo, ['title' => 'Hoof check', 'freq' => 'weekly', 'weekdays' => []]), 'weekly needs days', 'weekday');
$throws(fn() => bcrf_rec_save($pdo, ['title' => 'Hoof check', 'freq' => 'every_n', 'interval_n' => 1]), 'every N needs 2+', 'how many days');
$throws(fn() => bcrf_rec_save($pdo, ['title' => 'Hoof check', 'freq' => 'daily', 'season_start' => '04-01']), 'season needs both ends', 'both ends');
$throws(fn() => bcrf_rec_save($pdo, ['title' => 'Hoof check', 'freq' => 'daily', 'start_date' => '2026-12-01', 'end_date' => '2026-11-01']), 'end before start', 'before the start');
$new = bcrf_rec_save($pdo, ['title' => 'Hoof check', 'freq' => 'weekly', 'weekdays' => ['2'], 'interval_n' => 1, 'horizon_days' => 14, 'checklist' => "Goats\nSheep\nDonkey"]);
$ok((int)$one("SELECT COUNT(*) FROM tasks WHERE recurrence_id = ?", [$new]) === 2, 'new weekly chore, 14 days ahead: 2 Tuesdays');
$ok(str_contains(bcrf_rec_describe(bcrf_rec_get($pdo, $new)), 'Every Tue'), 'chore list shows the schedule');
$ok(count(bcrf_rec_list($pdo)) === 5, 'chore list');

// -- Saved checklists --
$tpl = bcrf_tpl_save($pdo, ['title' => 'Vet visit prep', 'checklist' => "Halters ready\nRecords printed", 'who' => 'anyone']);
$ok(count(bcrf_tpl_get($pdo, $tpl)['lines']) === 2, 'saved checklist');
$throws(fn() => bcrf_tpl_save($pdo, ['title' => 'Empty']), 'a saved checklist needs lines', 'at least one');
$fromTpl = bcrf_task_create($pdo, ['title' => 'Vet visit prep', 'checklist' => bcrf_tpl_get($pdo, $tpl)['lines'], 'template_id' => $tpl]);
$ok((int)$one("SELECT template_id FROM tasks WHERE id = ?", [$fromTpl]) === $tpl, 'task made from a saved checklist');

// -- Manager list and edits keep ticks --
$all = bcrf_tasks_list($pdo, ['status' => 'open', 'who' => 'role:Shopkeep']);
$ok(count($all) === 1 && (int)$all[0]['id'] === $tB, 'All tasks filtered by group');
$ok(count(bcrf_tasks_list($pdo, ['status' => 'closed', 'q' => 'feed'])) >= 2, 'closed tasks search');
bcrf_task_update($pdo, $tA, ['title' => 'Trim goat hooves', 'checklist' => "Clippers\nHoof block\nTreats\nBandage", 'who' => 'user:4', 'due_date' => '2026-11-22']);
$t = bcrf_task_get($pdo, $tA);
$ok($t['checklist'][0]['done'] === true && count($t['checklist']) === 4 && $t['due_date'] === '2026-11-22', 'edit keeps ticked lines and adds new ones');
$ok(bcrf_tasks_who($t) === 'Ann Hand' && bcrf_tasks_who(['assignee_role' => 'Staff']) === 'Farm staff' && bcrf_tasks_who([]) === 'Anyone on the farm', 'who labels');
$ok(count(bcrf_tasks_people($pdo)) === 5, 'staff picker lists active staff');

echo ($fails ? "$fails of $n checks FAILED\n" : "All $n checks passed\n");
exit($fails ? 1 : 0);
