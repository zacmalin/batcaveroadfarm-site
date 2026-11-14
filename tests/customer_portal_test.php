<?php
// tests/customer_portal_test.php — Farm OS Phase 3 slice 6 (Nov 12, 2026): the customer portal (includes/customer_portal.php):
// emailed sign-in links, accounts made for people who booked without one, ownership by verified email, and the newsletter
// switch, against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/customer_portal_test.php
// Refuses to run unless DB_NAME ends in "_test". Empties that database and builds it from every migration.
// Email is a stand-in: nothing leaves the machine. Exit 0 = all passed.
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
$_SESSION = [];

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
require $root . '/includes/event_quotes.php';
require $root . '/includes/tickets_online.php';
require $root . '/includes/customer_portal.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$tokenFrom = function (): string { $m = end($GLOBALS['mails']); return preg_match('/link\.php\?token=([a-f0-9]{64})/', $m[2] ?? '', $x) ? $x[1] : ''; };

$tz = new DateTimeZone('America/New_York');
$today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$future = (new DateTimeImmutable('now', $tz))->modify('+20 days')->format('Y-m-d');
$past = (new DateTimeImmutable('now', $tz))->modify('-40 days')->format('Y-m-d');

// -- Fixtures --
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role, account_status) VALUES (1, 'Zac', 'Owner', 'o@x.test', 'administrator', 'active')");
$pdo->exec("INSERT INTO booking_types (id, type_key, label, pricing_model, unit_price, requires_payment, is_active, sort_order) VALUES
            (1, 'day_admission', 'Petting Zoo Day Admission', 'per_person', 8.00, 1, 1, 1), (2, 'school', 'School Field Trip', 'per_person', 6.00, 0, 1, 2)");
$tok = fn() => bin2hex(random_bytes(32));
$ins = $pdo->prepare("INSERT INTO bookings (id, type_id, customer_name, email, phone, party_size, payment_status, booking_date, start_time, end_time, status, token)
                      VALUES (?, ?, ?, ?, '', ?, 'waived', ?, '10:00:00', '12:00:00', ?, ?)");
$ins->execute([10, 2, 'Old Fort Elementary', 'Teacher@Example.test', 25, $future, 'confirmed', $tok()]);
$ins->execute([11, 2, 'Old Fort Elementary', 'teacher@example.test', 22, $past, 'completed', $tok()]);
$ins->execute([12, 1, 'Day Ticket Buyer', 'teacher@example.test', 4, $future, 'confirmed', $tok()]);    // day tickets aren't listed as bookings
$ins->execute([13, 2, 'Someone Else', 'other@example.test', 30, $future, 'confirmed', $tok()]);
$pdo->prepare("INSERT INTO event_inquiries (id, event_type, preferred_date, start_time, adult_count, child_count, customer_name, email, status) VALUES
               (2, 'birthday', ?, '14:00:00', 6, 8, 'Emily Jenkins', 'emily@example.test', 'quoted'),
               (3, 'birthday', ?, NULL, 2, 2, 'Archived Party', 'emily@example.test', 'archived')")->execute([$future, $future]);

// Emily's party quote: sent, $140, $40 paid in cash.
$q = bcrf_quote_create($pdo, 'event_inquiry', 2, 1);
$pdo->prepare("UPDATE event_quotes SET sent_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$q['id']]);
bcrf_quote_record_payment($pdo, $q['id'], 'cash', 4000, '', '', 1);
// The school's quote is still a draft: the portal mustn't show it.
bcrf_quote_create($pdo, 'booking', 10, 1);

$pdo->exec("INSERT INTO tickets (id, public_code, source, purchaser_name, purchaser_email, quantity, quantity_redeemed, status, expires_at, amount_cents, created_at, payment_status)
            VALUES (1, 'ABCDEFGH23', 'online', 'Emily Jenkins', 'emily@example.test', 4, 1, 'partially_redeemed', DATE_ADD(NOW(), INTERVAL 300 DAY), 3200, NOW(), 'paid'),
                   (2, 'ZZZZZZZZ23', 'online', 'Emily Jenkins', 'emily@example.test', 2, 0, 'pending_payment', DATE_ADD(NOW(), INTERVAL 300 DAY), 1600, NOW(), 'pending'),
                   (3, 'OTHER00023', 'online', 'Other', 'other@example.test', 2, 0, 'issued', DATE_ADD(NOW(), INTERVAL 300 DAY), 1600, NOW(), 'paid')");
$ptype = (int)$one("SELECT id FROM pass_types ORDER BY id LIMIT 1");
if (!$ptype) { $pdo->exec("INSERT INTO pass_types (type_key, label, price, is_active, sort_order) VALUES ('family', 'Family Pass', 120, 1, 1)"); $ptype = (int)$pdo->lastInsertId(); }
$pdo->prepare("INSERT INTO passes (pass_type_id, holder_name, email, token, valid_from, valid_until, status) VALUES
               (?, 'Emily Jenkins', 'emily@example.test', ?, ?, DATE_ADD(?, INTERVAL 200 DAY), 'active'),
               (?, 'Emily Jenkins', 'emily@example.test', ?, ?, ?, 'pending_payment')")
    ->execute([$ptype, str_repeat('a', 48), $past, $today, $ptype, str_repeat('b', 48), $today, $future]);

// -- Sign-in link: an address with nothing on file gets nothing, and no account --
$GLOBALS['mails'] = [];
$ok(!bcrf_portal_signin_request($pdo, 'stranger@example.test'), 'unknown address: no link');
$ok(!$GLOBALS['mails'] && !$one("SELECT COUNT(*) FROM customers WHERE email = 'stranger@example.test'"), 'unknown address: no email, no account made');
$ok(!bcrf_portal_signin_request($pdo, 'not-an-email'), 'bad address refused');

// -- Emily has a party but no account: the link makes one (no password, not verified until she clicks) --
$ok(bcrf_portal_email_has_records($pdo, 'EMILY@example.test'), 'records found by email, any case');
$ok(bcrf_portal_signin_request($pdo, ' Emily@Example.test '), 'link sent to Emily');
$emily = bcrf_customer_find_by_email($pdo, 'emily@example.test');
$ok($emily && $emily['first_name'] === 'Emily' && $emily['last_name'] === 'Jenkins', 'account made with her name from the party');
$ok($emily && $emily['password'] === '' && $emily['email_verified_at'] === null, 'no password, not verified yet');
$ok(count($GLOBALS['mails']) === 1 && $GLOBALS['mails'][0][0] === 'emily@example.test', 'one email, to her address');
$t1 = $tokenFrom();
$ok(strlen($t1) === 64, 'email holds a 64-hex link');
$ok($one("SELECT purpose FROM password_resets WHERE token_hash = ?", [hash('sha256', $t1)]) === 'signin', 'stored as a sign-in token (hash only)');
$ok(!$one("SELECT COUNT(*) FROM password_resets WHERE token_hash = ?", [$t1]), 'raw token never stored');

// Second request burns the first link.
bcrf_portal_signin_request($pdo, 'emily@example.test');
$t2 = $tokenFrom();
$ok($t2 !== $t1 && bcrf_portal_signin_peek($pdo, $t1) === null, 'a new link cancels the old one');
$ok(bcrf_portal_signin_peek($pdo, $t2) !== null, 'new link is live');
$ok((int)$one("SELECT COUNT(*) FROM customers WHERE email = 'emily@example.test'") === 1, 'no second account');

// Peeking (the confirm screen) doesn't spend it; using it does, once.
$ok(bcrf_portal_signin_peek($pdo, $t2) !== null, 'peek leaves the link live');
$c = bcrf_portal_signin_use($pdo, $t2);
$ok($c && (int)$c['id'] === (int)$emily['id'], 'link signs Emily in');
$ok($c && $c['email_verified_at'] !== null, 'using the link verifies her email');
$ok(bcrf_portal_signin_use($pdo, $t2) === null, 'link works only once');
$ok(bcrf_portal_signin_use($pdo, 'nothex') === null && bcrf_portal_signin_use($pdo, str_repeat('0', 64)) === null, 'junk and unknown tokens refused');

// Expired link.
bcrf_portal_signin_request($pdo, 'emily@example.test');
$t3 = $tokenFrom();
$pdo->prepare("UPDATE password_resets SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE token_hash = ?")->execute([hash('sha256', $t3)]);
$ok(bcrf_portal_signin_use($pdo, $t3) === null, 'expired link refused');

// A password-reset token can't be used as a sign-in link.
$reset = bin2hex(random_bytes(32));
$pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, purpose, realm) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), 'reset', 'customer')")->execute([$emily['id'], hash('sha256', $reset)]);
$ok(bcrf_portal_signin_peek($pdo, $reset) === null, 'reset token is not a sign-in link');
// A staff-realm row with the same id can't sign a customer in.
$staffTok = bin2hex(random_bytes(32));
$pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, purpose, realm) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), 'signin', 'staff')")->execute([$emily['id'], hash('sha256', $staffTok)]);
$ok(bcrf_portal_signin_peek($pdo, $staffTok) === null, 'staff-realm token refused');

// Disabled account: no link.
$GLOBALS['mails'] = [];
$pdo->exec("INSERT INTO customers (first_name, last_name, email, password, account_status, created_at) VALUES ('Off', 'Person', 'off@example.test', '', 'disabled', NOW())");
$ok(!bcrf_portal_signin_request($pdo, 'off@example.test') && !$GLOBALS['mails'], 'disabled account gets no link');

// -- What Emily sees --
$emily = bcrf_customer_find($pdo, (int)$emily['id']);
$it = bcrf_portal_items($pdo, $emily);
$ok($it['verified'], 'verified');
$ok(count($it['upcoming']) === 1 && $it['upcoming'][0]['type'] === 'event_inquiry' && $it['upcoming'][0]['id'] === 2, 'her party is upcoming (archived one hidden)');
$p = $it['upcoming'][0];
$ok($p['total'] === 14000 && $p['paid'] === 4000 && $p['balance'] === 10000 && $p['balance_when'] === 'arrival', 'party shows $140, $40 paid, $100 left on arrival');
$ok($p['quote_url'] && str_contains($p['quote_url'], '/booking/quote.php?q='), 'pay link is the quote page');
$ok($p['status'] === 'Quote sent', 'friendly status');
$ok(count($it['tickets']) === 1 && $it['tickets'][0]['code'] === 'ABCDEFGH23' && $it['tickets'][0]['left'] === 3 && $it['tickets'][0]['usable'], 'paid ticket with 3 left; unpaid one hidden');
$ok(str_contains($it['tickets'][0]['url'], 'ticket.php?c=ABCDEFGH23'), 'ticket opens its QR page');
$ok(count($it['passes']) === 1 && $it['passes'][0]['active'] && $it['passes'][0]['type'] !== '', 'active pass shown; unpaid pass hidden');
$ok(str_contains($it['passes'][0]['url'], 'verify.php?token=' . str_repeat('a', 48)), 'pass opens its QR page');
$ok(!$it['receipts'], 'no receipts yet');

// -- An unverified password account sees nothing matched by email --
$pdo->exec("INSERT INTO customers (first_name, last_name, email, password, created_at) VALUES ('Tess', 'Teacher', 'teacher@example.test', '" . password_hash('pw', PASSWORD_DEFAULT) . "', NOW())");
$tess = bcrf_customer_find_by_email($pdo, 'teacher@example.test');
$it = bcrf_portal_items($pdo, $tess);
$ok(!$it['verified'] && !$it['upcoming'] && !$it['past'] && !$it['tickets'] && !$it['passes'], 'unverified email: nothing shown');

// Register receipts follow the attached customer, verified or not.
$pdo->prepare("INSERT INTO sales (id, sale_number, customer_id, status, total_cents, subtotal_cents, refunded_cents, business_date, completed_at, idempotency_key) VALUES
               (50, 'R-2026-000050', ?, 'completed', 1200, 1200, 0, ?, UTC_TIMESTAMP(), 'k50'), (51, 'R-2026-000051', ?, 'voided', 500, 500, 0, ?, UTC_TIMESTAMP(), 'k51')")
    ->execute([$tess['id'], $today, $tess['id'], $today]);
$pdo->exec("INSERT INTO sale_lines (sale_id, line_no, description, quantity, unit, unit_price_cents, gross_cents, line_total_cents) VALUES
            (50, 1, 'Feed cup', 2, 'each', 300, 600, 600), (50, 2, 'Eggs (dozen)', 1, 'each', 600, 600, 600)");
$it = bcrf_portal_items($pdo, $tess);
$ok(count($it['receipts']) === 2 && $it['receipts'][0]['number'] === 'R-2026-000051' && $it['receipts'][0]['voided'], 'receipts listed newest first, voided marked');
$ok(count($it['receipts'][1]['lines']) === 2 && (int)$it['receipts'][1]['lines'][0]['line_total_cents'] === 600, 'receipt lines shown');
$ok(!bcrf_portal_items($pdo, $emily)['receipts'], 'Emily sees none of Tess\'s receipts');

// Verified (she uses a link): her bookings show, the day ticket and the other school's don't; the draft quote stays private.
bcrf_portal_signin_request($pdo, 'teacher@example.test');
$tess = bcrf_portal_signin_use($pdo, $tokenFrom());
$it = bcrf_portal_items($pdo, $tess);
$ok(count($it['upcoming']) === 1 && $it['upcoming'][0]['id'] === 10, 'upcoming school trip only (not the day ticket, not another school)');
$ok($it['upcoming'][0]['quote_url'] === null && $it['upcoming'][0]['total'] === null, 'draft quote not shown');
$ok(count($it['past']) === 1 && $it['past'][0]['id'] === 11 && $it['past'][0]['status'] === 'Done', 'last year\'s trip is in past');
$ok(!$it['tickets'] && !$it['passes'], 'none of Emily\'s tickets or passes');
$ok((int)$one("SELECT COUNT(*) FROM customers WHERE email = 'teacher@example.test'") === 1, 'existing account reused, not duplicated');
$ok(password_verify('pw', (string)$one("SELECT password FROM customers WHERE email = 'teacher@example.test'")), 'her password still works');

// -- Newsletter --
$ok(bcrf_portal_newsletter($pdo, 'emily@example.test') === false, 'not on the newsletter');
$unv = ['id' => 999, 'first_name' => 'U', 'last_name' => 'V', 'email' => 'u@example.test', 'email_verified_at' => null];
$ok(!bcrf_portal_newsletter_set($pdo, $unv, true) && bcrf_portal_newsletter($pdo, 'u@example.test') === false, 'unverified email can\'t join');
$ok(bcrf_portal_newsletter_set($pdo, $emily, true) && bcrf_portal_newsletter($pdo, 'emily@example.test') === true, 'Emily joins');
$ok(bcrf_portal_newsletter_set($pdo, $emily, true) && (int)$one("SELECT COUNT(*) FROM mailing_list WHERE email = 'emily@example.test'") === 1, 'joining twice keeps one row');
$ok($one("SELECT consent_source FROM mailing_list WHERE email = 'emily@example.test'") === 'portal' && $one("SELECT consent_at FROM mailing_list WHERE email = 'emily@example.test'") !== null,
    'joining in My farm records where and when she agreed (Nov 14, 2026)');
$ok(bcrf_portal_newsletter_set($pdo, $emily, false) && bcrf_portal_newsletter($pdo, 'emily@example.test') === false, 'Emily leaves');
$ok((int)$one("SELECT COUNT(*) FROM audit_events WHERE object_type = 'mailing_list' AND actor_type = 'customer'") === 3, 'each change audited as the customer');
$ok($pdo->query("SELECT CONCAT(value, via) FROM email_preference_log WHERE email = 'emily@example.test' AND setting = 'newsletter' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) === ['1portal', '0portal'],
    'on and off are in the email preferences log');

echo $fails ? "\n$fails of $n checks FAILED\n" : "All $n customer portal checks passed\n";
exit($fails ? 1 : 0);
