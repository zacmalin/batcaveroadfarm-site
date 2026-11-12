<?php
// tests/crm_test.php — Farm OS Phase 3 (Nov 12, 2026): one customer record, households, organizations and merging
// (includes/crm.php, migration 0036) against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/crm_test.php
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
require $root . '/includes/crm.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (BcrfCrmError $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};

// -- Migration --
$ok(bcrf_crm_ready($pdo), 'CRM tables and columns exist');
$ok((int)$one("SELECT can_access FROM view_permissions WHERE role = 'moderator' AND view_name = 'customers'") === 1, 'Managers can open Customers');
$pdo->exec("UPDATE view_permissions SET can_access = 0 WHERE role = 'moderator' AND view_name = 'customers'");
(require $root . '/admin/migrations/0036_customer_links.php')($pdo);
$ok((int)$one("SELECT can_access FROM view_permissions WHERE role = 'moderator' AND view_name = 'customers'") === 0, 'repeat run keeps a permission choice');

// -- Helpers --
$ok(bcrf_crm_phone_key('(828) 555-0101') === '8285550101' && bcrf_crm_phone_key('+1 828 555 0101') === '8285550101' && bcrf_crm_phone_key('555-01') === '', 'phone key');
$ok(bcrf_crm_split_name('  Emily   Jenkins Smith ') === ['Emily', 'Jenkins Smith'] && bcrf_crm_split_name('') === ['Guest', ''], 'name split');
$ok(bcrf_crm_email(' Emily@X.Test ') === 'emily@x.test' && bcrf_crm_email('nope') === '', 'email clean');

// -- Fixtures --
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role, account_status) VALUES (1, 'Zac', 'Owner', 'o@x.test', 'administrator', 'active')");
$pdo->exec("INSERT INTO customers (id, first_name, last_name, email, email_verified_at, phone_number, password, source, created_at) VALUES
            (100, 'Emily', 'Jenkins', 'emily@x.test', NOW(), '828-555-0101', '\$2y\$hash', 'account', NOW())");
$pdo->exec("INSERT INTO booking_types (id, type_key, label, pricing_model, unit_price, requires_payment, is_active, sort_order) VALUES
            (2, 'school', 'School Field Trip', 'per_person', 6.00, 0, 1, 2)");
$pdo->exec("INSERT INTO bookings (id, type_id, customer_name, email, phone, party_size, payment_status, booking_date, start_time, end_time, status, token, date_flexible) VALUES
            (10, 2, 'Ms Teacher', 'TEACHER@school.test ', '828 555 0202', 25, 'waived', '2026-12-03', '10:00:00', '12:00:00', 'confirmed', 't10', 0),
            (11, 2, 'Ms Teacher', 'teacher@school.test', '', 30, 'waived', '2027-03-03', '10:00:00', '12:00:00', 'pending', 't11', 0)");
$pdo->exec("INSERT INTO event_inquiries (id, event_type, preferred_date, adult_count, child_count, customer_name, email, phone, status) VALUES
            (2, 'birthday', '2026-12-05', 6, 8, 'Emily Jenkins', 'Emily@x.test', '', 'confirmed'),
            (3, 'birthday', '2026-12-06', 2, 3, 'No Email', '', '', 'quoted'),
            (4, 'birthday', '2026-12-07', 2, 3, 'Em Jenkins', 'em.jenkins@other.test', '(828) 555-0101', 'new')");

// -- Sync --
$r = bcrf_crm_sync($pdo);
$ok($r['linked'] === 4 && $r['created'] === 2, 'sync linked 4 records and made 2 guests — got ' . json_encode($r));
$ok((int)$one("SELECT customer_id FROM event_inquiries WHERE id = 2") === 100, 'party linked to the existing account by email, any case');
$teacher = (int)$one("SELECT customer_id FROM bookings WHERE id = 10");
$ok($teacher > 0 && $teacher === (int)$one("SELECT customer_id FROM bookings WHERE id = 11"), 'both bookings with one email share one guest customer');
$t = bcrf_crm_get($pdo, $teacher);
$ok($t['source'] === 'guest' && $t['password'] === null && $t['email_verified_at'] === null && $t['first_name'] === 'Ms' && $t['email'] === 'teacher@school.test', 'guest customer: no password, not verified');
$ok($one("SELECT customer_id FROM event_inquiries WHERE id = 3") === null, 'no email: left unlinked');
$em2 = (int)$one("SELECT customer_id FROM event_inquiries WHERE id = 4");
$ok($em2 > 0 && $em2 !== 100, 'different email: different customer even with the same phone');
$r2 = bcrf_crm_sync($pdo);
$ok($r2 === ['linked' => 0, 'created' => 0], 'second sync does nothing');

// -- Search and profile --
$ok(array_column(bcrf_crm_search($pdo, 'jenkins'), 'id') == [100, $em2] || array_column(bcrf_crm_search($pdo, 'jenkins'), 'id') == [$em2, 100], 'search by name');
$ok(array_column(bcrf_crm_search($pdo, '555-0202'), 'id') === [$teacher], 'search by phone digits');
$ok(array_column(bcrf_crm_search($pdo, 'school.test'), 'id') === [$teacher], 'search by email');
$p = bcrf_crm_profile($pdo, $teacher);
$ok(count($p['bookings']) === 2 && !$p['parties'], 'profile shows the teacher\'s two bookings');
bcrf_crm_update($pdo, $teacher, ['first_name' => 'Jane', 'last_name' => 'Teacher', 'phone' => '828-555-0202', 'staff_notes' => 'Prefers mornings']);
$ok(bcrf_crm_get($pdo, $teacher)['staff_notes'] === 'Prefers mornings', 'notes saved');
$throws(fn() => bcrf_crm_update($pdo, $teacher, ['first_name' => ' ']), 'first name required', 'First name');

// -- Households --
$hid = bcrf_crm_household_create($pdo, 100, '', 1);
$ok($one("SELECT name FROM households WHERE id = ?", [$hid]) === 'Jenkins family', 'household named from the last name');
bcrf_crm_household_join($pdo, $em2, 100, 1);
$ok((int)$one("SELECT household_id FROM customers WHERE id = ?", [$em2]) === $hid, 'second adult joins the same household');
bcrf_crm_child_add($pdo, $hid, 'Ava', '5-8');
$throws(fn() => bcrf_crm_child_add($pdo, $hid, 'Ben', '40'), 'age range must be from the list', 'age');
$throws(fn() => bcrf_crm_child_add($pdo, $hid, '', '5-8'), 'child needs a first name', 'first name');
$p = bcrf_crm_profile($pdo, 100);
$ok(count($p['members']) === 2 && count($p['children']) === 1 && $p['children'][0]['age_range'] === '5-8', 'household shows two adults and a child');
$cols = array_column($pdo->query("SELECT column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'household_children'")->fetchAll(), 'c');
$ok(!array_intersect($cols, ['last_name', 'birth_date', 'email', 'dob']), 'children stored as first name and age range only');
$throws(fn() => bcrf_crm_household_join($pdo, 100, 100, 1), 'can\'t join yourself', 'someone else');

// -- Organizations --
$throws(fn() => bcrf_crm_org_save($pdo, null, ['name' => ''], 1), 'org name required', 'name');
$throws(fn() => bcrf_crm_org_save($pdo, null, ['name' => 'X', 'email' => 'bad'], 1), 'org email checked', 'email');
$org = bcrf_crm_org_save($pdo, null, ['name' => 'Old Fort Elementary', 'kind' => 'school', 'phone' => '828-555-0300'], 1);
$ok(bcrf_crm_org_get($pdo, $org)['kind'] === 'school', 'organization saved');
bcrf_crm_org_save($pdo, $org, ['name' => 'Old Fort Elementary School', 'kind' => 'nonsense'], 1);
$ok(bcrf_crm_org_get($pdo, $org)['kind'] === 'other' && bcrf_crm_org_get($pdo, $org)['name'] === 'Old Fort Elementary School', 'unknown kind falls back to other');
$c1 = bcrf_crm_org_contact_add($pdo, $org, 'teacher@school.test', '', 'booking');
$ok($c1 === $teacher, 'contact matched to the existing customer by email');
$c2 = bcrf_crm_org_contact_add($pdo, $org, 'office@school.test', 'Front Office', 'billing');
$ok($c2 > 0 && bcrf_crm_get($pdo, $c2)['source'] === 'guest', 'new contact email makes a guest customer');
bcrf_crm_org_contact_add($pdo, $org, 'office@school.test', '', 'viewer');
$ok((string)$one("SELECT role FROM organization_contacts WHERE organization_id = ? AND customer_id = ?", [$org, $c2]) === 'viewer' && count(bcrf_crm_org_contacts($pdo, $org)) === 2, 'adding again changes the role');
$throws(fn() => bcrf_crm_org_contact_add($pdo, $org, '', 'x', 'booking'), 'contact needs an email', 'email');
bcrf_crm_org_link($pdo, 'booking', 10, $org);
bcrf_crm_org_link($pdo, 'event_inquiry', 4, $org);
$recs = bcrf_crm_org_records($pdo, $org);
$ok(count($recs['bookings']) === 1 && count($recs['parties']) === 1, 'booking and party linked to the organization');
$list = bcrf_crm_orgs($pdo, 'fort');
$ok(count($list) === 1 && (int)$list[0]['n_bookings'] === 1 && (int)$list[0]['n_contacts'] === 2, 'org list counts');
bcrf_crm_org_link($pdo, 'booking', 10, 0);
$ok($one("SELECT organization_id FROM bookings WHERE id = 10") === null, 'unlink');
$throws(fn() => bcrf_crm_org_link($pdo, 'booking', 10, 9999), 'unknown organization refused', 'organization');
$p = bcrf_crm_profile($pdo, $teacher);
$ok(count($p['orgs']) === 1 && $p['orgs'][0]['role'] === 'booking', 'customer profile shows their organization');

// -- Duplicates --
$pdo->exec("INSERT INTO customers (id, first_name, last_name, email, phone_number, password, source, created_at) VALUES
            (200, 'Jane', 'Teacher', 'jane.t@home.test', NULL, NULL, 'register', NOW()),
            (201, 'Staffer', 'Person', 'staff@x.test', NULL, '\$2y\$h', 'account', NOW())");
$pdo->exec("UPDATE customers SET staff_user_id = 1 WHERE id = 201");
$d = bcrf_crm_duplicates($pdo);
$pairs = array_map(fn($x) => [(int)$x['a']['id'], (int)$x['b']['id'], $x['why']], $d);
$ok(in_array([100, $em2, 'same phone'], $pairs, true), 'same phone suggested — got ' . json_encode($pairs));
$ok((bool)array_filter($pairs, fn($x) => $x[0] === $teacher && $x[1] === 200 && $x[2] === 'same name'), 'same name suggested');

// -- Merge rules --
$keep = fn($id) => bcrf_crm_get($pdo, $id);
$ok(bcrf_crm_merge_blocker($pdo, $keep(200), $keep(200)) !== '', 'same record refused');
$ok(str_contains(bcrf_crm_merge_blocker($pdo, $keep(200), $keep(201)), 'staff'), 'staff record can\'t be the one removed');
$ok(str_contains(bcrf_crm_merge_blocker($pdo, $keep($em2), $keep(100)), 'website sign-in'), 'website account can\'t be the one removed');
$pdo->exec("INSERT INTO reward_members (customer_id, phone_key, enrolled_at) VALUES (200, '8285559999', UTC_TIMESTAMP())");
$ok(str_contains(bcrf_crm_merge_blocker($pdo, $keep($teacher), $keep(200)), 'Rewards'), 'rewards member can\'t be the one removed');
$pdo->exec("DELETE FROM reward_members WHERE customer_id = 200");

// -- Merge: Jane at home (200) into the teacher record --
$pdo->exec("INSERT INTO tickets (public_code, source, purchaser_name, purchaser_email, customer_id, quantity, quantity_redeemed, status, amount_cents, created_at, payment_status)
            VALUES ('TK1', 'online', 'Jane', 'jane.t@home.test', 200, 2, 0, 'active', 1600, NOW(), 'paid')");
$tk = (int)$pdo->lastInsertId();
$pdo->exec("UPDATE bookings SET customer_id = 200 WHERE id = 11");
$h2 = bcrf_crm_household_create($pdo, 200, 'Teacher family', 1);
bcrf_crm_org_contact_add($pdo, $org, 'jane.t@home.test', '', 'billing');   // already a contact through the other record
$mid = bcrf_crm_merge($pdo, $teacher, 200, 1);
$ok((int)$one("SELECT customer_id FROM tickets WHERE id = ?", [$tk]) === $teacher && (int)$one("SELECT customer_id FROM bookings WHERE id = 11") === $teacher, 'tickets and bookings moved to the kept record');
$gone = bcrf_crm_get($pdo, 200);
$ok((int)$gone['merged_into_id'] === $teacher && $gone['account_status'] === 'disabled' && $gone['household_id'] === null, 'removed record switched off and points at the kept one');
$ok((int)bcrf_crm_get($pdo, $teacher)['household_id'] === $h2, 'kept record takes the household it didn\'t have');
$ok((int)$one("SELECT COUNT(*) FROM organization_contacts WHERE organization_id = ? AND customer_id IN (?, 200)", [$org, $teacher]) === 1, 'no double contact after merge');
$ok(bcrf_crm_resolve($pdo, 200) === $teacher, 'resolve follows the merge');
$ok(bcrf_crm_find_or_create($pdo, 'jane.t@home.test') === $teacher, 'their old email now finds the kept record');
$ok(!in_array(200, array_column(bcrf_crm_search($pdo, 'jane'), 'id')), 'merged record hidden from search');
$ok(!array_filter(bcrf_crm_duplicates($pdo), fn($x) => (int)$x['b']['id'] === 200), 'merged record gone from duplicates');
$throws(fn() => bcrf_crm_merge($pdo, $teacher, 200, 1), 'merging twice refused', 'already');
$ok((int)$one("SELECT COUNT(*) FROM customer_merges") === 1, 'merge recorded');

// -- Undo --
bcrf_crm_merge_undo($pdo, $mid, 1);
$ok((int)$one("SELECT customer_id FROM tickets WHERE id = ?", [$tk]) === 200 && (int)$one("SELECT customer_id FROM bookings WHERE id = 11") === 200, 'undo moves the rows back');
$ok((int)$one("SELECT customer_id FROM bookings WHERE id = 10") === $teacher, 'undo leaves the kept record\'s own rows');
$back = bcrf_crm_get($pdo, 200);
$ok($back['merged_into_id'] === null && $back['account_status'] === 'active' && (int)$back['household_id'] === $h2, 'removed record is back as it was');
$ok(bcrf_crm_get($pdo, $teacher)['household_id'] === null, 'kept record gives back the household');
$ok((int)$one("SELECT COUNT(*) FROM organization_contacts WHERE organization_id = ? AND customer_id = 200", [$org]) === 1, 'dropped org contact restored');
$throws(fn() => bcrf_crm_merge_undo($pdo, $mid, 1), 'undo twice refused', 'already');

// -- Merge inside a transaction rolls back on a refusal --
$before = (int)$one("SELECT customer_id FROM bookings WHERE id = 11");
$throws(fn() => bcrf_crm_merge($pdo, 200, 201, 1), 'staff merge refused in the transaction', 'staff');
$ok((int)$one("SELECT customer_id FROM bookings WHERE id = 11") === $before && !$pdo->inTransaction(), 'nothing changed after a refusal');

echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
