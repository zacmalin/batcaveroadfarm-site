<?php
// tests/capacity_holds_test.php — Farm OS Phase 3 slice 5 (Nov 12, 2026): capacity holds, group arrival at the gate and
// weather days (includes/capacity_holds.php, includes/weather_days.php, includes/admission.php, migration 0033) against a
// real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/capacity_holds_test.php
// Refuses to run unless DB_NAME ends in "_test". Empties that database and builds it from every migration.
// Email is a stand-in: nothing leaves the machine. Times are built around "now" in the farm's time zone. Exit 0 = all passed.
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$dsn = 'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4';
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', $opts);
$root = dirname(__DIR__);
ini_set('log_errors', '0');
date_default_timezone_set('America/New_York');

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
require $root . '/includes/queue.php';
require $root . '/admin/includes/audit.php';
require $root . '/includes/admission.php';
require $root . '/includes/capacity_holds.php';
require $root . '/includes/event_quotes.php';
require $root . '/includes/weather_days.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$throws = function (callable $f, string $what, string $like = '', string $class = 'BcrfSaleError') use ($ok) {
    try { $f(); $ok(false, "$what (no error)"); }
    catch (Throwable $e) { $ok($e instanceof $class && ($like === '' || stripos($e->getMessage(), $like) !== false), "$what [" . get_class($e) . ': ' . $e->getMessage() . "]"); }
};
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };

// -- Migration --
$ok(bcrf_holds_ready($pdo), 'migration 0033 ran (start_time, check_ins.booking_id / event_inquiry_id)');
$ok(bcrf_weather_ready($pdo), 'weather tables exist');
$ok($one("SELECT setting_value FROM site_settings WHERE setting_key = 'party_length_minutes'") === '120', 'party length setting 120 min');
$ok($one("SELECT setting_value FROM site_settings WHERE setting_key = 'hold_early_minutes'") === '30', 'hold early setting 30 min');
$ok(str_contains((string)$one("SELECT setting_value FROM site_settings WHERE setting_key = 'weather_message'"), 'two weeks'), 'default weather wording mentions the two-week party move');
$pdo->exec("UPDATE site_settings SET setting_value = '150' WHERE setting_key = 'party_length_minutes'");
(require $root . '/admin/migrations/0033_capacity_holds.php')($pdo);
$ok($one("SELECT setting_value FROM site_settings WHERE setting_key = 'party_length_minutes'") === '150', 'repeat run keeps a changed setting');
$pdo->exec("UPDATE site_settings SET setting_value = '120' WHERE setting_key = 'party_length_minutes'");

// -- Fixtures around "now" (farm time) --
$tz = new DateTimeZone('America/New_York');
$now = new DateTimeImmutable('now', $tz);
$today = $now->format('Y-m-d');
$tomorrow = $now->modify('+1 day')->format('Y-m-d');
$startB = max($today . ' 00:00:00', $now->modify('-10 minutes')->format('Y-m-d H:i:00'));
$endB = min($today . ' 23:59:00', $now->modify('+110 minutes')->format('Y-m-d H:i:00'));
$startP = max($today . ' 00:00:00', $now->modify('-5 minutes')->format('Y-m-d H:i:00'));
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role, account_status) VALUES (1, 'Zac', 'Owner', 'o@x.test', 'administrator', 'active')");
$pdo->exec("INSERT INTO site_settings (setting_key, setting_value) VALUES ('capacity_limit', '20') ON DUPLICATE KEY UPDATE setting_value = '20'");
$pdo->exec("INSERT INTO booking_types (id, type_key, label, pricing_model, unit_price, requires_payment, is_active, sort_order) VALUES
            (1, 'day_admission', 'Petting Zoo Day Admission', 'per_person', 8.00, 1, 1, 1),
            (2, 'school', 'School Field Trip', 'per_person', 6.00, 0, 1, 2)");
$tok = fn() => bin2hex(random_bytes(32));
$ins = $pdo->prepare("INSERT INTO bookings (id, type_id, customer_name, email, phone, party_size, payment_status, booking_date, start_time, end_time, status, token)
                      VALUES (?, ?, ?, ?, '', ?, 'waived', ?, ?, ?, ?, ?)");
$ins->execute([10, 2, 'Old Fort Elementary', 'teacher@example.test', 25, $today, substr($startB, 11), substr($endB, 11), 'confirmed', $tok()]);
$ins->execute([11, 2, 'Pending School', 'p@example.test', 30, $today, substr($startB, 11), substr($endB, 11), 'pending', $tok()]);
$ins->execute([12, 1, 'Day Ticket Buyer', 'd@example.test', 4, $today, '10:00:00', '16:00:00', 'confirmed', $tok()]);
$ins->execute([13, 2, 'Tomorrow School', 'tomorrow@example.test', 40, $tomorrow, '10:00:00', '12:00:00', 'pending', $tok()]);
$pdo->prepare("INSERT INTO event_inquiries (id, event_type, preferred_date, start_time, adult_count, child_count, customer_name, email, status) VALUES
               (2, 'birthday', ?, ?, 6, 8, 'Emily Test', 'emily@example.test', 'confirmed'),
               (3, 'birthday', ?, NULL, 4, 6, 'No Email Party', '', 'quoted'),
               (4, 'birthday', ?, NULL, 2, 2, 'Declined Party', 'x@example.test', 'declined')")
    ->execute([$today, substr($startP, 11), $tomorrow, $tomorrow]);

// -- Holds --
$holds = bcrf_holds_for_date($pdo, $today);
$ok(count($holds) === 2, 'two holds today: the confirmed school booking and the confirmed party (not pending, not day tickets)');
$hb = $holds[0]['type'] === 'booking' ? $holds[0] : $holds[1];
$hp = $holds[0]['type'] === 'event_inquiry' ? $holds[0] : $holds[1];
$ok($hb['id'] === 10 && $hb['guests'] === 25 && $hb['what'] === 'School Field Trip', 'booking hold: 25 guests');
$ok($hp['id'] === 2 && $hp['guests'] === 14 && $hp['what'] === 'Birthday party', 'party hold: 6 adults + 8 children = 14');
$ok($hb['active'] && $hp['active'], 'both hold capacity now');
$ok($hp['timed'] && strtotime($hp['end_utc'] . ' UTC') - strtotime($hp['start_utc'] . ' UTC') === 120 * 60, 'party window = start + 2 hours');
$ok(strtotime($hp['start_utc'] . ' UTC') - strtotime($hp['from_utc'] . ' UTC') === 30 * 60, 'hold starts 30 minutes early');
$pdo->exec("UPDATE event_inquiries SET status = 'confirmed' WHERE id = 3");
$ht = bcrf_holds_for_date($pdo, $tomorrow);
$ok(count($ht) === 1 && !$ht[0]['timed'] && $ht[0]['start_label'] === '10:00 AM' && $ht[0]['end_label'] === '4:00 PM', 'party with no start time holds the whole open day (10–4 with no hours set)');
$pdo->exec("UPDATE event_inquiries SET status = 'quoted' WHERE id = 3");

// -- Occupancy with holds --
$o = bcrf_occupancy($pdo);
$ok($o['base_limit'] === 20 && $o['held'] === 39 && $o['limit'] === 59, 'allowance = 20 walk-in + 39 held');
$ok($o['on_site'] === 0 && $o['free'] === 20 && $o['held_waiting'] === 39 && $o['level'] === 'ok', 'empty farm: 20 walk-in spaces free, 39 held');

// Walk-ins fill 18 of 20
bcrf_check_in_row($pdo, ['source_type' => 'gate_sale', 'party_size' => 18, 'guests_counted' => 18]);
$o = bcrf_occupancy($pdo);
$ok($o['pool_on'] === 18 && $o['free'] === 2 && $o['level'] === 'busy', 'walk-ins: 18 of 20, busy');
$ok(bcrf_capacity_check($pdo, 2, null) === [null, null], 'two more walk-ins fit');
$throws(fn() => bcrf_capacity_check($pdo, 3, null), 'three more walk-ins do not (groups\' held spaces are not theirs)', 'walk-in spaces', 'BcrfCapacityError');

// -- Arrival against the hold --
$r = bcrf_hold_arrive($pdo, 'booking', 10, 25);
$ok($r['arrived'] === 25 && $r['quote'] === null, 'school arrives: 25 in, no quote to change');
$o = bcrf_occupancy($pdo);
$ok($o['on_site'] === 43 && $o['pool_on'] === 18 && $o['free'] === 2, 'the school uses its own hold, not walk-in spaces');
$row = $pdo->query("SELECT * FROM check_ins WHERE booking_id = 10")->fetch();
$ok($row && $row['source_type'] === 'booking' && (int)$row['party_size'] === 25 && $row['business_date'] === $today, 'check-in linked to the booking');
$ok(abs(strtotime($row['expected_out_at'] . ' UTC') - strtotime($hb['end_utc'] . ' UTC')) <= 120 || strtotime($row['expected_out_at'] . ' UTC') >= strtotime($hb['end_utc'] . ' UTC'), 'expected out at the end of their window');
$ok((int)$one("SELECT COUNT(*) FROM audit_events WHERE object_type = 'bookings' AND object_id = '10' AND object_label LIKE '%arrived%'") === 1, 'arrival audited on the booking');

// Party with a quote: actual headcount updates the per-guest line
$q = bcrf_quote_create($pdo, 'event_inquiry', 2, 1);
$ok($q['total_cents'] === 14000, 'party quote $140');
$r = bcrf_hold_arrive($pdo, 'event_inquiry', 2, 12, true);
$q = bcrf_quote_get($pdo, 'event_inquiry', 2);
$ok($r['arrived'] === 12 && $q['total_cents'] === 12000 && $q['lines'][0]['qty'] === 12, '12 arrive: quote becomes 12 × $10 = $120');
$ok(str_contains((string)$r['quote'], '$140.00 → $120.00'), 'the gate is told how the quote changed');
$r = bcrf_hold_arrive($pdo, 'event_inquiry', 2, 3, true);
$q = bcrf_quote_get($pdo, 'event_inquiry', 2);
$ok($r['arrived'] === 15 && $q['total_cents'] === 15000, '3 more: 15 arrived (one over the 14 booked), quote $150');
$o = bcrf_occupancy($pdo);
$ok($o['pool_on'] === 19 && $o['free'] === 1, 'the one guest over the booking uses a walk-in space');
$r = bcrf_hold_arrive($pdo, 'event_inquiry', 2, 0 + 1, false);
$ok(bcrf_quote_get($pdo, 'event_inquiry', 2)['total_cents'] === 15000 && $r['quote'] === null, 'without "update the quote" the quote stays');
$throws(fn() => bcrf_hold_arrive($pdo, 'booking', 10, 5), 'five extra school guests don\'t fit in the 0 walk-in spaces left', 'more than the 25 booked', 'BcrfCapacityError');
$r = bcrf_hold_arrive($pdo, 'booking', 10, 5, false, 'Extra parent chaperones');
$ok($r['arrived'] === 30 && $one("SELECT capacity_override_reason FROM check_ins WHERE booking_id = 10 ORDER BY id DESC LIMIT 1") === 'Extra parent chaperones', 'an Owner can let them in with a reason');
$_SESSION['user_role'] = 'Staff';
$throws(fn() => bcrf_hold_arrive($pdo, 'booking', 10, 1, false, 'please'), 'staff cannot override', 'Owner or Manager', 'BcrfCapacityError');
$_SESSION['user_role'] = 'administrator';

// Not confirmed for today
$throws(fn() => bcrf_hold_arrive($pdo, 'booking', 11, 5), 'a pending booking can\'t arrive against a hold', 'isn\'t confirmed for today');
$throws(fn() => bcrf_hold_arrive($pdo, 'event_inquiry', 3, 5), 'a party on another day can\'t arrive today', 'isn\'t confirmed for today');
$throws(fn() => bcrf_hold_arrive($pdo, 'booking', 10, 0), 'zero guests refused', 'how many');

// Gate views
$g = bcrf_holds_gate_view($pdo);
$gp = array_values(array_filter($g, fn($x) => $x['type'] === 'event_inquiry'))[0];
$ok(count($g) === 2 && $gp['arrived'] === 16 && $gp['guests'] === 14 && $gp['per_guest_quote'] && str_contains($gp['money'], '$150.00'), 'groups today: arrivals, expected, quote state');
$who = array_column(bcrf_on_site_view($pdo), 'who');
$ok(in_array('Birthday party: Emily Test', $who, true) && in_array('School Field Trip: Old Fort Elementary', $who, true), 'on-site list names the groups');

// Checked-out group: hold ends with its window; still on site keeps it active
$pdo->exec("UPDATE check_ins SET checked_out_at = UTC_TIMESTAMP() WHERE booking_id = 10");
$ok(bcrf_occupancy($pdo)['pool_on'] === 20, 'school leaves: walk-in count unchanged (18 + the party\'s 2 extra)');

// Per-guest line detection
$ok(bcrf_hold_per_guest_line([['label' => 'Pony ride', 'qty' => 1], ['label' => 'Admission', 'qty' => 20]], 20) === 1, 'per-guest line = the one whose quantity is the expected count');
$ok(bcrf_hold_per_guest_line([['label' => 'Party guests', 'qty' => 9]], 20) === 0, 'or the one that says "guest"');
$ok(bcrf_hold_per_guest_line([['label' => 'Flat fee', 'qty' => 1]], 20) === null, 'flat price: no per-guest line');

// Quote subject shows the party start time
$s = bcrf_quote_subject($pdo, 'event_inquiry', 2);
$ok(str_contains(bcrf_quote_when($s), date('g:i A', strtotime($startP))), 'party quote shows its start time');

// -- Weather day --
$subs = bcrf_weather_subjects($pdo, $tomorrow);
$ok(count($subs) === 2 && in_array('Tomorrow School', array_column($subs, 'name'), true) && in_array('No Email Party', array_column($subs, 'name'), true),
    'tomorrow: the pending school and the quoted party (not the declined one)');
$sent = [];
$stub = function ($to, $subj, $html) use (&$sent) { $sent[] = [$to, $subj, $html]; return true; };
$w = bcrf_weather_call($pdo, $tomorrow, "Too much rain.\nWe'll move you.", true, 1, $stub);
$ok($w['told'] === 1 && $w['no_email'] === ['No Email Party'] && $w['closed'], 'one emailed, one with no email to call, day closed');
$ok(count($sent) === 1 && $sent[0][0] === 'tomorrow@example.test' && str_contains($sent[0][1], 'Weather update') && str_contains($sent[0][2], 'Too much rain.<br />'), 'the email has the message');
$ok(str_contains($sent[0][2], 'School Field Trip') || str_contains($sent[0][2], 'school field trip'), 'and says which booking');
$ok((int)$one("SELECT COUNT(*) FROM booking_blackouts WHERE d_date = ?", [$tomorrow]) === 1, 'blackout added for the day');
$ok($one("SELECT reminder_sent_at FROM bookings WHERE id = 13") !== null, 'no cheerful reminder after the bad news');
$ok((int)$one("SELECT COUNT(*) FROM weather_day_items") === 2 && (int)$one("SELECT COUNT(*) FROM weather_day_items WHERE sent_at IS NULL AND send_error LIKE 'No email%'") === 1, 'both recorded; the one without email flagged');
$ok($one("SELECT status FROM bookings WHERE id = 13") === 'pending' && $one("SELECT status FROM event_inquiries WHERE id = 3") === 'quoted', 'nothing cancelled automatically');
$w = bcrf_weather_call($pdo, $tomorrow, 'Again', true, 1, $stub);
$ok($w['told'] === 0 && count($sent) === 1, 'pressing again doesn\'t email anyone twice');
$ins->execute([14, 2, 'Late Booker', 'late@example.test', 10, $tomorrow, '13:00:00', '15:00:00', 'confirmed', $tok()]);
$w = bcrf_weather_call($pdo, $tomorrow, 'Again', false, 1, $stub);
$ok($w['told'] === 1 && end($sent)[0] === 'late@example.test', 'someone booked since gets told on the next press');
$fail = fn() => false;
$ins->execute([15, 2, 'Bounce Co', 'bounce@example.test', 10, $tomorrow, '13:00:00', '15:00:00', 'confirmed', $tok()]);
$w = bcrf_weather_call($pdo, $tomorrow, 'Again', false, 1, $fail);
$ok($w['failed'] === ['Bounce Co'] && $one("SELECT sent_at FROM weather_day_items WHERE subject_id = 15") === null, 'a failed send is recorded and retried next time');
$w = bcrf_weather_call($pdo, $tomorrow, 'Again', false, 1, $stub);
$ok($w['told'] === 1 && $one("SELECT sent_at FROM weather_day_items WHERE subject_id = 15") !== null, 'retry sends it');
$throws(fn() => bcrf_weather_call($pdo, $now->modify('-1 day')->format('Y-m-d'), 'x', false, 1, $stub), 'a past day is refused', 'passed', 'BcrfWeatherError');
$throws(fn() => bcrf_weather_call($pdo, $tomorrow, '  ', false, 1, $stub), 'an empty message is refused', 'message', 'BcrfWeatherError');
$item = (int)$one("SELECT id FROM weather_day_items WHERE subject_type = 'booking' AND subject_id = 13");
bcrf_weather_set_outcome($pdo, $item, 'rescheduled', 'Moved to next Friday', 1);
$ok($one("SELECT outcome FROM weather_day_items WHERE id = ?", [$item]) === 'rescheduled', 'outcome saved');
$throws(fn() => bcrf_weather_set_outcome($pdo, $item, 'teleported', '', 1), 'unknown outcome refused', 'Pick', 'BcrfWeatherError');
$d = bcrf_weather_day($pdo, $tomorrow);
$ok($d && (int)$d['closed_day'] === 1 && count($d['items']) === 4, 'the day shows all four told');
$rec = bcrf_weather_recent($pdo);
$ok(count($rec) === 1 && (int)$rec[0]['told'] === 4 && (int)$rec[0]['open_items'] === 3, 'recent days list: 4 told, 3 not sorted yet');

echo $fails ? "$fails of $n checks FAILED\n" : "All $n checks passed\n";
exit($fails ? 1 : 0);
