<?php
// tests/event_quotes_test.php — Farm OS Phase 3 slice 4 (Nov 12, 2026): quotes, deposits and payments for group bookings
// and parties (includes/event_quotes.php, migration 0032) against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/event_quotes_test.php
// Refuses to run unless DB_NAME ends in "_test". Empties that database and builds it from every migration.
// Stripe and email are stand-ins: nothing leaves the machine. Exit 0 = all passed.
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$dsn = 'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4';
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', $opts);
$root = dirname(__DIR__);
ini_set('log_errors', '0');
date_default_timezone_set('America/New_York');

// Stand-ins for the site mailer and the signed-in user.
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
require $root . '/includes/event_quotes.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$throws = function (callable $f, string $what, string $like = '') use ($ok) {
    try { $f(); $ok(false, "$what (no error)"); }
    catch (BcrfQuoteError $e) { $ok($like === '' || stripos($e->getMessage(), $like) !== false, "$what [" . $e->getMessage() . "]"); }
};
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };

// -- Migration --
$ok(bcrf_quotes_ready($pdo), 'migration 0032 ran');
$ok($one("SELECT setting_value FROM site_settings WHERE setting_key = 'party_price_per_guest_cents'") === '1000', 'party price setting $10');
$pdo->exec("UPDATE site_settings SET setting_value = '1200' WHERE setting_key = 'party_price_per_guest_cents'");
(require $root . '/admin/migrations/0032_event_quotes.php')($pdo);
$ok($one("SELECT setting_value FROM site_settings WHERE setting_key = 'party_price_per_guest_cents'") === '1200', 'repeat run keeps a changed party price');
$pdo->exec("UPDATE site_settings SET setting_value = '1000' WHERE setting_key = 'party_price_per_guest_cents'");

// -- Money parsing --
$ok(bcrf_quote_parse_cents('140') === 14000 && bcrf_quote_parse_cents('$12.5') === 1250 && bcrf_quote_parse_cents('1,000.05') === 100005, 'parse dollars');
$ok(bcrf_quote_parse_cents('') === null && bcrf_quote_parse_cents('-5') === null && bcrf_quote_parse_cents('1.234') === null && bcrf_quote_parse_cents('abc') === null, 'refuse bad amounts');

// -- Fixtures: a party like Emily's (6 adults, 8 children, Dec 5) and a school group booking --
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role, account_status) VALUES (1, 'Zac', 'Owner', 'o@x.test', 'administrator', 'active')");
$pdo->exec("INSERT INTO event_inquiries (id, event_type, preferred_date, adult_count, child_count, birthday_age, customer_name, email, status)
            VALUES (2, 'birthday', '2026-12-05', 6, 8, 6, 'Emily Test', 'emily@example.test', 'contacted')");
$pdo->exec("INSERT INTO booking_types (id, type_key, label, pricing_model, unit_price, requires_payment, is_active, sort_order) VALUES
            (1, 'day_admission', 'Petting Zoo Day Admission', 'per_person', 8.00, 1, 1, 1),
            (2, 'group_visit', 'Farm Visit (groups of 6+)', 'per_person', 6.00, 0, 1, 2),
            (3, 'tour', 'Group Tour', 'flat', 75.00, 1, 1, 3),
            (4, 'school', 'School Field Trip', 'per_person', 0.00, 0, 1, 4)");
$tok = fn() => bin2hex(random_bytes(32));
$ins = $pdo->prepare("INSERT INTO bookings (id, type_id, customer_name, email, phone, party_size, amount_total, payment_status, payment_provider, payment_reference, paid_at,
                      booking_date, start_time, end_time, status, token) VALUES (?, ?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, '10:00:00', '12:00:00', ?, ?)");
$ins->execute([10, 2, 'Old Fort Elementary', 'teacher@example.test', 24, null, 'waived', null, null, null, '2027-04-14', 'pending', $tok()]);
$ins->execute([11, 3, 'Scout Troop 7', 'scouts@example.test', 9, 75.00, 'paid', 'stripe', 'cs_test_tour', '2026-11-12 08:00:00', '2027-05-01', 'confirmed', $tok()]);
$ins->execute([12, 4, 'Montessori', '', 30, null, 'waived', null, null, null, '2027-04-20', 'pending', $tok()]);
$ins->execute([13, 2, 'Cancelled Co', 'c@example.test', 8, null, 'waived', null, null, null, '2027-04-21', 'cancelled', $tok()]);

// -- Starting a party quote --
$p = bcrf_quote_create($pdo, 'event_inquiry', 2, 1);
$ok($p['total_cents'] === 14000, 'party starts at $10 × 14 guests = $140');
$ok(count($p['lines']) === 1 && $p['lines'][0]['qty'] === 14 && $p['lines'][0]['unit_cents'] === 1000, 'one line, 14 × $10');
$ok($p['deposit_cents'] === 0 && $p['balance_when'] === 'arrival' && $p['pay_online'] === 0, 'party: no deposit, pay on arrival, online off');
$ok(strlen($p['token']) === 32 && ctype_xdigit($p['token']), 'random 32-hex token');
$ok(bcrf_quote_create($pdo, 'event_inquiry', 2, 1)['id'] === $p['id'] && (int)$one("SELECT COUNT(*) FROM event_quotes") === 1, 'starting again returns the same quote');
$ps = bcrf_quote_status($pdo, $p);
$ok($ps['stage'] === 'draft' && $ps['balance'] === 14000 && $ps['label'] === 'Quote $140.00 · not sent', 'unsent quote reads as a draft');
$ok(bcrf_quote_payable($pdo, $p) === ['deposit' => 0, 'balance' => 0], 'party with online off offers nothing to pay online');
$ok(bcrf_quote_by_token($pdo, strtoupper($p['token']))['id'] === $p['id'] && bcrf_quote_by_token($pdo, 'nope') === null && bcrf_quote_by_token($pdo, str_repeat('0', 32)) === null, 'look up by token');

// -- Sending marks a party Quoted and emails the customer's own address --
$GLOBALS['mails'] = [];
$to = bcrf_quote_email($pdo, $p, 1);
$ok($to === 'emily@example.test' && count($GLOBALS['mails']) === 1, 'quote emailed to the customer');
$mail = $GLOBALS['mails'][0][2] ?? '';
$ok(str_contains($mail, '$140.00') && str_contains($mail, 'Zac, BCRF') && str_contains($mail, 'No deposit: pay in full when you arrive') && str_contains($mail, bcrf_quote_url($p)), 'email has the price, terms, sign-off and link');
$ok(str_contains($mail, 'rain or shine') && str_contains($mail, 'Every child needs an adult'), 'party terms in the email');
$ok($one("SELECT status FROM event_inquiries WHERE id = 2") === 'quoted', 'contacted party moves to quoted');
$p = bcrf_quote_by_id($pdo, $p['id']);
$ok($p['sent_count'] === 1 && $p['sent_at'] !== null, 'sent time recorded');
$ok(bcrf_quote_status($pdo, $p)['label'] === '$140.00 due on arrival', 'sent party: $140 due on arrival');
$pdo->exec("UPDATE event_inquiries SET status = 'confirmed' WHERE id = 2");
bcrf_quote_mark_sent($pdo, $p, null, 1, 'marked sent');
$ok($one("SELECT status FROM event_inquiries WHERE id = 2") === 'confirmed', 'sending again never moves a confirmed party back');

// -- Paying on arrival --
$throws(fn() => bcrf_quote_record_payment($pdo, $p['id'], 'cash', 15000, '', '', 1), 'more than the balance refused', 'more than');
$throws(fn() => bcrf_quote_record_payment($pdo, $p['id'], 'bitcoin', 1000, '', '', 1), 'unknown method refused', 'how they paid');
$throws(fn() => bcrf_quote_record_payment($pdo, $p['id'], 'cash', 0, '', '', 1), 'zero refused');
$throws(fn() => bcrf_quote_record_payment($pdo, $p['id'], 'register', 14000, '', '', 1), 'register needs a receipt number', 'receipt number');
$throws(fn() => bcrf_quote_record_payment($pdo, $p['id'], 'register', 14000, 'R-2026-000999', '', 1), 'unknown receipt refused', 'No register sale');
$pdo->exec("INSERT INTO sales (id, sale_number, status, total_cents, subtotal_cents, business_date, completed_at, idempotency_key) VALUES
            (50, 'R-2026-000050', 'completed', 10000, 10000, '2026-12-05', UTC_TIMESTAMP(), 'k50'), (51, 'R-2026-000051', 'voided', 14000, 14000, '2026-12-05', UTC_TIMESTAMP(), 'k51')");
$throws(fn() => bcrf_quote_record_payment($pdo, $p['id'], 'register', 10000, 'R-2026-000051', '', 1), 'voided receipt refused', 'voided');
$throws(fn() => bcrf_quote_record_payment($pdo, $p['id'], 'register', 12000, 'r-2026-000050', '', 1), 'more than the receipt refused', 'already used up');
$pid1 = bcrf_quote_record_payment($pdo, $p['id'], 'register', 10000, 'r-2026-000050', '', 1);
$ok((int)$one("SELECT sale_id FROM event_payments WHERE id = ?", [$pid1]) === 50 && $one("SELECT reference FROM event_payments WHERE id = ?", [$pid1]) === 'R-2026-000050', 'register payment linked to the sale (receipt number tidied)');
$throws(fn() => bcrf_quote_record_payment($pdo, $p['id'], 'register', 1000, 'R-2026-000050', '', 1), 'same receipt can\'t be used past its total', 'already used up');
$pid2 = bcrf_quote_record_payment($pdo, $p['id'], 'venmo', 4000, '@emily', 'rest by Venmo', 1);
$ps = bcrf_quote_status($pdo, $p);
$ok($ps['stage'] === 'paid' && $ps['paid'] === 14000 && $ps['balance'] === 0 && $ps['label'] === 'Paid in full', 'paid in full');
$throws(fn() => bcrf_quote_record_payment($pdo, $p['id'], 'cash', 100, '', '', 1), 'nothing left to pay', 'Nothing is left');

// -- Mistakes and refunds by hand --
$throws(fn() => bcrf_quote_void_payment($pdo, $pid2, '', 1), 'removing needs a reason', 'why');
bcrf_quote_void_payment($pdo, $pid2, 'typed twice', 1);
$ok(bcrf_quote_status($pdo, $p)['balance'] === 4000 && $one("SELECT void_reason FROM event_payments WHERE id = ?", [$pid2]) === 'typed twice', 'removed payment no longer counts, history kept');
$throws(fn() => bcrf_quote_void_payment($pdo, $pid2, 'again', 1), 'can\'t remove twice', 'already');
$rid = bcrf_quote_refund($pdo, $pid1, 2500, 'two kids were sick', 1);
$ok((int)$one("SELECT amount_cents FROM event_payments WHERE id = ?", [$rid]) === -2500 && $one("SELECT method FROM event_payments WHERE id = ?", [$rid]) === 'register', 'hand refund recorded against the payment');
$throws(fn() => bcrf_quote_refund($pdo, $pid1, 8000, 'too much', 1), 'refund more than what\'s left refused', 'Only $75.00');
$throws(fn() => bcrf_quote_refund($pdo, $pid1, 100, '', 1), 'refund needs a reason');
$throws(fn() => bcrf_quote_void_payment($pdo, $pid1, 'oops', 1), 'can\'t remove a payment that has refunds', 'refunds');
$ok(bcrf_quote_status($pdo, $p)['balance'] === 6500, 'balance after a refund');

// -- Group booking: per-guest price, deposit, online --
$b = bcrf_quote_create($pdo, 'booking', 10, 1);
$ok($b['total_cents'] === 14400 && $b['lines'][0]['qty'] === 24 && $b['lines'][0]['unit_cents'] === 600, 'booking starts from the type: 24 × $6');
$ok($b['pay_online'] === 1, 'bookings: online on by default');
$throws(fn() => bcrf_quote_save($pdo, $b['id'], ['lines' => [], 'deposit' => 0], 1), 'no lines refused', 'at least one');
$throws(fn() => bcrf_quote_clean_lines(['', 'Hayride'], ['', 'x'], ['', '5']), 'bad quantity refused', 'quantity');
$throws(fn() => bcrf_quote_clean_lines(['Kids'], ['3'], ['5.555']), 'bad price refused', 'price');
$throws(fn() => bcrf_quote_clean_lines([''], ['3'], ['5']), 'line without a description refused', 'description');
$lines = bcrf_quote_clean_lines(['Students', 'Chaperones', '', 'Feed cups'], ['20', '4', '', '20'], ['6', '0', '', '5.00']);
$ok(count($lines) === 3 && bcrf_quote_lines_total($lines) === 22000, 'clean lines: blanks dropped, total $220');
$throws(fn() => bcrf_quote_save($pdo, $b['id'], ['lines' => $lines, 'deposit' => 30000], 1), 'deposit over the total refused', 'more than the total');
$throws(fn() => bcrf_quote_save($pdo, $b['id'], ['lines' => $lines, 'deposit' => 5000, 'deposit_due' => '2027-02-30'], 1), 'bad date refused', 'real date');
$b = bcrf_quote_save($pdo, $b['id'], ['lines' => $lines, 'deposit' => 5000, 'deposit_due' => '2027-03-15', 'balance_when' => 'before', 'pay_online' => true, 'customer_note' => 'Bring <b>boots</b>'], 1);
$ok($b['total_cents'] === 22000 && $b['deposit_cents'] === 5000 && $b['deposit_due'] === '2027-03-15' && $b['balance_when'] === 'before' && $b['customer_note'] === 'Bring boots', 'saved: total, deposit, due date, before the day, note without tags');
$bs = bcrf_quote_status($pdo, $b);
$ok($bs['stage'] === 'draft', 'still a draft until sent');
bcrf_quote_mark_sent($pdo, $b, null, 1, 'test');
$b = bcrf_quote_by_id($pdo, $b['id']);
$bs = bcrf_quote_status($pdo, $b);
$ok($bs['stage'] === 'deposit_due' && $bs['deposit_left'] === 5000 && $bs['label'] === 'Deposit due $50.00', 'deposit due $50');
$ok(bcrf_quote_payable($pdo, $b) === ['deposit' => 5000, 'balance' => 22000], 'online: pay deposit or all');
$GLOBALS['mails'] = [];
bcrf_quote_email($pdo, $b, 1);
$bm = $GLOBALS['mails'][0][2] ?? '';
$ok(str_contains($bm, 'Deposit: $50.00') && str_contains($bm, 'March 15') && str_contains($bm, '30 or more days ahead') && str_contains($bm, 'pay online'), 'booking email: deposit, due date, refund policy, pay online');

// -- Stripe: a deposit paid online, recorded once however many times it arrives --
$GLOBALS['mails'] = [];
$ok(bcrf_quote_stripe_paid($pdo, $b['id'], 'cs_test_dep1', 5000, 'pi_1') === true, 'webhook records the deposit');
$ok(bcrf_quote_stripe_paid($pdo, $b['id'], 'cs_test_dep1', 5000, 'pi_1') === false, 'webhook retry does nothing');
$ok((int)$one("SELECT COUNT(*) FROM event_payments WHERE stripe_session_id = 'cs_test_dep1'") === 1, 'one ledger row for one checkout');
$ok(count($GLOBALS['mails']) === 2 && $GLOBALS['mails'][0][0] === 'teacher@example.test' && str_contains($GLOBALS['mails'][0][2], '$170.00'), 'receipt to the customer with what\'s left, plus a note to the farm');
$bs = bcrf_quote_status($pdo, $b);
$ok($bs['stage'] === 'balance_due' && $bs['balance'] === 17000 && $bs['label'] === '$170.00 due before the day', 'after the deposit: $170 due before the day');
$ok(bcrf_quote_payable($pdo, $b) === ['deposit' => 0, 'balance' => 17000], 'online: only the balance now');
$ok(bcrf_quote_stripe_paid($pdo, 999, 'cs_test_x', 100) === false, 'unknown quote ignored');
$ok(bcrf_quote_stripe_paid($pdo, $b['id'], '', 100) === false && bcrf_quote_stripe_paid($pdo, $b['id'], 'cs_zero', 0) === false, 'no session or no money ignored');
$throws(fn() => bcrf_quote_void_payment($pdo, (int)$one("SELECT id FROM event_payments WHERE stripe_session_id = 'cs_test_dep1'"), 'mistake', 1), 'online payments can\'t be removed', 'Refund');

// -- Stripe refund goes through the Stripe call (stand-in) --
$calls = [];
$stripeStub = function (string $sess, int $cents) use (&$calls) { $calls[] = [$sess, $cents]; return 're_test_' . count($calls); };
$dep = (int)$one("SELECT id FROM event_payments WHERE stripe_session_id = 'cs_test_dep1'");
bcrf_quote_refund($pdo, $dep, 2000, 'partial', 1, $stripeStub);
$ok($calls === [['cs_test_dep1', 2000]] && $one("SELECT stripe_refund_id FROM event_payments WHERE refund_of = ?", [$dep]) === 're_test_1', 'Stripe refund called once, refund id stored');
$failStub = function () { throw new RuntimeException('Stripe: card expired'); };
try { bcrf_quote_refund($pdo, $dep, 1000, 'again', 1, $failStub); $ok(false, 'failed Stripe refund throws'); }
catch (RuntimeException $e) { $ok(!($e instanceof BcrfQuoteError) && (int)$one("SELECT COUNT(*) FROM event_payments WHERE refund_of = ?", [$dep]) === 1, 'failed Stripe refund records nothing'); }
$throws(fn() => bcrf_quote_refund($pdo, $dep, 3001, 'too much', 1, $stripeStub), 'Stripe refund capped at what\'s left', 'Only $30.00');

// -- A booking paid in full with its original request: that money comes in with the quote --
$t = bcrf_quote_create($pdo, 'booking', 11, 1);
$ts = bcrf_quote_status($pdo, $t);
$ok($t['total_cents'] === 7500 && $ts['paid'] === 7500 && $ts['stage'] === 'paid', 'tour paid at request: quote starts paid in full');
$ok($one("SELECT stripe_session_id FROM event_payments WHERE quote_id = ?", [$t['id']]) === 'cs_test_tour', 'its Stripe checkout is on the ledger (so a refund works and the webhook can\'t add it twice)');
$ok(bcrf_quote_stripe_paid($pdo, $t['id'], 'cs_test_tour', 7500) === false, 'the old checkout can\'t be recorded twice');

// -- No price yet, no email, cancelled --
$z = bcrf_quote_create($pdo, 'booking', 12, 1);
$ok($z['lines'] === [] && $z['total_cents'] === 0 && bcrf_quote_status($pdo, $z)['stage'] === 'no_price', 'free type starts with no price');
$throws(fn() => bcrf_quote_email($pdo, $z, 1), 'no email address refused', 'no email');
$c = bcrf_quote_create($pdo, 'booking', 13, 1);
$c = bcrf_quote_save($pdo, $c['id'], ['lines' => [['label' => 'Visit', 'qty' => 8, 'unit_cents' => 600]], 'deposit' => 0, 'pay_online' => true], 1);
$ok(bcrf_quote_payable($pdo, $c) === ['deposit' => 0, 'balance' => 0], 'cancelled booking: nothing to pay online');
$throws(fn() => bcrf_quote_email($pdo, $c, 1), 'cancelled booking can\'t be sent', 'cancelled');
$throws(fn() => bcrf_quote_create($pdo, 'booking', 999, 1), 'missing booking refused', 'not found');
$ok(bcrf_quote_subject($pdo, 'nonsense', 1) === null, 'unknown kind');

// -- Over-paid after the price went down: refund owed --
$b = bcrf_quote_save($pdo, $b['id'], ['lines' => [['label' => 'Students', 'qty' => 2, 'unit_cents' => 600]], 'deposit' => 0, 'pay_online' => true], 1);
$bs = bcrf_quote_status($pdo, $b);
$ok($bs['stage'] === 'overpaid' && $bs['balance'] === -1800 && $bs['label'] === 'Refund owed $18.00', 'price cut below what was paid: refund owed');
$ok(bcrf_quote_payable($pdo, $b) === ['deposit' => 0, 'balance' => 0], 'nothing to pay when over-paid');

// -- List badges and audit --
$badges = bcrf_quote_badges($pdo, 'booking', [10, 11, 12, 99]);
$ok(isset($badges[10], $badges[11], $badges[12]) && !isset($badges[99]) && $badges[11]['status']['stage'] === 'paid', 'badges for a list of bookings');
$ok(bcrf_quote_badges($pdo, 'booking', []) === [], 'no ids, no query');
$ok((int)$one("SELECT COUNT(*) FROM audit_events WHERE object_type = 'event_inquiries' AND object_id = 2") >= 5, 'party money changes are in its audit history');
$ok((int)$one("SELECT COUNT(*) FROM audit_events WHERE object_label LIKE '%paid online%' AND actor_label = 'Stripe (automatic)'") === 1, 'online payment audited as Stripe (automatic)');
$ok((int)$one("SELECT COUNT(*) FROM domain_events WHERE type = 'event_payment.recorded'") >= 3, 'payment events for the queue');

// -- Two people recording the last of the balance at once: only one gets in --
$r = bcrf_quote_create($pdo, 'event_inquiry', 2, 1);   // existing party quote, balance $65
$pdo2 = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', $opts);
$pdo->beginTransaction();
bcrf_quote_by_id($pdo, $r['id'], true);   // first person holds the lock
$pdo2->exec("SET SESSION innodb_lock_wait_timeout = 1");
try { bcrf_quote_record_payment($pdo2, $r['id'], 'cash', 6500, '', '', 1); $ok(false, 'second writer waits for the lock'); }
catch (PDOException $e) { $ok(str_contains($e->getMessage(), 'Lock wait timeout'), 'second writer waits for the lock'); }
$pdo->rollBack();
bcrf_quote_record_payment($pdo2, $r['id'], 'cash', 6500, '', '', 1);
$throws(fn() => bcrf_quote_record_payment($pdo, $r['id'], 'cash', 6500, '', '', 1), 'then the first sees nothing left', 'Nothing is left');

echo $fails ? "$fails of $n checks FAILED\n" : "All $n checks passed\n";
exit($fails ? 1 : 0);
