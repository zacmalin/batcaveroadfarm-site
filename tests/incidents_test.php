<?php
// tests/incidents_test.php — Farm OS Phase 5 slice 8 (Nov 12, 2026): incidents (includes/incidents.php, migration 0047)
// against a real, THROWAWAY MySQL 8 database: reporting, checks, owner email, follow-up task, waiver / check-in / customer
// matching, changes kept in the history, closing, photos, who sees what.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/incidents_test.php
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
$_SESSION = ['user_id' => 3, 'user_role' => 'Staff'];
$upload = sys_get_temp_dir() . '/bcrf_incident_test_' . getmypid();
putenv('BCRF_UPLOAD_DIR=' . $upload);
$GLOBALS['bcrf_incidents_test_upload'] = true;

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }
function bcf_send_mail(string $to, string $subject, string $html, array $opts = []): bool { $GLOBALS['mails'][] = [$to, $subject, $html]; return empty($GLOBALS['mail_down']); }
function hasViewAccess($role, $view) { return $role === 'administrator' || ($role === 'moderator' && in_array($view, ['incidents', 'tasks', 'tasks_manage'], true)) || ($role === 'Staff' && $view === 'tasks'); }
require_once $root . '/includes/queue.php';
require_once $root . '/includes/tasks.php';
require_once $root . '/includes/animals.php';
require_once $root . '/includes/incidents.php';
$GLOBALS['mails'] = [];

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (BcrfIncidentError $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};
$now = bcrf_incident_now_local();
$today = $now->format('Y-m-d');
$earlier = $now->modify('-1 hour');
$base = ['type' => 'visitor_injury', 'injury' => 'bite', 'severity' => 'minor', 'date' => $earlier->format('Y-m-d'), 'time' => $earlier->format('H:i'),
         'location' => 'Goat Barn', 'description' => 'Hazel nipped a child\'s finger at the feeding rail.', 'action_taken' => 'Washed, bandage.',
         'person_name' => ['Mia Jenkins', '', ''], 'person_phone' => ['828-555-0101', '', ''], 'person_email' => ['', '', ''], 'person_note' => ['age 6', '', '']];

// -- Migration, permission --
$ok(bcrf_incidents_ready($pdo), 'tables exist');
(require $root . '/admin/migrations/0047_incidents.php')($pdo);
$ok((int)$one("SELECT can_access FROM view_permissions WHERE role = 'moderator' AND view_name = 'incidents'") === 1, 'Managers get the view, migration repeats safely');
$ok(!bcrf_incidents_can(), 'staff cannot see every incident');
$_SESSION['user_role'] = 'moderator'; $ok(bcrf_incidents_can(), 'managers can'); $_SESSION['user_role'] = 'Staff';

// -- People, animals --
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role, account_status) VALUES
            (1, 'Zac', 'Owner', 'zac@x.test', 'administrator', 'active'), (2, 'Casey', 'Owner', 'casey@x.test', 'administrator', 'active'),
            (3, 'Sam', 'Staff', 'sam@x.test', 'Staff', 'active'), (4, 'Old', 'Owner', 'old@x.test', 'administrator', 'disabled'),
            (5, 'Mo', 'Manager', 'mo@x.test', 'moderator', 'active')");
$pdo->exec("INSERT INTO classifications (class_name) VALUES ('Goat')");
$pdo->exec("INSERT INTO breeds (breed_name, slug) VALUES ('Nigerian Dwarf', 'nd')");
$pdo->exec("INSERT INTO animals (name, breed_id, classification_id, date_of_birth, is_active) VALUES ('Hazel', 1, 1, '2023-01-01', 1), ('Bramble', 1, 1, '2023-01-01', 1)");
[$hazel, $bramble] = array_map('intval', $pdo->query("SELECT id FROM animals ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$ok(count(bcrf_incident_owners($pdo)) === 2, 'owners: active administrators with email');

// -- Waiver, ticket check-in, customer for matching --
$utc = fn(DateTimeImmutable $d) => $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$signed = $utc($now->modify('-3 hours')); $exp = $utc($now->modify('+300 days'));
$pdo->prepare("INSERT INTO waivers (template_id, body_sha256, signer_name, signer_email, minors_json, typed_signature, via, event_inquiry_id, signed_at, expires_at)
               VALUES (1, REPEAT('a', 64), 'Emily Jenkins', 'emily@example.test', ?, 'Emily Jenkins', 'online', 2, ?, ?)")
    ->execute([json_encode([['name' => 'Mia Jenkins', 'age' => 6]]), $signed, $exp]);
$pdo->prepare("INSERT INTO waivers (template_id, body_sha256, signer_name, signer_email, typed_signature, via, signed_at, expires_at, voided_at)
               VALUES (1, REPEAT('a', 64), 'Void Person', 'void@example.test', 'Void Person', 'gate', ?, ?, ?)")->execute([$signed, $exp, $signed]);
$pdo->exec("INSERT INTO customers (id, first_name, last_name, email, password, created_at) VALUES (7, 'Emily', 'Jenkins', 'emily@example.test', '', NOW())");
$pdo->exec("INSERT INTO tickets (id, public_code, source, purchaser_name, purchaser_email, quantity, quantity_redeemed, status, expires_at, amount_cents, created_at, payment_status)
            VALUES (1, 'ABCDEFGH23', 'online', 'Emily Jenkins', 'emily@example.test', 2, 2, 'redeemed', DATE_ADD(NOW(), INTERVAL 300 DAY), 1600, NOW(), 'paid')");
$pdo->prepare("INSERT INTO check_ins (source_type, ticket_id, party_size, guests_counted, checked_in_at, expected_out_at, business_date) VALUES ('ticket', 1, 2, 2, ?, ?, ?)")
    ->execute([$utc($now->modify('-2 hours')), $utc($now), $today]);
$ciId = (int)$pdo->lastInsertId();

// -- Checks --
$throws(fn() => bcrf_incident_add($pdo, ['type' => 'nope'] + $base, [], 3), 'type needed', 'kind');
$throws(fn() => bcrf_incident_add($pdo, ['severity' => ''] + $base, [], 3), 'severity needed', 'serious');
$throws(fn() => bcrf_incident_add($pdo, ['location' => ' '] + $base, [], 3), 'where needed', 'where');
$throws(fn() => bcrf_incident_add($pdo, ['description' => ''] + $base, [], 3), 'what happened needed', 'what happened');
$throws(fn() => bcrf_incident_add($pdo, ['time' => '25:00'] + $base, [], 3), 'real time', 'time');
$throws(fn() => bcrf_incident_add($pdo, ['date' => $now->modify('+2 days')->format('Y-m-d')] + $base, [], 3), 'not in the future', 'future');
$throws(fn() => bcrf_incident_add($pdo, ['person_name' => ['', '', '']] + $base, [], 3), 'visitor injury needs the visitor', 'visitor');
$throws(fn() => bcrf_incident_add($pdo, ['person_name' => ['', '', ''], 'person_phone' => ['555-0101', '', '']] + $base, [], 3), 'contact needs a name', 'name');
$throws(fn() => bcrf_incident_add($pdo, ['person_email' => ['not-an-email', '', '']] + $base, [], 3), 'bad email', 'email');
$throws(fn() => bcrf_incident_add($pdo, ['type' => 'escape', 'person_name' => []] + $base, [], 3), 'escape needs an animal', 'animal');
$throws(fn() => bcrf_incident_add($pdo, ['animals' => [999]] + $base, [], 3), 'unknown animal', 'not found');
$ok((int)$one("SELECT COUNT(*) FROM incidents") === 0, 'nothing saved by refused reports');

// -- Report by a staff member --
$GLOBALS['mails'] = [];
$r = bcrf_incident_add($pdo, ['animals' => [$hazel]] + $base, [], 3);
$id = $r['id'];
$i = bcrf_incident_get($pdo, $id);
$ok($i['status'] === 'open' && $i['type'] === 'visitor_injury' && $i['injury'] === 'bite' && (int)$i['created_by'] === 3, 'report saved, open');
$ok($i['happened_at'] === $utc($earlier->setTime((int)$earlier->format('H'), (int)$earlier->format('i'))), 'time stored in UTC');
$ok(array_column($i['animals'], 'id') === [$hazel], 'animal linked');
$ok(count($i['people']) === 1 && $i['people'][0]['phone'] === '828-555-0101' && $i['people'][0]['note'] === 'age 6', 'visitor saved with contact');
$ok((int)$i['people'][0]['waiver_id'] === 1 && (int)$i['people'][0]['event_inquiry_id'] === 2, 'child matched to parent\'s waiver by name, with the party');
$ok((int)$i['people'][0]['customer_id'] === 7 && (int)$i['people'][0]['check_in_id'] === $ciId, 'waiver email finds customer and that day\'s check-in');
$ok($r['notified'] === 2 && count($GLOBALS['mails']) === 2 && $GLOBALS['mails'][0][0] === 'zac@x.test', 'both owners emailed');
$ok($one("SELECT owner_notified_at FROM incidents WHERE id = ?", [$id]) !== null, 'notified time stamped');
$ok(str_contains($GLOBALS['mails'][0][1], 'Visitor hurt (bite)') && str_contains($GLOBALS['mails'][0][2], 'incidents.php?id=' . $id), 'email names it and links to it');
$ok(!str_contains($GLOBALS['mails'][0][2], '828-555-0101'), 'email leaves out contact details');
$t = bcrf_task_get($pdo, (int)$r['task_id']);
$ok($t['assignee_role'] === 'administrator' && $t['priority'] === 'high' && $t['due_date'] === $today && $t['related_type'] === 'incident' && $t['related_id'] === (string)$id, 'follow-up task for owners today');
$ok((int)$one("SELECT task_id FROM incidents WHERE id = ?", [$id]) === (int)$r['task_id'], 'task linked on the report');
$ok(!$r['warnings'], 'no warnings');

// Serious → urgent; email by address; voided waiver doesn't count; mail down → queued
$GLOBALS['mails'] = []; $GLOBALS['mail_down'] = true;
$r2 = bcrf_incident_add($pdo, ['severity' => 'serious', 'person_name' => ['Emily Jenkins', 'Void Person', ''], 'person_email' => ['EMILY@example.test', 'void@example.test', ''], 'person_phone' => ['', '', '']] + $base, [], 3);
$GLOBALS['mail_down'] = false;
$i2 = bcrf_incident_get($pdo, $r2['id']);
$ok(bcrf_task_get($pdo, (int)$r2['task_id'])['priority'] === 'urgent', 'serious → urgent task');
$ok((int)$i2['people'][0]['waiver_id'] === 1 && $i2['people'][0]['email'] === 'emily@example.test', 'email match, stored lower case');
$ok($i2['people'][1]['waiver_id'] === null, 'voided waiver not matched');
$ok($r2['notified'] === 2 && (int)$one("SELECT COUNT(*) FROM queue_jobs WHERE job = 'email.send'") === 2, 'mail server down: queued instead');
$ok(str_starts_with($GLOBALS['mails'][0][1] ?? '', 'SERIOUS'), 'serious subject');

// No owners reachable → warning
$pdo->exec("UPDATE users SET account_status = 'disabled' WHERE role = 'administrator'");
$r3 = bcrf_incident_add($pdo, ['type' => 'escape', 'injury' => 'bite', 'animals' => [$hazel, $bramble], 'person_name' => []] + $base, [], 3);
$pdo->exec("UPDATE users SET account_status = 'active' WHERE id IN (1, 2)");
$ok($r3['notified'] === 0 && str_contains(implode(' ', $r3['warnings']), 'Call or text'), 'nobody emailed: warning says call');
$i3 = bcrf_incident_get($pdo, $r3['id']);
$ok($i3['injury'] === null && count($i3['animals']) === 2 && !$i3['people'], 'injury only for visitor injuries; escape without visitors');

// -- Who sees what --
$ok(bcrf_incident_can_see($i), 'reporter sees own report');
$_SESSION['user_id'] = 9; $ok(!bcrf_incident_can_see($i), 'other staff cannot');
$throws(fn() => bcrf_incident_update($pdo, $id, $base, 9), 'staff cannot change', 'Owners');
$throws(fn() => bcrf_incident_set_status($pdo, $id, 'closed', 'x', 9), 'staff cannot close', 'Owners');
$_SESSION = ['user_id' => 5, 'user_role' => 'moderator'];
$ok(bcrf_incident_can_see($i), 'manager sees all');
$ok(count(bcrf_incident_list($pdo)) === 3 && count(bcrf_incident_list($pdo, ['mine' => 3])) === 3 && count(bcrf_incident_list($pdo, ['mine' => 5])) === 0, 'list, mine');
$ok(count(bcrf_incident_list($pdo, ['animal' => $bramble])) === 1 && count(bcrf_incident_list($pdo, ['type' => 'escape'])) === 1, 'by animal, by type');
$ok(bcrf_incident_open_count($pdo) === 3, 'open count');

// -- Changes kept --
$ch = bcrf_incident_update($pdo, $id, ['severity' => 'moderate', 'description' => 'Hazel nipped a child\'s finger at the feeding rail. Skin broken.', 'animals' => [$hazel, $bramble]] + $base, 5);
$ok($ch === ['severity', 'description', 'animals'], 'changed fields reported');
$e = bcrf_incident_get($pdo, $id)['edits'];
$ok(count($e) === 3 && $e[0]['old_value'] === 'minor' && $e[0]['new_value'] === 'moderate' && (int)$e[0]['edited_by'] === 5, 'old and new value kept');
$ok($e[2]['old_value'] === 'Hazel' && $e[2]['new_value'] === 'Bramble, Hazel', 'animal change kept as names');
$ok(bcrf_incident_update($pdo, $id, ['severity' => 'moderate', 'description' => 'Hazel nipped a child\'s finger at the feeding rail. Skin broken.', 'animals' => [$bramble, $hazel]] + $base, 5) === [], 'same again: no change logged');
$ok(count(bcrf_incident_get($pdo, $id)['edits']) === 3, 'still three changes');
bcrf_incident_add_person($pdo, $id, ['name' => 'Emily Jenkins', 'email' => 'emily@example.test', 'phone' => '', 'note' => 'mother'], 5);
$i = bcrf_incident_get($pdo, $id);
$ok(count($i['people']) === 2 && (int)$i['people'][1]['waiver_id'] === 1 && end($i['edits'])['field'] === 'person', 'visitor added later, matched, logged');
$throws(fn() => bcrf_incident_add_person($pdo, $id, ['name' => ''], 5), 'added visitor needs a name', 'name');

// -- Photos --
$png = sys_get_temp_dir() . '/bcrf_inc_' . getmypid() . '.png';
$im = imagecreatetruecolor(2400, 1200); imagepng($im, $png);
$pid = bcrf_incident_photo_add($pdo, $id, ['tmp_name' => $png, 'error' => UPLOAD_ERR_OK, 'name' => 'a.png'], 5);
$p = bcrf_incident_photo_get($pdo, $pid);
$ok((int)$p['width'] === 1600 && (int)$p['height'] === 800 && is_file(bcrf_incident_photo_path($p)), 'photo shrunk and stored as WebP');
$ok(str_starts_with(bcrf_incident_photo_path($p), $upload . '/incidents/'), 'stored outside the site');
$ok(end(bcrf_incident_get($pdo, $id)['edits'])['field'] === 'photo', 'photo added later is logged');
$txt = sys_get_temp_dir() . '/bcrf_inc_' . getmypid() . '.txt'; file_put_contents($txt, 'hello');
$throws(fn() => bcrf_incident_photo_add($pdo, $id, ['tmp_name' => $txt, 'error' => UPLOAD_ERR_OK], 5), 'non-photo refused', 'not a photo');
$ok(bcrf_incident_photo_path(['file' => '../../etc/passwd']) === '', 'odd file names refused');
$files = bcrf_incident_files(['name' => ['a.png', ''], 'tmp_name' => [$png, ''], 'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE], 'size' => [1, 0]]);
$ok(count($files) === 1 && $files[0]['tmp_name'] === $png, 'multi-upload list, empty slots dropped');
$_SESSION = ['user_id' => 3, 'user_role' => 'Staff'];
$r4 = bcrf_incident_add($pdo, ['type' => 'property', 'injury' => '', 'person_name' => []] + $base, ['name' => ['a.png', 'b.txt'], 'tmp_name' => [$png, $txt], 'error' => [0, 0], 'size' => [1, 1]], 3);
$ok(count(bcrf_incident_get($pdo, $r4['id'])['photos']) === 1 && count($r4['warnings']) === 1 && str_contains($r4['warnings'][0], 'not a photo'), 'report saved with good photo; bad one is a warning');
$ok(count(bcrf_incident_get($pdo, $r4['id'])['edits']) === 0, 'photos on the first report are not logged as changes');

// -- Close and reopen --
$_SESSION = ['user_id' => 1, 'user_role' => 'administrator'];
$throws(fn() => bcrf_incident_set_status($pdo, $id, 'closed', '', 1), 'close needs a note', 'settled');
bcrf_incident_set_status($pdo, $id, 'closed', 'Called the family; healing fine.', 1);
$i = bcrf_incident_get($pdo, $id);
$ok($i['status'] === 'closed' && $i['close_note'] === 'Called the family; healing fine.' && $i['closed_by_name'] === 'Zac Owner', 'closed with note');
$throws(fn() => bcrf_incident_set_status($pdo, $id, 'closed', 'again', 1), 'already closed', 'already');
bcrf_incident_set_status($pdo, $id, 'open', 'Family called back', 1);
$i = bcrf_incident_get($pdo, $id);
$ok($i['status'] === 'open' && $i['closed_at'] === null && end($i['edits'])['new_value'] === 'reopened: Family called back', 'reopen logged');
$ok(bcrf_incident_line($i) === 'Visitor hurt (bite) · Needed care · Goat Barn', 'one-line summary');

// -- Never deleted: no delete function --
$ok(!function_exists('bcrf_incident_delete'), 'no delete');

@unlink($png); @unlink($txt);
exec('rm -rf ' . escapeshellarg($upload));
echo ($fails ? "$fails of $n checks FAILED\n" : "All $n checks passed\n");
exit($fails ? 1 : 0);
