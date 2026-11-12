<?php
// tests/parties_test.php — Farm OS Phase 3 (Nov 12, 2026): party packages, guest lists and assigned staff (includes/parties.php,
// migration 0035) and how they reach quotes, capacity holds and the calendar, against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/parties_test.php
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
require_once $root . '/includes/parties.php';
require_once $root . '/includes/capacity_holds.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (BcrfPartyError $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};

// -- Migration and the starting package --
$ok(bcrf_party_ready($pdo), 'party tables exist');
$pk = bcrf_party_packages($pdo);
$ok(count($pk) === 1 && $pk[0]['name'] === 'Standard party', 'one starting package');
$std = $pk[0];
$ok($std['base_cents'] === 0 && $std['extra_guest_cents'] === 1000 && $std['duration_minutes'] === 120, 'starting package = today\'s $10 a guest, 2 hours');
$ok(bcrf_party_price_text($std) === '$10 a guest', 'price text, per guest');
(require $root . '/admin/migrations/0035_party_plans.php')($pdo);
$ok((int)$one("SELECT COUNT(*) FROM party_packages") === 1, 'repeat run adds no second package');

// -- Package rules --
$throws(fn() => bcrf_party_package_clean(['name' => '', 'extra' => '10', 'duration' => 120]), 'name required', 'name');
$throws(fn() => bcrf_party_package_clean(['name' => 'X', 'duration' => 120]), 'needs a price', 'price');
$throws(fn() => bcrf_party_package_clean(['name' => 'X', 'extra' => 'ten', 'duration' => 120]), 'price must be a number', 'amount');
$throws(fn() => bcrf_party_package_clean(['name' => 'X', 'extra' => '10', 'duration' => 5]), 'length at least 15 minutes', 'Length');
$throws(fn() => bcrf_party_package_clean(['name' => 'X', 'extra' => '10', 'included_guests' => 5, 'duration' => 60]), 'included guests need a package price', 'package price');
$deluxe = bcrf_party_package_save($pdo, null, ['name' => 'Deluxe', 'base' => '$150', 'included_guests' => '10', 'extra' => '12.50',
                                              'duration' => '180', 'inclusions' => "Pony ride\n\nHayride ", 'active' => '1', 'sort_order' => '2']);
$d = bcrf_party_package($pdo, $deluxe);
$ok($d['base_cents'] === 15000 && $d['extra_guest_cents'] === 1250 && $d['included_guests'] === 10 && $d['duration_minutes'] === 180, 'package saved in cents');
$ok($d['items'] === ['Pony ride', 'Hayride'], 'inclusions split into lines, blanks dropped');
$ok(bcrf_party_price_text($d) === '$150 for up to 10 guests, then $12.50 a guest', 'price text, package + extras');
$ok(bcrf_party_price_lines($d, 8) === [['label' => 'Deluxe (up to 10 guests)', 'qty' => 1, 'unit_cents' => 15000]], '8 guests fit in the package');
$ok(bcrf_party_price_lines($d, 14) === [['label' => 'Deluxe (up to 10 guests)', 'qty' => 1, 'unit_cents' => 15000],
                                        ['label' => 'Extra guests', 'qty' => 4, 'unit_cents' => 1250]], '14 guests = package + 4 extra');
$ok(bcrf_party_price_lines($std, 14) === [['label' => 'Standard party (per guest)', 'qty' => 14, 'unit_cents' => 1000]], 'per-guest package');
$flat = bcrf_party_package_save($pdo, null, ['name' => 'Buyout', 'base' => '500', 'duration' => '240', 'active' => '1']);
$ok(bcrf_party_price_text(bcrf_party_package($pdo, $flat)) === '$500', 'flat price text');
$ok(count(bcrf_party_price_lines(bcrf_party_package($pdo, $flat), 40)) === 1, 'flat price: one line whatever the headcount');
bcrf_party_package_save($pdo, $flat, ['name' => 'Buyout', 'base' => '500', 'duration' => '240']);   // active unticked
$ok(count(bcrf_party_packages($pdo)) === 2 && count(bcrf_party_packages($pdo, true)) === 3, 'turned-off package hidden from the pick list');

// -- Fixtures --
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role, account_status) VALUES
            (1, 'Zac', 'Owner', 'o@x.test', 'administrator', 'active'), (2, 'Sam', 'Staff', 's@x.test', 'Staff', 'active'),
            (3, 'Gone', 'Person', 'g@x.test', 'Staff', 'disabled'), (4, 'Cust', 'Omer', 'c@x.test', 'user', 'active')");
$pdo->exec("INSERT INTO event_inquiries (id, event_type, preferred_date, start_time, adult_count, child_count, customer_name, email, status) VALUES
            (2, 'birthday', '2026-12-05', '14:00:00', 6, 8, 'Emily Jenkins', 'e@x.test', 'confirmed'),
            (3, 'birthday', '2026-12-06', NULL, 2, 3, 'Other Party', 'm@x.test', 'quoted')");

// -- Package on a party --
bcrf_party_set_package($pdo, 2, $deluxe);
$ok((int)$one("SELECT package_id FROM event_inquiries WHERE id = 2") === $deluxe, 'package set on the party');
$throws(fn() => bcrf_party_set_package($pdo, 2, 9999), 'unknown package refused', 'Pick');
$throws(fn() => bcrf_party_set_package($pdo, 3, $flat), 'turned-off package refused for a new pick', 'turned off');
$ok(bcrf_party_length($pdo, bcrf_party_get($pdo, 2)) === 180, 'party length from its package');
$ok(bcrf_party_length($pdo, bcrf_party_get($pdo, 3)) === 120, 'no package: farm\'s party length');

// Quote starts from the package
$s = bcrf_quote_subject($pdo, 'event_inquiry', 2);
$ok(bcrf_quote_default_lines($pdo, $s) === bcrf_party_price_lines($d, 14), 'quote starts from the package price');
$ok(bcrf_quote_default_lines($pdo, bcrf_quote_subject($pdo, 'event_inquiry', 3))[0]['unit_cents'] === 1000, 'no package: $10 a guest as before');

// Capacity hold uses the package length
$holds = bcrf_holds_for_date($pdo, '2026-12-05');
$h2 = array_values(array_filter($holds, fn($h) => $h['type'] === 'event_inquiry' && $h['id'] === 2))[0] ?? null;
$ok($h2 && $h2['start_label'] === '2:00 PM' && $h2['end_label'] === '5:00 PM', 'hold runs for the package\'s 3 hours');

// -- Staff --
bcrf_party_staff_add($pdo, 2, 2, ' Animals ');
bcrf_party_staff_add($pdo, 2, 2, 'Lead');
$st = bcrf_party_staff($pdo, 2);
$ok(count($st) === 1 && $st[0]['job'] === 'Lead', 'adding twice updates the job');
$throws(fn() => bcrf_party_staff_add($pdo, 2, 3, ''), 'disabled account refused', 'staff list');
$throws(fn() => bcrf_party_staff_add($pdo, 2, 4, ''), 'customer refused', 'staff list');
$ok(bcrf_party_is_staff($pdo, 2, 2) && !bcrf_party_is_staff($pdo, 3, 2) && !bcrf_party_is_staff($pdo, 2, 0), 'is_staff');

// Calendar: Sam works party 2 without the Parties layer
$pdo->exec("INSERT INTO view_permissions (role, view_name, can_access) VALUES ('Staff', 'event_inquiries', 0) ON DUPLICATE KEY UPDATE can_access = 0");
$items = bcrf_cal_items($pdo, '2026-12-01', '2026-12-10', bcrf_cal_layers_for('Staff'), ['user_id' => 2, 'role' => 'Staff', 'manager' => false]);
$parties = array_values(array_filter($items, fn($i) => $i['layer'] === 'parties'));
$ok(count($parties) === 1 && $parties[0]['uid'] === 'party-2', 'assigned staff see their party, not others');
$ok($parties && $parties[0]['end'] === '2026-12-05 17:00:00', 'calendar uses the package length');
$ok($parties && $parties[0]['url'] === '/admin/party.php?id=2', 'calendar opens the party page');
$ok($parties && str_contains($parties[0]['note'], 'Deluxe') && str_contains($parties[0]['note'], 'Staff: Sam'), 'note shows package and staff');
$all = bcrf_cal_items($pdo, '2026-12-01', '2026-12-10', ['parties'], ['user_id' => 1, 'role' => 'administrator', 'manager' => true]);
$ok(count($all) === 2, 'the Parties layer still shows every party');
$none = bcrf_cal_items($pdo, '2026-12-01', '2026-12-10', ['tasks'], ['user_id' => 1, 'role' => 'administrator', 'manager' => true]);
$ok(!array_filter($none, fn($i) => $i['layer'] === 'parties'), 'not assigned and layer off: no parties');
// Phone feed with "only my tasks" still has the party they work
$pdo->exec("INSERT INTO calendar_feeds (user_id, token_hash, with_events, created_at) VALUES (2, REPEAT('a', 64), 0, UTC_TIMESTAMP())");
$owner = ['user_id' => 2, 'role' => 'Staff', 'first_name' => 'Sam', 'with_events' => 0];
$ics = bcrf_cal_feed_ics($pdo, $owner, '2026-12-01');
$ok(str_contains($ics, 'UID:party-2@') && !str_contains($ics, 'UID:party-3@'), 'phone feed has the assigned party only');
bcrf_party_staff_remove($pdo, 2, 2);
$ok(!bcrf_party_staff($pdo, 2), 'staff removed');
$items = bcrf_cal_items($pdo, '2026-12-01', '2026-12-10', bcrf_cal_layers_for('Staff'), ['user_id' => 2, 'role' => 'Staff', 'manager' => false]);
$ok(!array_filter($items, fn($i) => $i['layer'] === 'parties'), 'removed staff no longer see it');

// -- Guest list --
$throws(fn() => bcrf_party_guests_add($pdo, 2, " \n ", true), 'empty list refused', 'name');
$added = bcrf_party_guests_add($pdo, 2, "Ava, peanut allergy\n\nBen\r\nCara ,  ", true);
$ok($added === 3, 'three children added');
bcrf_party_guests_add($pdo, 2, "Emily Jenkins", false);
$g = bcrf_party_guests($pdo, 2);
$ok(count($g) === 4 && $g[0]['name'] === 'Emily Jenkins' && !$g[0]['is_child'], 'adults first, then children by name');
$ava = array_values(array_filter($g, fn($x) => $x['name'] === 'Ava'))[0];
$cara = array_values(array_filter($g, fn($x) => $x['name'] === 'Cara'))[0];
$ok($ava['note'] === 'peanut allergy' && $cara['note'] === null, 'note after the comma; blank note stays empty');
bcrf_party_guest_arrived($pdo, 2, (int)$ava['id'], true);
$t1 = $one("SELECT arrived_at FROM party_guests WHERE id = ?", [$ava['id']]);
bcrf_party_guest_arrived($pdo, 2, (int)$ava['id'], true);
$ok($t1 !== null && $one("SELECT arrived_at FROM party_guests WHERE id = ?", [$ava['id']]) === $t1, 'checking in twice keeps the first time');
$throws(fn() => bcrf_party_guest_arrived($pdo, 3, (int)$ava['id'], true), 'guest from another party refused', 'list');
$c = bcrf_party_guest_counts(bcrf_party_guests($pdo, 2));
$ok($c === ['children' => 3, 'adults' => 1, 'arrived' => 1, 'total' => 4], 'counts');
bcrf_party_guest_arrived($pdo, 2, (int)$ava['id'], false);
$ok($one("SELECT arrived_at FROM party_guests WHERE id = ?", [$ava['id']]) === null, 'undo check-in');
bcrf_party_guest_remove($pdo, 3, (int)$cara['id']);
$ok(count(bcrf_party_guests($pdo, 2)) === 4, 'remove ignores a guest from another party');
bcrf_party_guest_remove($pdo, 2, (int)$cara['id']);
$ok(count(bcrf_party_guests($pdo, 2)) === 3, 'guest removed');
$throws(fn() => bcrf_party_guests_add($pdo, 2, implode("\n", array_fill(0, 300, 'Kid')), true), 'list capped at 300', '300');

// -- Summaries for the Private Events list --
bcrf_party_staff_add($pdo, 2, 2, '');
$sum = bcrf_party_summaries($pdo, [2, 3, 0]);
$ok($sum[2]['package'] === 'Deluxe' && $sum[2]['guests'] === 3 && $sum[2]['staff'] === ['Sam'], 'summary for party 2');
$ok($sum[3] === ['package' => '', 'guests' => 0, 'arrived' => 0, 'staff' => []], 'empty summary for party 3');
$ok(bcrf_party_summaries($pdo, []) === [], 'no ids, no query');

echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
