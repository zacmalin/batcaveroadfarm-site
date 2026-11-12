<?php
// tests/calendar_test.php — Farm OS Phase 3 (Nov 12, 2026): the master calendar and phone calendar feeds (includes/calendar.php,
// migration 0034) against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/calendar_test.php
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
require $root . '/admin/includes/permission_checks.php';
require $root . '/includes/queue.php';
require $root . '/admin/includes/audit.php';
require $root . '/includes/event_quotes.php';
require $root . '/includes/tasks.php';
require $root . '/includes/calendar.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$titles = fn(array $items, string $layer = '') => array_values(array_map(fn($i) => $i['title'], array_filter($items, fn($i) => $layer === '' || $i['layer'] === $layer)));

// -- Migration --
$ok(bcrf_cal_feed_ready($pdo), 'calendar_feeds table exists');
$ok((int)$one("SELECT can_access FROM view_permissions WHERE role = 'Staff' AND view_name = 'calendar'") === 1, 'Staff may open the calendar');
$pdo->exec("UPDATE view_permissions SET can_access = 0 WHERE role = 'Media' AND view_name = 'calendar'");
(require $root . '/admin/migrations/0034_calendar.php')($pdo);
$ok((int)$one("SELECT can_access FROM view_permissions WHERE role = 'Media' AND view_name = 'calendar'") === 0, 'repeat run keeps a permission choice');

// -- Layers by role --
$ok(bcrf_cal_layers_for('administrator') === array_keys(BCRF_CAL_LAYERS), 'administrator sees every layer');
$staff = bcrf_cal_layers_for('Staff');
$ok(in_array('tasks', $staff, true) && in_array('events', $staff, true) && in_array('closures', $staff, true), 'Staff sees tasks, public events, closed days');
$pdo->exec("INSERT INTO view_permissions (role, view_name, can_access) VALUES ('Staff', 'event_inquiries', 0), ('Staff', 'bookings', 1), ('Staff', 'jobs', 0)
            ON DUPLICATE KEY UPDATE can_access = VALUES(can_access)");
$staff = bcrf_cal_layers_for('Staff');
$ok(in_array('bookings', $staff, true) && !in_array('parties', $staff, true) && !in_array('interviews', $staff, true), 'layers follow each page\'s permission');
$ok(bcrf_cal_layers_for('user') === [] && bcrf_cal_layers_for('') === [], 'customers and nobody see nothing');

// -- Fixtures (fixed dates: Dec 2026, a Tuesday-to-Monday week) --
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role, account_status) VALUES
            (1, 'Zac', 'Owner', 'o@x.test', 'administrator', 'active'), (2, 'Sam', 'Staff', 's@x.test', 'Staff', 'active'),
            (3, 'Gone', 'Person', 'g@x.test', 'Staff', 'disabled')");
$pdo->exec("INSERT INTO booking_types (id, type_key, label, pricing_model, unit_price, requires_payment, is_active, sort_order) VALUES
            (1, 'day_admission', 'Petting Zoo Day Admission', 'per_person', 8.00, 1, 1, 1), (2, 'school', 'School Field Trip', 'per_person', 6.00, 0, 1, 2)");
$ins = $pdo->prepare("INSERT INTO bookings (id, type_id, customer_name, email, phone, party_size, payment_status, booking_date, start_time, end_time, status, token, date_flexible)
                      VALUES (?, ?, ?, 'b@x.test', '', ?, 'waived', ?, ?, ?, ?, ?, 0)");
$ins->execute([10, 2, 'Old Fort Elementary', 25, '2026-12-03', '10:00:00', '12:00:00', 'confirmed', bin2hex(random_bytes(32))]);
$ins->execute([11, 2, 'Pending School', 30, '2026-12-04', '09:30:00', '11:00:00', 'pending', bin2hex(random_bytes(32))]);
$ins->execute([12, 1, 'Day Ticket Buyer', 4, '2026-12-03', '10:00:00', '16:00:00', 'confirmed', bin2hex(random_bytes(32))]);
$ins->execute([13, 2, 'Cancelled School', 30, '2026-12-03', '10:00:00', '12:00:00', 'cancelled', bin2hex(random_bytes(32))]);
$pdo->exec("INSERT INTO event_inquiries (id, event_type, preferred_date, start_time, adult_count, child_count, customer_name, email, status) VALUES
            (2, 'birthday', '2026-12-05', '14:00:00', 6, 8, 'Emily Jenkins', 'e@x.test', 'confirmed'),
            (3, 'birthday', '2026-12-06', NULL, 2, 3, 'Maybe Party', 'm@x.test', 'quoted'),
            (4, 'birthday', '2026-12-06', NULL, 2, 3, 'Declined Party', 'd@x.test', 'declined')");
$pdo->exec("INSERT INTO events (title, slug, start_datetime, end_datetime, all_day, recurrence, recur_days, recur_until, status) VALUES
            ('Story time', 'story', '2026-11-01 10:00:00', '2026-11-01 11:00:00', 0, 'weekly', '6', '2026-12-31', 'published'),
            ('Holiday market', 'market', '2026-12-04 09:00:00', '2026-12-06 15:00:00', 0, 'range', NULL, NULL, 'published'),
            ('Farm birthday', 'bday', '2020-12-07 00:00:00', NULL, 1, 'annual', NULL, NULL, 'published'),
            ('Draft thing', 'draft', '2026-12-03 10:00:00', NULL, 0, 'none', NULL, NULL, 'draft')");
$pdo->exec("INSERT INTO tasks (title, status, due_date, due_time, assignee_user_id, assignee_role, created_at, priority) VALUES
            ('Mine', 'open', '2026-12-03', '09:00:00', 2, NULL, NOW(), 'high'), ('Zac only', 'open', '2026-12-03', NULL, 1, NULL, NOW(), 'normal'),
            ('Staff role', 'open', '2026-12-04', NULL, NULL, 'Staff', NOW(), 'normal'), ('Anyone', 'in_progress', '2026-12-04', NULL, NULL, NULL, NOW(), 'normal'),
            ('Done one', 'done', '2026-12-04', NULL, NULL, NULL, NOW(), 'normal'), ('Shopkeep role', 'open', '2026-12-04', NULL, NULL, 'Shopkeep', NOW(), 'normal')");
$pdo->exec("INSERT INTO vendors (id, name, created_at) VALUES (1, 'Feed Store', NOW())");
$pdo->exec("INSERT INTO purchase_orders (vendor_id, status, po_ref, expected_date, created_at) VALUES (1, 'ordered', 'PO-7', '2026-12-08', NOW()), (1, 'received', 'PO-6', '2026-12-08', NOW())");
$pdo->exec("INSERT INTO booking_blackouts (d_date, reason) VALUES ('2026-12-07', 'Staff day')");
$pdo->exec("INSERT INTO weather_days (business_date, message, closed_day, created_at) VALUES ('2026-12-04', 'Ice', 1, UTC_TIMESTAMP())");

$all = array_keys(BCRF_CAL_LAYERS);
$mgr = ['user_id' => 1, 'role' => 'administrator', 'manager' => true];
$items = bcrf_cal_items($pdo, '2026-12-01', '2026-12-08', $all, $mgr);

// Parties and bookings
$p = array_values(array_filter($items, fn($i) => $i['uid'] === 'party-2'))[0] ?? null;
$ok($p && $p['start'] === '2026-12-05 14:00:00' && $p['end'] === '2026-12-05 16:00:00' && !$p['tentative'], 'party at its start time, 2 hours long, confirmed');
$ok($p && str_contains($p['title'], 'Emily Jenkins') && str_contains($p['note'], '14 guests'), 'party title and guest count');
$m = array_values(array_filter($items, fn($i) => $i['uid'] === 'party-3'))[0] ?? null;
$ok($m && $m['all_day'] && $m['tentative'], 'quoted party with no time: all day, not confirmed');
$ok(!in_array('party-4', array_column($items, 'uid'), true), 'declined party left out');
$b = $titles($items, 'bookings');
$ok($b === ['School Field Trip: Old Fort Elementary', 'School Field Trip: Pending School'], 'group bookings only (no day tickets, no cancelled)');

// Public events
$ev = array_filter($items, fn($i) => $i['layer'] === 'events');
$story = array_values(array_filter($ev, fn($i) => $i['title'] === 'Story time'));
$ok(count($story) === 1 && $story[0]['start'] === '2026-12-05 10:00:00' && $story[0]['end'] === '2026-12-05 11:00:00', 'weekly event on its Saturday, with its times');
$market = array_column(array_values(array_filter($ev, fn($i) => $i['title'] === 'Holiday market')), 'date');
$ok($market === ['2026-12-04', '2026-12-05', '2026-12-06'], 'multi-day event on each of its days');
$bd = array_values(array_filter($ev, fn($i) => $i['title'] === 'Farm birthday'));
$ok(count($bd) === 1 && $bd[0]['start'] === '2026-12-07' && $bd[0]['all_day'], 'yearly all-day event on its date');
$ok(!in_array('Draft thing', $titles($items), true), 'draft event left out');
$ok(bcrf_cal_event_days(['start_datetime' => '2026-11-01 10:00:00', 'recurrence' => 'weekly', 'recur_days' => '', 'recur_until' => '2026-11-20'], '2026-11-01', '2026-11-30') === ['2026-11-01', '2026-11-08', '2026-11-15'], 'weekly with no days repeats on its first weekday until its end');

// Tasks: a manager sees all open ones; staff see theirs, their role's and anyone's
$ok($titles($items, 'tasks') === ['Zac only', 'Mine', 'Anyone', 'Shopkeep role', 'Staff role'], 'manager sees every open task, untimed first');
$mine = bcrf_cal_items($pdo, '2026-12-01', '2026-12-08', ['tasks'], ['user_id' => 2, 'role' => 'Staff', 'manager' => false]);
$ok($titles($mine) === ['Mine', 'Anyone', 'Staff role'], 'staff see their own, their role\'s and anyone\'s tasks');
$t = $mine[0];
$ok($t['start'] === '2026-12-03 09:00:00' && str_contains($t['note'], 'Sam') && str_contains($t['note'], 'high priority'), 'task time, who, priority');

// Deliveries, interviews, closures
$ok($titles($items, 'orders') === ['Delivery: Feed Store'], 'open order shows as a delivery; received one doesn\'t');
$ok($titles($items, 'closures') === ['Called off for weather', 'Closed to bookings: Staff day'], 'weather day and blackout day');
$pdo->exec("INSERT INTO job_categories (id, name, slug) VALUES (1, 'Farm', 'farm')");
$pdo->exec("INSERT INTO jobs (id, category_id, title, slug) VALUES (1, 1, 'Farm hand', 'farm-hand')");
try {
    $pdo->exec("INSERT INTO job_cycles (id, job_id, opened_at) VALUES (1, 1, NOW())");
    $age = (string)$one("SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(column_type, \"'\", 2), \"'\", -1) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'applications' AND column_name = 'age_bracket'");
    $pdo->prepare("INSERT INTO applications (job_cycle_id, job_id, first_name, last_name, email, age_bracket, submitted_at, status, interview_at, interview_location)
                   VALUES (1, 1, 'Ann', 'Applicant', 'a@x.test', ?, NOW(), 'interview', '2026-12-02 15:00:00', 'Barn')")->execute([$age]);
    $iv = bcrf_cal_items($pdo, '2026-12-01', '2026-12-08', ['interviews'], $mgr);
    $ok(count($iv) === 1 && $iv[0]['title'] === 'Interview: Ann Applicant' && $iv[0]['end'] === '2026-12-02 15:30:00' && str_contains($iv[0]['note'], 'Barn'), 'interview with job and place');
} catch (Throwable $e) { $ok(false, 'interview fixture: ' . $e->getMessage()); }

// Only asked-for layers, sorted by day
$only = bcrf_cal_items($pdo, '2026-12-01', '2026-12-08', ['parties'], $mgr);
$ok(array_unique(array_column($only, 'layer')) === ['parties'], 'only the asked-for layers');
$dates = array_column($items, 'date'); $sorted = $dates; sort($sorted);
$ok($dates === $sorted, 'items in date order');
$ok(bcrf_cal_items($pdo, '2026-12-08', '2026-12-01', $all, $mgr) === [] && bcrf_cal_items($pdo, 'nope', '2026-12-01', $all, $mgr) === [], 'bad ranges give nothing');

// -- Phone feed --
$tok = bcrf_cal_feed_reset($pdo, 2);
$ok(strlen($tok) === 48 && $one("SELECT token_hash FROM calendar_feeds WHERE user_id = 2") === hash('sha256', $tok), 'secret made, only its hash stored');
$ok(str_contains(bcrf_cal_feed_url($tok), 'calendar_feed.php?t=' . $tok), 'feed address holds the secret');
$own = bcrf_cal_feed_owner($pdo, $tok);
$ok($own && (int)$own['user_id'] === 2 && (int)$own['with_events'] === 1, 'secret finds its owner; parties and bookings on by default');
$ok(bcrf_cal_feed_owner($pdo, str_repeat('a', 48)) === null && bcrf_cal_feed_owner($pdo, 'short') === null, 'unknown and junk secrets refused');

$ics = bcrf_cal_feed_ics($pdo, $own, '2026-12-01');
$ok(str_starts_with($ics, "BEGIN:VCALENDAR\r\n") && str_ends_with($ics, "END:VCALENDAR\r\n"), 'ICS wrapper with CRLF');
$ok(str_contains($ics, 'SUMMARY:Mine') && str_contains($ics, 'SUMMARY:Staff role') && !str_contains($ics, 'Zac only') && !str_contains($ics, 'Shopkeep role'), 'feed has my tasks only');
$ok(str_contains($ics, 'Old Fort Elementary') && !str_contains($ics, 'Emily Jenkins'), 'bookings in (Staff may see them), parties out (Staff may not)');
$ok(str_contains($ics, 'SUMMARY:(Not confirmed) School Field Trip: Pending School') && str_contains($ics, 'STATUS:TENTATIVE'), 'pending booking marked not confirmed');
$ok(str_contains($ics, "DTSTART:20261203T150000Z\r\nDTEND:20261203T170000Z"), 'farm 10 AM to noon becomes 15:00-17:00 UTC (winter)');
$ok(str_contains($ics, "DTSTART;VALUE=DATE:20261207\r\nDTEND;VALUE=DATE:20261208"), 'all-day closure is a one-day date event');
$ok(!str_contains($ics, 'Story time') && !str_contains($ics, 'Feed Store'), 'public events and deliveries stay off the phone feed');
$ok(!preg_match('/^[^\r\n]{76,}/m', $ics), 'lines folded to 75 characters');

bcrf_cal_feed_set_events($pdo, 2, false);
$ics2 = bcrf_cal_feed_ics($pdo, bcrf_cal_feed_owner($pdo, $tok), '2026-12-01');
$ok(str_contains($ics2, 'SUMMARY:Mine') && !str_contains($ics2, 'Old Fort Elementary') && str_contains($ics2, 'Staff day'), 'tasks only: bookings gone, closed days kept');

// Escaping
$pdo->exec("UPDATE tasks SET title = 'Feed, water; check \\\\ gate' WHERE title = 'Mine'");
$ics3 = bcrf_cal_feed_ics($pdo, bcrf_cal_feed_owner($pdo, $tok), '2026-12-01');
$ok(str_contains($ics3, 'SUMMARY:Feed\\, water\\; check \\\\ gate'), 'commas, semicolons and backslashes escaped');

// A manager's feed still shows only their own tasks
$tz = bcrf_cal_feed_reset($pdo, 1);
$icsZ = bcrf_cal_feed_ics($pdo, bcrf_cal_feed_owner($pdo, $tz), '2026-12-01');
$ok(str_contains($icsZ, 'Zac only') && !str_contains($icsZ, 'SUMMARY:Feed') && str_contains($icsZ, 'Emily Jenkins'), 'owner feed: own tasks, plus parties');

// Reset kills the old link; turn off; disabled staff
$tok2 = bcrf_cal_feed_reset($pdo, 2);
$ok(bcrf_cal_feed_owner($pdo, $tok) === null && bcrf_cal_feed_owner($pdo, $tok2) !== null, 'reset: old link dead, new one works');
$ok((int)bcrf_cal_feed_get($pdo, 2)['with_events'] === 0, 'reset keeps the tasks-only choice');
bcrf_cal_feed_delete($pdo, 2);
$ok(bcrf_cal_feed_owner($pdo, $tok2) === null && bcrf_cal_feed_get($pdo, 2) === null, 'turned off');
$tg = bcrf_cal_feed_reset($pdo, 3);
$ok(bcrf_cal_feed_owner($pdo, $tg) === null, 'disabled staff member\'s link doesn\'t work');

echo $fails ? "\n$fails of $n checks FAILED\n" : "All $n calendar checks passed\n";
exit($fails ? 1 : 0);
