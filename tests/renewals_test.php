<?php
// tests/renewals_test.php — Renewals and the one calendar (Nov 15, 2026): includes/renewals.php (migration 0069), the
// "Renewals & due dates" calendar layer, planned monthly/quarterly/yearly chores, quarterly and yearly chore repeats, and the
// bell/email/task reminders — against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_all_test DB_USER=... DB_PASS=... php tests/renewals_test.php
// Refuses to run unless DB_NAME ends in "_test". Empties that database and builds it from every migration. Exit 0 = all passed.
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '',
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$GLOBALS['pdo'] = $pdo;
$root = dirname(__DIR__);
ini_set('log_errors', '0');
date_default_timezone_set('America/New_York');
$_SESSION = ['user_id' => 1, 'user_role' => 'administrator'];
$GLOBALS['mails'] = [];
function bcf_send_mail(string $to, string $subject, string $html, array $opts = []): bool { $GLOBALS['mails'][] = [$to, $subject, $html]; return true; }

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }
require $root . '/admin/includes/permission_checks.php';
require $root . '/includes/queue.php';
require $root . '/admin/includes/audit.php';
require $root . '/includes/event_quotes.php';
require $root . '/includes/tasks.php';
require $root . '/includes/calendar.php';
require $root . '/includes/record_tools.php';
require $root . '/includes/notifications.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $needle = '') { try { $f(); return false; } catch (BcrfRenewalError $e) { return $needle === '' || stripos($e->getMessage(), $needle) !== false; } };
$titles = fn(array $items, string $layer = '') => array_values(array_map(fn($i) => $i['title'], array_filter($items, fn($i) => $layer === '' || $i['layer'] === $layer)));

// -- Migration and permission --
$ok(bcrf_renewals_ready($pdo), 'renewals table exists');
$ok((int)$one("SELECT can_access FROM view_permissions WHERE role = 'moderator' AND view_name = 'renewals'") === 1, 'Managers get the Renewals view');
(require $root . '/admin/migrations/0069_renewals.php')($pdo);
$ok((int)$one("SELECT COUNT(*) FROM view_permissions WHERE view_name = 'renewals'") === 1, 'safe to repeat');
$ok(bcrf_renewals_can('administrator') && bcrf_renewals_can('moderator') && !bcrf_renewals_can('Staff') && !bcrf_renewals_can('Shopkeep') && !bcrf_renewals_can('user'), 'Owners and Managers only');
$ok(in_array('renewals', bcrf_cal_layers_for('moderator'), true) && !in_array('renewals', bcrf_cal_layers_for('Staff'), true), 'calendar layer follows the view');
$ok(isset(bcrf_record_kinds()['renewal']) && bcrf_record_can('renewal', 'moderator') && !bcrf_record_can('renewal', 'Staff'), 'renewal is a record kind (notes, tasks, changes)');
$secs = []; foreach ((require $root . '/includes/permission_sections.php') ? bcrf_permission_sections() : [] as $s) foreach ($s['items'] as $it) $secs[] = $it[1];
$ok(in_array('renewals', $secs, true), 'Permissions page lists Renewals');
require_once $root . '/includes/admin_nav.php';
$navTabs = []; foreach (bcrf_admin_nav_sections() as $sec) foreach ($sec['items'] as $it) foreach ($it['tabs'] as $t) $navTabs[] = $t[1];
$ok(in_array('/admin/renewals.php', $navTabs, true), 'Renewals is in the menu');

// -- Fixtures --
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role, account_status) VALUES
            (1, 'Zac', 'Owner', 'zac@x.test', 'administrator', 'active'), (2, 'Casey', 'Owner', 'casey@x.test', 'administrator', 'active'),
            (3, 'Mo', 'Manager', 'mo@x.test', 'moderator', 'active'), (4, 'Sam', 'Staff', 'sam@x.test', 'Staff', 'active')");
$pdo->exec("INSERT INTO vendors (id, name, created_at) VALUES (20, 'Feed Barn', UTC_TIMESTAMP())");

// -- Dates --
$ok(bcrf_renewal_add_months('2027-01-31', 1) === '2027-02-28' && bcrf_renewal_add_months('2028-01-31', 1) === '2028-02-29', 'month end holds');
$ok(bcrf_renewal_next('2027-01-31', 1) === '2027-02-28' && bcrf_renewal_next('2027-01-31', 1, '2027-03-01') === '2027-03-31', 'next keeps the 31st after a short month');
$ok(bcrf_renewal_next('2027-04-29', 0) === null, 'one-time has no next');
$ok(bcrf_renewal_when('2026-12-01', '2026-11-30') === 'Tomorrow' && bcrf_renewal_when('2026-11-27', '2026-11-30') === '3 days late' && bcrf_renewal_when('2026-11-30', '2026-11-30') === 'Today', 'when, in words');

// -- Validation --
$base = ['title' => 'Domain: farm website', 'category' => 'website', 'renews_on' => '2027-04-29', 'repeat_months' => '12', 'remind_days' => '60', 'who' => 'role:administrator'];
$ok($throws(fn() => bcrf_renewal_clean($pdo, ['title' => ''] + $base), 'name'), 'needs a name');
$ok($throws(fn() => bcrf_renewal_clean($pdo, ['renews_on' => '2027-02-30'] + $base), 'date'), 'needs a real date');
$ok($throws(fn() => bcrf_renewal_clean($pdo, ['repeat_months' => '7'] + $base), 'how often'), 'repeat from the list');
$ok($throws(fn() => bcrf_renewal_clean($pdo, ['remind_days' => '400'] + $base), 'remind'), 'remind 0-365');
$ok($throws(fn() => bcrf_renewal_clean($pdo, ['cost' => 'lots'] + $base), 'cost'), 'cost looks like money');
$ok($throws(fn() => bcrf_renewal_clean($pdo, ['who' => 'user:4'] + $base), 'owner or manager'), 'staff can\'t be the handler');
$c = bcrf_renewal_clean($pdo, ['cost' => '$1,234.5', 'link_url' => 'registrar.example.com/renew', 'who' => 'user:3', 'vendor_id' => '20', 'category' => 'nope'] + $base);
$ok($c['cost_cents'] === 123450 && $c['link_url'] === 'https://registrar.example.com/renew' && $c['assignee_user_id'] === 3 && $c['assignee_role'] === null
    && $c['vendor_id'] === 20 && $c['category'] === 'other', 'cleaned: cents, https link, person, vendor, unknown kind → other');
$ok(bcrf_renewal_clean($pdo, ['vendor_id' => '999'] + $base)['vendor_id'] === null, 'unknown vendor dropped');

// -- Add, change, delete, restore --
$dom = bcrf_renewal_save($pdo, null, $base + ['cost' => '22.99', 'provider' => 'Bluehost'], 1);
$r = bcrf_renewal_get($pdo, $dom);
$ok($r && $r['title'] === 'Domain: farm website' && (int)$r['cost_cents'] === 2299 && $r['assignee_role'] === 'administrator' && (int)$r['created_by'] === 1, 'added');
$ok((int)$one("SELECT COUNT(*) FROM audit_events WHERE object_type = 'renewals' AND object_id = ? AND action = 'create'", [(string)$dom]) === 1, 'add is audited');
bcrf_renewal_save($pdo, $dom, ['notes' => 'Card on file: business Visa'] + $base + ['cost' => '24.99', 'provider' => 'Bluehost'], 1);
$ok((int)$one("SELECT cost_cents FROM renewals WHERE id = ?", [$dom]) === 2499 && count(bcrf_record_changes($pdo, 'renewal', $dom)) === 2, 'changed; Changes panel has both');
$ins = bcrf_renewal_save($pdo, null, ['title' => 'Liability insurance', 'category' => 'insurance', 'renews_on' => '2026-12-10', 'repeat_months' => '12', 'remind_days' => '30', 'who' => 'role:moderator'], 1);
$once = bcrf_renewal_save($pdo, null, ['title' => 'Kitchen inspection', 'category' => 'license', 'renews_on' => '2026-12-05', 'repeat_months' => '0', 'remind_days' => '7', 'who' => 'user:1'], 1);
$tok = bcrf_renewal_save($pdo, null, ['title' => 'Facebook page token', 'category' => 'account', 'renews_on' => '2027-01-24', 'repeat_months' => '2', 'remind_days' => '14', 'who' => 'role:administrator', 'auto_renews' => '1'], 1);
$ok(array_column(bcrf_renewal_list($pdo), 'title') === ['Kitchen inspection', 'Liability insurance', 'Facebook page token', 'Domain: farm website'], 'list: soonest first');
$ok(array_column(bcrf_renewal_list($pdo, 'upcoming', 'visa'), 'id') === [$dom], 'search finds notes');
bcrf_renewal_delete($pdo, $tok, true, 1);
$ok(!in_array($tok, array_column(bcrf_renewal_list($pdo), 'id')) && array_column(bcrf_renewal_list($pdo, 'deleted'), 'id') === [$tok] && $one("SELECT COUNT(*) FROM renewals WHERE id = ?", [$tok]) == 1, 'delete hides, never erases');
$ok($throws(fn() => bcrf_renewal_save($pdo, $tok, $base, 1), 'deleted'), 'a deleted one can\'t be edited');
bcrf_renewal_delete($pdo, $tok, false, 1);
$ok(in_array($tok, array_column(bcrf_renewal_list($pdo), 'id')) && (int)$one("SELECT COUNT(*) FROM audit_events WHERE object_type = 'renewals' AND object_id = ? AND action = 'delete'", [(string)$tok]) === 1, 'restored; delete audited');

// -- Calendar: renewals layer and repeats --
$occ = bcrf_renewals_occurrences($pdo, '2027-01-01', '2027-06-30');
$ok(array_map(fn($o) => $o[1], array_values(array_filter($occ, fn($o) => (int)$o[0]['id'] === $tok))) === ['2027-01-24', '2027-03-24', '2027-05-24'], 'every 2 months repeats on the calendar');
$ok(count(array_filter($occ, fn($o) => (int)$o[0]['id'] === $dom)) === 1, 'yearly shows once in six months');
$viewer = ['user_id' => 1, 'role' => 'administrator', 'manager' => true];
$items = bcrf_cal_items($pdo, '2026-12-01', '2026-12-31', ['renewals'], $viewer);
$ok($titles($items, 'renewals') === ['Renew: Kitchen inspection', 'Renew: Liability insurance'], 'renewals on the calendar: ' . json_encode($titles($items)));
$ok($items[0]['url'] === '/admin/renewals.php?id=' . $once, 'opens the renewal');
$ok($titles(bcrf_cal_items($pdo, '2027-01-24', '2027-01-24', ['renewals'], $viewer)) === ['Renews: Facebook page token'], 'auto-renewing reads "Renews:"');

// Papers, vaccinations, passes on the same layer, each behind its own permission
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$pdo->exec("INSERT INTO breeds (breed_name, slug) VALUES ('Nigerian Dwarf', 'nd')");
$pdo->exec("INSERT INTO animals (id, name, breed_id, classification_id, date_of_birth, is_active) VALUES (30, 'Biscuit', 1, 1, '2023-01-01', 1)");
$pdo->exec("INSERT INTO purchase_docs (id, doc_type, link_type, link_id, expires_on, created_at) VALUES
            (50, 'insurance', 'vendor', 20, '2026-12-15', UTC_TIMESTAMP()), (51, 'vet', 'animal', 30, '2026-12-16', UTC_TIMESTAMP()),
            (52, 'w9', 'vendor', 20, '2026-12-17', UTC_TIMESTAMP())");
$pdo->exec("UPDATE purchase_docs SET deleted_at = UTC_TIMESTAMP() WHERE id = 52");
$pdo->exec("INSERT INTO animal_vaccinations (animal_id, vaccine, given_on, next_due, created_at) VALUES (30, 'CDT', '2025-12-18', '2026-12-18', UTC_TIMESTAMP())");
$pdo->exec("INSERT INTO pass_types (id, type_key, label) VALUES (1, 'family', 'Family')");
$pdo->exec("INSERT INTO passes (pass_type_id, holder_name, email, token, valid_until, status) VALUES
            (1, 'Ann Lee', 'a@x.test', REPEAT('a', 48), '2026-12-20', 'active'), (1, 'Bo Ray', 'b@x.test', REPEAT('b', 48), '2026-12-21', 'active'),
            (1, 'Cy Day', 'c@x.test', REPEAT('c', 48), '2026-12-21', 'active'), (1, 'Old', 'o@x.test', REPEAT('d', 48), '2026-12-21', 'cancelled')");
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$t = $titles(bcrf_cal_items($pdo, '2026-12-11', '2026-12-31', ['renewals'], $viewer));
$ok($t === ['Runs out: Insurance certificate for Feed Barn', 'Runs out: Vet record for Biscuit', 'Vaccine due: Biscuit (CDT)', 'Annual pass ends: Ann Lee', '2 annual passes end'], 'Owner sees papers, vaccines, passes: ' . json_encode($t));
$pdo->exec("INSERT INTO view_permissions (role, view_name, can_access) VALUES ('moderator', 'animal_health', 0), ('moderator', 'passes', 0)
            ON DUPLICATE KEY UPDATE can_access = VALUES(can_access)");
$pdo->exec("INSERT INTO role_permissions (role, table_name, can_view, can_edit) VALUES ('moderator', 'inventory', 1, 1), ('moderator', 'animals', 0, 0)
            ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_edit = VALUES(can_edit)");
$t = $titles(bcrf_cal_items($pdo, '2026-12-11', '2026-12-31', ['renewals'], ['user_id' => 3, 'role' => 'moderator', 'manager' => true]));
$ok($t === ['Runs out: Insurance certificate for Feed Barn'], 'a Manager without animals or passes sees only vendor papers: ' . json_encode($t));

// -- Reminders: bell, email, task --
$GLOBALS['mails'] = [];
$added = bcrf_renewals_sweep($pdo, '2026-11-20');   // insurance (Dec 10, 30 days) is inside its window; inspection (Dec 5, 7 days) is not
$ok($added === 1 && (int)$one("SELECT COUNT(*) FROM notifications WHERE kind = 'renewal_due'") === 1, 'one bell for the one inside its window');
$ok((string)$one("SELECT role FROM notifications WHERE kind = 'renewal_due'") === 'moderator' && (string)$one("SELECT link FROM notifications WHERE kind = 'renewal_due'") === '/admin/renewals.php?id=' . $ins, 'goes to Managers (who handle it), links to it');
$ok(count($GLOBALS['mails']) === 1 && $GLOBALS['mails'][0][0] === 'mo@x.test' && str_starts_with($GLOBALS['mails'][0][1], 'Coming up: Liability insurance'), 'Managers emailed once: ' . json_encode(array_column($GLOBALS['mails'], 1)));
$task = $pdo->query("SELECT * FROM tasks WHERE related_type = 'renewal'")->fetchAll();
$ok(count($task) === 1 && $task[0]['title'] === 'Renew: Liability insurance' && $task[0]['due_date'] === '2026-12-10' && $task[0]['assignee_role'] === 'moderator' && $task[0]['priority'] === 'high', 'a Renew task due on the date');
$ok((int)$one("SELECT task_id FROM renewals WHERE id = ?", [$ins]) === (int)$task[0]['id'], 'task remembered');
$ok(bcrf_renewals_sweep($pdo, '2026-11-20') === 0 && bcrf_renewals_sweep($pdo, '2026-11-21') === 0 && count($GLOBALS['mails']) === 1
    && (int)$one("SELECT COUNT(*) FROM tasks WHERE related_type = 'renewal'") === 1, 'once only, however often the sweep runs');
// The renewal's task is left off the Tasks layer while the Renewals layer is on.
$both = bcrf_cal_items($pdo, '2026-12-10', '2026-12-10', ['tasks', 'renewals'], $viewer);
$ok($titles($both) === ['Renew: Liability insurance'] && $both[0]['layer'] === 'renewals', 'no double entry: ' . json_encode($titles($both)));
$ok($titles(bcrf_cal_items($pdo, '2026-12-10', '2026-12-10', ['tasks'], $viewer)) === ['Renew: Liability insurance'], 'the task still shows without the Renewals layer');
// Late
$GLOBALS['mails'] = [];
bcrf_renewals_sweep($pdo, '2026-12-12');
$ok((int)$one("SELECT COUNT(*) FROM notifications WHERE dedupe_key = ?", ['renewal_late:' . $ins . ':2026-12-10']) === 1, 'a second bell when it goes late');
$ok(count(array_filter($GLOBALS['mails'], fn($m) => str_starts_with($m[1], 'Late: Liability insurance'))) === 1, 'late email');
$ok(count(array_filter($GLOBALS['mails'], fn($m) => in_array($m[0], ['zac@x.test'], true) && str_contains($m[1], 'Kitchen inspection'))) === 1
    && !in_array('casey@x.test', array_column(array_filter($GLOBALS['mails'], fn($m) => str_contains($m[1], 'Kitchen inspection')), 0), true), 'a person-handled one emails only that person');
$ok((int)$one("SELECT user_id FROM notifications WHERE dedupe_key LIKE ?", ['renewal_late:' . $once . ':%']) === 1, 'and the bell is theirs');

// -- Renewed --
$GLOBALS['bcrf_renewals_today'] = '2026-12-12';
$next = bcrf_renewal_mark_renewed($pdo, $ins, 3);
$ok($next === '2027-12-10' && (string)$one("SELECT last_renewed_on FROM renewals WHERE id = ?", [$ins]) === '2026-12-12', 'renewed late: next year, same day');
$ok((string)$one("SELECT status FROM tasks WHERE id = ?", [$task[0]['id']]) === 'done' && $one("SELECT task_id FROM renewals WHERE id = ?", [$ins]) === null, 'its task is done');
$GLOBALS['bcrf_renewals_today'] = '2027-02-20';
$ok(bcrf_renewal_mark_renewed($pdo, $tok, 1) === '2027-03-24', 'every 2 months, renewed a month late: the next date after today');
$GLOBALS['bcrf_renewals_today'] = '2027-04-01';
$ok(bcrf_renewal_mark_renewed($pdo, $dom, 1) === '2028-04-29', 'renewed early: a year from the due date, not from today');
$ok(bcrf_renewal_mark_renewed($pdo, $once, 1) === null && $one("SELECT finished_at FROM renewals WHERE id = ?", [$once]) !== null
    && array_column(bcrf_renewal_list($pdo, 'finished'), 'id') === [$once], 'one-time: finished');
$ok($throws(fn() => bcrf_renewal_mark_renewed($pdo, $once, 1), 'already'), 'can\'t finish twice');
$ok(bcrf_cal_items($pdo, '2026-12-05', '2026-12-05', ['renewals'], $viewer) === [], 'finished one is off the calendar');
bcrf_renewal_reopen($pdo, $once, 1);
$ok(in_array($once, array_column(bcrf_renewal_list($pdo), 'id')), 'put back on the list');
// Moving the date closes the old date's task
$GLOBALS['bcrf_renewals_today'] = '';
$tid = bcrf_renewal_make_task($pdo, bcrf_renewal_get($pdo, $once));
bcrf_renewal_save($pdo, $once, ['title' => 'Kitchen inspection', 'category' => 'license', 'renews_on' => '2027-06-01', 'repeat_months' => '0', 'remind_days' => '7', 'who' => 'user:1'], 1);
$ok((string)$one("SELECT status FROM tasks WHERE id = ?", [$tid]) === 'cancelled', 'new date: the old task is cancelled');
bcrf_renewal_delete($pdo, $dom, true, 1);
$ok(!in_array($dom, array_map(fn($o) => (int)$o[0]['id'], bcrf_renewals_occurrences($pdo, '2028-01-01', '2028-12-31')), true), 'deleted one is off the calendar');

// -- Chores: every 3 months and once a year --
$q = ['freq' => 'quarterly', 'month_day' => 1, 'start_date' => '2027-02-01', 'end_date' => null, 'interval_n' => 1, 'open_days_only' => 0];
$ok(bcrf_rec_falls_on($pdo, $q, '2027-02-01') && bcrf_rec_falls_on($pdo, $q, '2027-05-01') && bcrf_rec_falls_on($pdo, $q, '2028-02-01')
    && !bcrf_rec_falls_on($pdo, $q, '2027-03-01') && !bcrf_rec_falls_on($pdo, $q, '2027-05-02') && !bcrf_rec_falls_on($pdo, $q, '2026-11-01'), 'every 3 months from the start month');
$y = ['freq' => 'yearly', 'month_day' => 29, 'start_date' => '2027-04-01', 'end_date' => null, 'interval_n' => 1, 'open_days_only' => 0];
$ok(bcrf_rec_falls_on($pdo, $y, '2027-04-29') && bcrf_rec_falls_on($pdo, $y, '2028-04-29') && !bcrf_rec_falls_on($pdo, $y, '2027-05-29'), 'once a year');
$ok(bcrf_rec_describe($q + ['due_anchor' => 'time', 'due_time' => '09:00:00']) === 'Every 3 months on the 1st (Feb, May, Aug, Nov) at 9:00 AM', 'quarterly in words: ' . bcrf_rec_describe($q + ['due_anchor' => 'time', 'due_time' => '09:00:00']));
$ok(bcrf_rec_describe($y + ['due_anchor' => 'time', 'due_time' => null]) === 'Every year on Apr 29', 'yearly in words');
$cid = bcrf_rec_save($pdo, ['title' => 'Accessibility rescan', 'freq' => 'quarterly', 'month_day' => '1', 'start_date' => '2027-02-01', 'due_anchor' => 'time', 'due_time' => '9:00', 'who' => 'role:administrator', 'horizon_days' => '14']);
$ok((string)$one("SELECT freq FROM task_recurrences WHERE id = ?", [$cid]) === 'quarterly', 'quarterly chore saves');
$ok($throws2 = (function () use ($pdo) { try { bcrf_rec_save($pdo, ['title' => 'x', 'freq' => 'yearly', 'month_day' => '', 'who' => 'anyone']); return false; } catch (BcrfTaskError $e) { return true; } })(), 'yearly needs a day');
$GLOBALS['bcrf_tasks_today'] = '2027-01-25';
bcrf_tasks_generate($pdo, $cid, true);
$ok((int)$one("SELECT COUNT(*) FROM tasks WHERE recurrence_id = ?", [$cid]) === 1 && (string)$one("SELECT due_date FROM tasks WHERE recurrence_id = ?", [$cid]) === '2027-02-01', 'its first task is made inside the horizon');
// Planned chores on the calendar, months ahead (the made task shows as a task; later ones as planned)
$GLOBALS['bcrf_tasks_today'] = '';
$cal = bcrf_cal_items($pdo, '2027-01-01', '2027-12-31', ['tasks'], $viewer);
$rescan = array_values(array_filter($cal, fn($i) => $i['title'] === 'Accessibility rescan'));
$ok(array_map(fn($i) => $i['date'], $rescan) === ['2027-02-01', '2027-05-01', '2027-08-01', '2027-11-01'], 'quarterly chore on the calendar all year: ' . json_encode(array_map(fn($i) => $i['date'], $rescan)));
$ok(str_starts_with($rescan[0]['uid'], 'task-') && str_starts_with($rescan[1]['uid'], 'chore-') && str_contains($rescan[1]['note'], 'planned'), 'made task, then planned ones');
$ok(count(array_filter(bcrf_cal_items($pdo, '2027-01-01', '2027-12-31', ['tasks'], ['user_id' => 4, 'role' => 'Staff', 'manager' => false]), fn($i) => $i['title'] === 'Accessibility rescan')) === 0, 'staff don\'t see an Owners\' chore');

// -- Phone feed carries renewals for people who may see them --
$GLOBALS['bcrf_renewals_today'] = '';
$tokO = bcrf_cal_feed_reset($pdo, 1, false);
$ics = bcrf_cal_feed_ics($pdo, bcrf_cal_feed_owner($pdo, $tokO), '2027-03-01');
$ok(str_contains($ics, 'SUMMARY:Renews: Facebook page token') && !str_contains($ics, '(Not confirmed) Renews'), 'Owner\'s feed has renewals');
$tokS = bcrf_cal_feed_reset($pdo, 4, false);
$ok(!str_contains(bcrf_cal_feed_ics($pdo, bcrf_cal_feed_owner($pdo, $tokS), '2027-03-01'), 'Renew'), 'Staff feed doesn\'t');

echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
