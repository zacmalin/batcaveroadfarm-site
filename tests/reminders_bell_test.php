<?php
// tests/reminders_bell_test.php — deposit/balance/pass reminder emails and the admin bell (Nov 13, 2026; migration 0063)
// against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_rb_test DB_USER=... DB_PASS=... php tests/reminders_bell_test.php
// Refuses to run unless DB_NAME ends in "_test". Exit 0 = all passed. Never touches a real database.
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$dsn = 'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4';
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', $opts);
$root = dirname(__DIR__);
ini_set('log_errors', '0');

$GLOBALS['mails'] = [];
function bcf_send_mail(string $to, string $subject, string $html, array $opts = []): bool { $GLOBALS['mails'][] = [$to, $subject, $html]; return true; }
$_SESSION = ['user_id' => 1, 'user_role' => 'administrator'];

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }
(require $root . '/admin/migrations/0063_reminders_bell.php')($pdo);   // safe to repeat

require $root . '/includes/reminders.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$sent = [];
$send = function ($to, $subject, $html) use (&$sent) { $sent[] = [$to, $subject, $html]; return true; };

$ok(bcrf_reminders_ready($pdo) && bcrf_notifications_ready($pdo), 'migration 0063 ran (twice)');
$ok(BCRF_REMINDER_EMAILS_LIVE === false, 'reminder emails are held until the wording is OK\'d');

// Fixed "now": Wed Mar 10, 2027, 10:00 AM farm time
$tz = new DateTimeZone('America/New_York');
$now = new DateTimeImmutable('2027-03-10 10:00:00', $tz);
$d = fn(int $days) => $now->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');

$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role, account_status) VALUES
  (1, 'Zac', 'Owner', 'o@x.test', 'administrator', 'active'), (2, 'Mia', 'Manager', 'm@x.test', 'moderator', 'active'),
  (3, 'Sam', 'Hand', 's@x.test', 'Staff', 'active'), (4, 'Kim', 'Shop', 'k@x.test', 'Shopkeep', 'active')");
$pdo->exec("INSERT INTO booking_types (id, type_key, label, pricing_model, unit_price, requires_payment, is_active, sort_order) VALUES
            (2, 'group_visit', 'Farm Visit (groups of 6+)', 'per_person', 6.00, 0, 1, 2), (4, 'school', 'School Field Trip', 'per_person', 5.00, 0, 1, 4)");
$bk = $pdo->prepare("INSERT INTO bookings (id, type_id, customer_name, email, phone, party_size, payment_status, booking_date, start_time, end_time, status, token, created_at)
                     VALUES (?, 4, ?, ?, '', 24, 'waived', ?, '10:00:00', '12:00:00', ?, ?, NOW())");
$quote = function (int $bid, int $total, int $dep, ?string $due, string $when = 'arrival', bool $sent = true, bool $online = false) use ($pdo) {
    $pdo->prepare("INSERT INTO event_quotes (subject_type, subject_id, token, lines_json, total_cents, deposit_cents, deposit_due, balance_when, pay_online, sent_at, sent_count, created_at)
                   VALUES ('booking', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
        ->execute([$bid, bin2hex(random_bytes(16)), json_encode([['label' => 'Field trip', 'qty' => 1, 'unit_cents' => $total]]), $total, $dep, $due, $when, $online ? 1 : 0,
                   $sent ? gmdate('Y-m-d H:i:s') : null, $sent ? 1 : 0]);
    return (int)$pdo->lastInsertId();
};
// 10: deposit due in 2 days (due)            11: deposit due in 10 days (not yet)     12: deposit already paid (no)
// 13: quote never sent (no)                  14: cancelled booking (no)               15: balance before the day, visit in 3 days (due)
// 16: balance before, visit in 12 days (no)  17: balance on arrival (no)
foreach ([[10, 'Old Fort Elementary', 'teacher@example.test', $d(20), 'confirmed'], [11, 'Later School', 'later@example.test', $d(40), 'pending'],
          [12, 'Paid School', 'paid@example.test', $d(20), 'confirmed'], [13, 'Draft School', 'draft@example.test', $d(20), 'pending'],
          [14, 'Gone School', 'gone@example.test', $d(20), 'cancelled'], [15, 'Scout Troop 7', 'scouts@example.test', $d(3), 'confirmed'],
          [16, 'Far Troop', 'far@example.test', $d(12), 'confirmed'], [17, 'Arrival Troop', 'arrive@example.test', $d(3), 'confirmed']] as [$id, $nm, $em, $dt, $stt]) {
    $bk->execute([$id, $nm, $em, $dt, $stt, bin2hex(random_bytes(32))]);
}
$q10 = $quote(10, 12000, 5000, $d(2), 'arrival', true, true);
$quote(11, 12000, 5000, $d(10));
$q12 = $quote(12, 12000, 5000, $d(2));
$pdo->prepare("INSERT INTO event_payments (quote_id, subject_type, subject_id, kind, method, amount_cents, created_at) VALUES (?, 'booking', 12, 'payment', 'check', 5000, UTC_TIMESTAMP())")->execute([$q12]);
$quote(13, 12000, 5000, $d(2), 'arrival', false);
$quote(14, 12000, 5000, $d(2));
$q15 = $quote(15, 9000, 0, null, 'before');
$quote(16, 9000, 0, null, 'before');
$quote(17, 9000, 0, null, 'arrival');

$dep = bcrf_reminders_quotes_due($pdo, 'deposit', $now);
$ok(array_map(fn($x) => $x['subject']['id'], $dep) === [10], 'deposit reminder: only the unpaid, sent, live quote due within 3 days');
$bal = bcrf_reminders_quotes_due($pdo, 'balance', $now);
$ok(array_map(fn($x) => $x['subject']['id'], $bal) === [15], 'balance reminder: only "before the day" with the visit within 5 days');

// Passes: 20 ends in 10 days (due); 21 ends in 30 days; 22 cancelled; 23 unpaid; 24 ended yesterday
$pdo->exec("INSERT INTO pass_types (id, type_key, label, price, max_guests, duration_months, is_active) VALUES (1, 'family', 'Family Annual Pass', 120.00, 6, 12, 1)");
$pp = $pdo->prepare("INSERT INTO passes (id, pass_type_id, holder_name, email, token, valid_from, valid_until, status, payment_status, created_at) VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, NOW())");
foreach ([[20, 'Jane Doe', 'jane@example.test', $d(10), 'active', 'paid'], [21, 'Far Off', 'far@example.test', $d(30), 'active', 'paid'],
          [22, 'Cancel Led', 'c@example.test', $d(10), 'cancelled', 'refunded'], [23, 'Not Paid', 'np@example.test', $d(10), 'pending_payment', 'pending'],
          [24, 'Done Already', 'done@example.test', $d(-1), 'active', 'paid']] as [$id, $nm, $em, $until, $st, $pay]) {
    $pp->execute([$id, $nm, $em, bin2hex(random_bytes(24)), $d(-355), $until, $st, $pay]);
}
$ok(array_map(fn($p) => (int)$p['id'], bcrf_reminders_passes_due($pdo, $now)) === [20], 'pass renewal: only the active, paid pass ending within 14 days');

// Wording
$prev = bcrf_reminder_preview($pdo, $now);
$ok(count($prev) === 3 && array_column($prev, 'kind') === ['deposit', 'balance', 'pass'], 'preview: one of each, nothing sent');
[$dSub, $dHtml] = [$prev[0]['subject'], $prev[0]['html']];
$q10row = bcrf_quote_by_id($pdo, $q10);
$ok(str_contains($dHtml, '$50.00 deposit') && str_contains($dHtml, bcrf_quote_url($q10row)) && str_contains($dHtml, 'pay online') && str_contains($dHtml, 'Zac, BCRF'), 'deposit email: amount, quote link, pay online, sign-off');
$ok(str_contains($prev[1]['html'], '$90.00 balance') && str_contains($prev[1]['html'], 'due before the day') && !str_contains($prev[1]['html'], 'pay online'), 'balance email: amount; no online button when online pay is off');
$ok(str_contains($prev[2]['html'], 'Family Annual Pass') && str_contains($prev[2]['html'], '/passes/') && !preg_match('/\$\d/', $prev[2]['html']), 'pass email: pass name and passes page, never a price');
$ok(str_contains($prev[2]['subject'], 'pass ends'), 'pass email subject');

// Held: nothing sent, nothing claimed
$r = bcrf_reminders_run($pdo, $now, $send);
$ok($r === ['due' => 3, 'sent' => 0, 'held' => 3] && $sent === [], 'not live: 3 due, all held, nothing sent');
$ok((int)$one("SELECT COUNT(*) FROM event_quotes WHERE deposit_reminded_at IS NOT NULL OR balance_reminded_at IS NOT NULL") === 0 && (int)$one("SELECT COUNT(*) FROM passes WHERE renewal_reminded_at IS NOT NULL") === 0, 'not live: nothing claimed, so they go once switched on');

// Live: sent once each
$r = bcrf_reminders_run($pdo, $now, $send, true);
$ok($r['sent'] === 3 && count($sent) === 3, 'live: 3 sent');
$ok(array_column($sent, 0) === ['teacher@example.test', 'scouts@example.test', 'jane@example.test'], 'to the customers\' own emails');
$r = bcrf_reminders_run($pdo, $now, $send, true);
$ok($r['due'] === 0 && count($sent) === 3, 'second run: nothing again');
$ok((int)$one("SELECT COUNT(*) FROM notifications WHERE kind = 'reminder_sent'") === 3, 'each send shows on the managers\' bell');

// -- The bell --
$pdo->exec("INSERT INTO event_inquiries (id, event_type, preferred_date, adult_count, child_count, customer_name, email, status, created_at)
            VALUES (2, 'birthday', '2027-04-03', 6, 8, 'Emily Test', 'emily@example.test', 'new', NOW()),
                   (3, 'birthday', '2027-04-04', 2, 2, 'Old Request', 'old@example.test', 'new', NOW() - INTERVAL 10 DAY)");
$bk->execute([30, 'New School', 'new@example.test', $d(30), 'pending', bin2hex(random_bytes(32))]);
$pdo->prepare("INSERT INTO event_payments (quote_id, subject_type, subject_id, kind, method, amount_cents, stripe_session_id, created_at) VALUES (?, 'booking', 15, 'payment', 'stripe', 4500, 'cs_test_1', UTC_TIMESTAMP())")->execute([$q15]);
$pdo->exec("INSERT INTO tasks (id, title, status, due_date, assignee_user_id, late_notified_at, created_at) VALUES (77, 'Feed the goats', 'open', CURDATE(), 3, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
// an overdue deposit (due 2 days ago, still owed) on a booking
$bk->execute([31, 'Slow Payer', 'slow@example.test', $d(25), 'confirmed', bin2hex(random_bytes(32))]);
$quote(31, 10000, 3000, (new DateTimeImmutable('today', new DateTimeZone('America/New_York')))->modify('-2 days')->format('Y-m-d'));
$added = bcrf_notify_sweep($pdo);
$kinds = $pdo->query("SELECT kind FROM notifications WHERE kind <> 'reminder_sent' ORDER BY kind")->fetchAll(PDO::FETCH_COLUMN);
$ok(array_values(array_unique($kinds)) === ['booking_request', 'deposit_overdue', 'online_payment', 'party_request', 'task_late'], 'sweep: party request, booking requests, online payment, overdue deposit, late task ' . json_encode($kinds));
$ok((int)$one("SELECT COUNT(*) FROM notifications WHERE dedupe_key = 'party_request:3'") === 0, 'a 10-day-old request isn\'t news');
$ok(bcrf_notify_sweep($pdo) === 0, 'second sweep adds nothing');
$ok(str_contains((string)$one("SELECT title FROM notifications WHERE kind = 'online_payment' LIMIT 1"), 'paid $45.00 online'), 'payment notice says who and how much');
$ok($one("SELECT link FROM notifications WHERE dedupe_key = 'party_request:2'") === '/admin/party.php?id=2', 'party notice opens the party');

$mgrAll = count(bcrf_notifications_list($pdo, 2, 'moderator'));
$ok($mgrAll >= 7 && bcrf_notifications_unread($pdo, 2, 'moderator') === $mgrAll, 'manager sees the farm\'s notices, all unread');
$ok(count(bcrf_notifications_list($pdo, 4, 'Shopkeep')) === 0, 'a shopkeeper sees none of the managers\' notices');
$staff = bcrf_notifications_list($pdo, 3, 'Staff');
$ok(count($staff) === 1 && $staff[0]['kind'] === 'task_late', 'Sam sees only his own late task');
$ok(!array_filter(bcrf_notifications_list($pdo, 1, 'administrator'), fn($x) => $x['kind'] === 'task_late'), 'someone else\'s late task isn\'t on the owner\'s bell');
$first = bcrf_notifications_list($pdo, 2, 'moderator')[0];
bcrf_notifications_mark_read($pdo, 2, 'moderator', $first['id']);
$ok(bcrf_notifications_unread($pdo, 2, 'moderator') === $mgrAll - 1, 'opening one marks it read');
$ok(bcrf_notifications_unread($pdo, 1, 'administrator') === $mgrAll, '…for that person only');
$ok(bcrf_notification_get($pdo, 4, 'Shopkeep', $first['id']) === null, 'can\'t open a notice that isn\'t yours');
bcrf_notifications_mark_read($pdo, 2, 'moderator');
$ok(bcrf_notifications_unread($pdo, 2, 'moderator') === 0, 'mark all read');
$ok(bcrf_notify($pdo, ['kind' => 'x', 'title' => 'Dup', 'dedupe' => 'party_request:2']) === 0, 'same thing never twice');
$ok(bcrf_notify($pdo, ['kind' => 'x', 'title' => 'Bad link', 'dedupe' => 'x:1', 'link' => 'https://evil.example/']) > 0
    && $one("SELECT link FROM notifications WHERE dedupe_key = 'x:1'") === null, 'only admin paths are kept as links');
$pdo->exec("UPDATE notifications SET created_at = UTC_TIMESTAMP() - INTERVAL 61 DAY WHERE dedupe_key = 'x:1'");
bcrf_notify_sweep($pdo);
$ok((int)$one("SELECT COUNT(*) FROM notifications WHERE dedupe_key = 'x:1'") === 0, 'notices older than 60 days are tidied away');

echo $fails ? "$fails of $n checks failed\n" : "All $n checks passed\n";
exit($fails ? 1 : 0);
