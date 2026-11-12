<?php
// tests/party_emails_test.php — Farm OS Phase 3 (Nov 12, 2026): the day-before reminder and the thank-you for parties
// (includes/party_emails.php, migration 0038), against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/party_emails_test.php
// Refuses to run unless DB_NAME ends in "_test". Empties that database and builds it from every migration. Exit 0 = all passed.
// No email is sent: a fake sender records what would go out.
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
require_once $root . '/includes/party_emails.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$tz = new DateTimeZone('America/New_York');
$at = fn(string $s) => new DateTimeImmutable($s, $tz);
$outbox = [];
$send = function ($to, $subject, $inner) use (&$outbox) { $outbox[] = compact('to', 'subject', 'inner'); return true; };

// Saturday 10–4, like the farm
$pdo->exec("DELETE FROM site_hours WHERE scope = 'general'");
$pdo->exec("INSERT INTO site_hours (scope, day_of_week, block_order, open_time, close_time, is_closed) VALUES ('general', 6, 0, '10:00:00', '16:00:00', 0)");
(require $root . '/admin/migrations/0038_party_emails.php')($pdo);
$ok(bcrf_party_emails_ready($pdo), 'migration adds the sent-at columns and is safe to repeat');

$add = function (array $f) use ($pdo): int {
    $f += ['event_type' => 'birthday', 'preferred_date' => '2026-12-05', 'start_time' => null, 'date_flexible' => 0, 'adult_count' => 6, 'child_count' => 8,
           'customer_name' => 'Emily Jenkins', 'email' => 'emily@example.test', 'phone' => null, 'status' => 'confirmed', 'package_id' => null];
    $pdo->prepare("INSERT INTO event_inquiries (event_type, preferred_date, start_time, date_flexible, adult_count, child_count, customer_name, email, phone, status, package_id, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$f['event_type'], $f['preferred_date'], $f['start_time'], $f['date_flexible'], $f['adult_count'], $f['child_count'], $f['customer_name'], $f['email'], $f['phone'], $f['status'], $f['package_id']]);
    return (int)$pdo->lastInsertId();
};

// -- Start time --
$emily = $add([]);
$ok(bcrf_party_start_at($pdo, bcrf_party_get($pdo, $emily))->format('Y-m-d H:i') === '2026-12-05 10:00', 'no start time: counts from opening time');
$pdo->exec("UPDATE event_inquiries SET start_time = '14:00:00' WHERE id = $emily");
$ok(bcrf_party_start_at($pdo, bcrf_party_get($pdo, $emily))->format('H:i') === '14:00', 'start time used when set');
$flex = $add(['date_flexible' => 1, 'customer_name' => 'Flex Person', 'email' => 'flex@example.test']);
$ok(bcrf_party_start_at($pdo, bcrf_party_get($pdo, $flex)) === null, 'date flexible: no start');
$quoted = $add(['status' => 'quoted', 'customer_name' => 'Quoted Person', 'email' => 'q@example.test', 'start_time' => '14:00:00']);
$noMail = $add(['email' => '', 'customer_name' => 'No Email', 'start_time' => '14:00:00']);

// -- Reminder window --
$ok(bcrf_party_emails_due($pdo, 'reminder', $at('2026-12-04 13:00')) === [], 'not yet 24 hours out');
$due = bcrf_party_emails_due($pdo, 'reminder', $at('2026-12-04 14:30'));
$ok(array_column($due, 'id') === [$emily], 'due within 24 hours; quoted, date-flexible and no-email parties skipped');
$ok(bcrf_party_emails_due($pdo, 'reminder', $at('2026-12-05 14:30')) === [], 'not after it starts');

// -- Quote shows on the reminder --
$q = bcrf_quote_create($pdo, 'event_inquiry', $emily, 1);
bcrf_quote_mark_sent($pdo, $q, null, 1, 'sent by email');
[$dueN, $sent] = bcrf_party_emails_run($pdo, 'reminder', $at('2026-12-04 15:00'), $send);
$ok($dueN === 1 && $sent === 1 && count($outbox) === 1, 'reminder sent once');
$m = $outbox[0] ?? ['to' => '', 'subject' => '', 'inner' => ''];
$ok($m['to'] === 'emily@example.test' && str_contains($m['subject'], 'tomorrow'), 'to Emily, about tomorrow');
$ok(str_contains($m['inner'], 'Hi Emily') && str_contains($m['inner'], 'Saturday, December 5 at 2:00 PM'), 'greets her and gives the day and time');
$ok(str_contains($m['inner'], '14 guests (6 adults, 8 children)'), 'guest count');
$ok(str_contains($m['inner'], '$140.00, due when you arrive'), 'what is due on arrival');
$ok(str_contains($m['inner'], 'rain or shine') && str_contains($m['inner'], 'Every child needs an adult'), 'party terms');
$ok(!str_contains($m['inner'], '$10 a guest covers'), 'price line left out (the quote has the price)');
$ok(str_contains($m['inner'], '/booking/quote.php?q=') && str_contains($m['inner'], 'preferences.php?t='), 'quote link and preferences link');
$ok(!str_contains($m['inner'], 'start time for you yet'), 'no start-time ask when one is set');
$ok($one("SELECT reminder_sent_at IS NOT NULL FROM event_inquiries WHERE id = ?", [$emily]) == 1, 'marked as sent');
[$dueN, $sent] = bcrf_party_emails_run($pdo, 'reminder', $at('2026-12-04 16:00'), $send);
$ok($dueN === 0 && count($outbox) === 1, 'not sent again on the next run');

// -- No start time: asks for one --
$outbox = [];
$noTime = $add(['preferred_date' => '2026-12-12', 'customer_name' => 'Sam Lee', 'email' => 'sam@example.test', 'adult_count' => 1, 'child_count' => 0]);
bcrf_party_emails_run($pdo, 'reminder', $at('2026-12-11 11:00'), $send);
$ok(count($outbox) === 1 && str_contains($outbox[0]['inner'], 'start time for you yet') && str_contains($outbox[0]['inner'], '1 guest<'), 'no start time: asks for one; 1 guest');
$ok(!str_contains($outbox[0]['inner'], 'See your quote'), 'no quote: no quote link');

// -- Preference off: claimed, not sent --
$outbox = [];
$off = $add(['preferred_date' => '2026-12-19', 'start_time' => '11:00:00', 'customer_name' => 'Pat Off', 'email' => 'off@example.test']);
bcrf_email_prefs_save($pdo, 'off@example.test', ['visit_reminders' => false], 'link', null, true);
[$dueN, $sent] = bcrf_party_emails_run($pdo, 'reminder', $at('2026-12-18 12:00'), $send);
$ok($dueN === 1 && $sent === 0 && !$outbox, 'reminders turned off: not sent');
$ok($one("SELECT reminder_sent_at IS NOT NULL FROM event_inquiries WHERE id = ?", [$off]) == 1, '… and not tried again');

// -- Thank-you --
$outbox = [];
$ok(bcrf_party_emails_due($pdo, 'thank_you', $at('2026-12-06 07:00')) === [], 'thank-you waits 18 hours');
[$dueN, $sent] = bcrf_party_emails_run($pdo, 'thank_you', $at('2026-12-06 09:00'), $send);
$ok($dueN === 1 && $sent === 1 && $outbox[0]['to'] === 'emily@example.test', 'thank-you the next morning');
$ok(str_contains($outbox[0]['inner'], '/review/') && str_contains($outbox[0]['inner'], 'Hi Emily') && str_contains($outbox[0]['inner'], 'preferences.php?t='), 'review link, greeting, preferences link');
bcrf_party_emails_run($pdo, 'thank_you', $at('2026-12-06 10:00'), $send);
$ok(count($outbox) === 1, 'thank-you sent once');
$old = $add(['preferred_date' => '2026-11-01', 'start_time' => '11:00:00', 'customer_name' => 'Old Party', 'email' => 'old@example.test']);
$ok(!in_array($old, array_column(bcrf_party_emails_due($pdo, 'thank_you', $at('2026-12-06 09:00')), 'id'), true), 'old parties are never thanked');
$declined = $add(['preferred_date' => '2026-12-05', 'start_time' => '11:00:00', 'status' => 'declined', 'customer_name' => 'Gone', 'email' => 'gone@example.test']);
$ok(!in_array($declined, array_column(bcrf_party_emails_due($pdo, 'thank_you', $at('2026-12-06 09:00')), 'id'), true), 'declined parties are not thanked');

// -- A failing sender doesn't stop the run --
$outbox = [];
$a = $add(['preferred_date' => '2026-12-26', 'start_time' => '11:00:00', 'customer_name' => 'A One', 'email' => 'a@example.test']);
$b = $add(['preferred_date' => '2026-12-26', 'start_time' => '12:00:00', 'customer_name' => 'B Two', 'email' => 'b@example.test']);
$flaky = function ($to, $s, $i) use (&$outbox) { if ($to === 'a@example.test') throw new RuntimeException('smtp down'); $outbox[] = $to; return true; };
[$dueN, $sent] = bcrf_party_emails_run($pdo, 'reminder', $at('2026-12-25 12:30'), $flaky);
$ok($dueN === 2 && $sent === 1 && $outbox === ['b@example.test'], 'one bad send doesn\'t stop the others');

echo ($fails ? "$fails of $n checks FAILED\n" : "All $n checks passed\n");
exit($fails ? 1 : 0);
