<?php
// tests/record_tools_test.php — the "one admin" batch (Nov 14, 2026; includes/record_tools.php, migration 0067):
// dated notes (who can add / hide / bring back), record links and labels, the Changes panel diff, the tasks box,
// call/text/email/map buttons, CSV cells, vendor + animal papers with an expiry date (who sees them, "running out"),
// the new bell alerts (incident, low stock, applicant, vaccination due, paper running out) and search over notes and papers.
// Against a real, THROWAWAY MySQL 8 database built from every migration.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_rt_test DB_USER=... DB_PASS=... php tests/record_tools_test.php
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '',
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$GLOBALS['pdo'] = $pdo;
$root = dirname(__DIR__);
ini_set('log_errors', '0');
date_default_timezone_set('America/New_York');
$upload = sys_get_temp_dir() . '/bcrf_rt_test_' . getmypid();
putenv('BCRF_UPLOAD_DIR=' . $upload);
$GLOBALS['bcrf_docs_test_upload'] = true;

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }
(require $root . '/admin/migrations/0067_record_notes_papers.php')($pdo);   // safe to repeat

// Roles: Shopkeep = stock (inventory edit), moderator (Manager) = customers + parties + animals + tasks, Staff = animals only.
function hasTableAccess($role, $table, $level = 'view') {
    if ($role === 'administrator') return true;
    if ($role === 'Shopkeep') return $table === 'inventory';
    if ($role === 'moderator' || $role === 'Staff') return $table === 'animals';
    return false;
}
function hasViewAccess($role, $view) {
    if ($role === 'administrator') return true;
    if ($role === 'moderator') return in_array($view, ['customers', 'event_inquiries', 'tasks', 'tasks_manage', 'animal_expenses'], true);
    if ($role === 'Staff') return $view === 'tasks';
    return false;
}
require_once $root . '/includes/record_tools.php';
require_once $root . '/includes/documents.php';
require_once $root . '/includes/notifications.php';
require_once $root . '/includes/tasks.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (BcrfRecordError | BcrfDocError $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};
$as = function (string $role) { $_SESSION = ['user_id' => ['administrator' => 1, 'Shopkeep' => 2, 'moderator' => 3, 'Staff' => 4][$role], 'user_role' => $role]; };

// People and records
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role) VALUES (1, 'Zac', 'Owner', 'o@x.test', 'administrator'), (2, 'Sam', 'Shop', 's@x.test', 'Shopkeep'),
            (3, 'Mia', 'Manager', 'm@x.test', 'moderator'), (4, 'Tess', 'Staff', 't@x.test', 'Staff')");
$pdo->exec("INSERT INTO customers (id, first_name, last_name, email, phone_number) VALUES (10, 'Hazel', 'Jones', 'hazel@x.test', '(828) 555-0101')");
$pdo->exec("INSERT INTO vendors (id, name, email, phone, created_at) VALUES (20, 'Feed Barn', 'feed@x.test', '828-555-0102', NOW())");
$pdo->exec("INSERT INTO classifications (class_name) VALUES ('Goat')");
$pdo->exec("INSERT INTO breeds (breed_name, slug) VALUES ('Nigerian Dwarf', 'nd')");
$pdo->exec("INSERT INTO animals (id, name, breed_id, classification_id, date_of_birth, is_active) VALUES (30, 'Biscuit', 1, 1, '2023-01-01', 1)");
$pdo->exec("INSERT INTO event_inquiries (id, event_type, preferred_date, start_time, adult_count, child_count, customer_name, email, status) VALUES
            (40, 'birthday', '2026-12-05', '14:00:00', 4, 10, 'Emily Party', 'e@x.test', 'new')");

// ── Kinds, links, labels ─────────────────────────────────────────────────────────────────────────
$ok(bcrf_record_url($pdo, 'vendor', 20) === '/admin/vendors.php?id=20', 'vendor link');
$ok(bcrf_record_url($pdo, 'event_inquiry', 40) === '/admin/party.php?id=40', 'party link');
$ok(bcrf_record_url($pdo, 'vendor', 'x') === '', 'bad id → no link');
$ok(bcrf_record_label($pdo, 'vendor', 20) === 'Vendor: Feed Barn', 'vendor label ' . bcrf_record_label($pdo, 'vendor', 20));
$ok(bcrf_record_label($pdo, 'customer', 10) === 'Customer: Hazel Jones', 'customer label');
$ok(bcrf_record_label($pdo, 'animal', 999) === 'Animal #999', 'missing record label');
$ok(bcrf_record_can('vendor', 'Shopkeep') && !bcrf_record_can('customer', 'Shopkeep'), 'Shopkeep opens vendors, not customers');
$ok(bcrf_record_can('animal', 'Staff') && !bcrf_record_can('vendor', 'Staff'), 'Staff opens animals, not vendors');
$ok(bcrf_record_can('event_inquiry', 'administrator'), 'Owner opens everything');
$ok(bcrf_record_flash_key('/admin/vendors.php?id=20') === 'vend_flash' && bcrf_record_flash_key('/admin/animals.php?id=1&tab=papers') === 'an_flash', 'flash keys');
$ok(bcrf_record_safe_back('/admin/vendors.php?id=20') === '/admin/vendors.php?id=20', 'safe back keeps admin page');
$ok(bcrf_record_safe_back('https://evil.test/') === '' && bcrf_record_safe_back('//evil.test/admin/x.php') === '', 'safe back refuses other sites');
$ok(isset(bcrf_record_task_kinds()['event_inquiry'], bcrf_record_task_kinds()['incident']), 'task form lists parties and incidents');

// ── Contact buttons ──────────────────────────────────────────────────────────────────────────────
$btn = bcrf_contact_buttons_html('(828) 555-0101', 'hazel@x.test', 'Hazel', '123 Bat Cave Rd, Old Fort NC');
$ok(str_contains($btn, 'href="tel:+18285550101"') || str_contains($btn, 'href="tel:8285550101"'), 'Call button dials the number');
$ok(str_contains($btn, 'sms:'), 'Text button');
$ok(str_contains($btn, 'mailto:hazel@x.test'), 'Email button');
$ok(str_contains($btn, 'google.com/maps') && str_contains($btn, 'Bat+Cave') || str_contains($btn, 'Bat%20Cave'), 'Map button');
$ok(bcrf_contact_buttons_html('', '', 'x') === '', 'no phone/email → nothing');

// ── Dated notes ──────────────────────────────────────────────────────────────────────────────
$ok(bcrf_record_notes_ready($pdo), 'record_notes table ready (0067 ran twice)');
$as('Shopkeep');
$nid = bcrf_record_note_add($pdo, 'vendor', 20, "  Asked for new insurance cert\r\n", 2);
$ok($nid > 0 && $one("SELECT body FROM record_notes WHERE id = ?", [$nid]) === 'Asked for new insurance cert', 'note saved, trimmed');
$throws(fn() => bcrf_record_note_add($pdo, 'customer', 10, 'hi', 2), 'Shopkeep cannot note a customer', "can't");
$throws(fn() => bcrf_record_note_add($pdo, 'vendor', 999, 'hi', 2), 'missing record', 'not found');
$throws(fn() => bcrf_record_note_add($pdo, 'vendor', 20, '   ', 2), 'blank note', 'Type');
$throws(fn() => bcrf_record_note_add($pdo, 'vendor', 20, str_repeat('a', BCRF_NOTE_MAX + 1), 2), 'too long', 'up to');
$throws(fn() => bcrf_record_note_add($pdo, 'booking', 1, 'x', 2), 'no notes on bookings', "can't be added");
$ok(count(bcrf_record_notes($pdo, 'vendor', 20)) === 1, 'one note listed');
$html = bcrf_record_notes_box_html($pdo, 'vendor', 20, '/admin/vendors.php?id=20', 'tok', 'Old single note');
$ok(str_contains($html, 'Asked for new insurance cert') && str_contains($html, 'Sam Shop') && str_contains($html, 'Earlier note') && str_contains($html, '>Hide<'), 'box shows note, writer, earlier note, Hide');
$ok(str_contains($html, 'action="/admin/record_note.php"') && str_contains($html, 'value="tok"'), 'box posts to record_note.php with token');
// someone else can't hide it; Owner can
$as('Staff');
$throws(fn() => bcrf_record_note_hide($pdo, $nid, true, 4), 'Staff cannot touch vendor notes', "can't");
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role) VALUES (5, 'Other', 'Shop', 'o2@x.test', 'Shopkeep')");
$_SESSION = ['user_id' => 5, 'user_role' => 'Shopkeep'];
$throws(fn() => bcrf_record_note_hide($pdo, $nid, true, 5), 'other Shopkeep cannot hide it', 'Only the person');
$ok(!str_contains(bcrf_record_notes_box_html($pdo, 'vendor', 20, '/admin/vendors.php?id=20', 'tok'), '>Hide<'), 'no Hide button for others');
$as('Shopkeep');
bcrf_record_note_hide($pdo, $nid, true, 2);
$ok(count(bcrf_record_notes($pdo, 'vendor', 20)) === 0 && count(bcrf_record_notes($pdo, 'vendor', 20, true)) === 1, 'writer hid it, still kept');
$ok(str_contains(bcrf_record_notes_box_html($pdo, 'vendor', 20, '/admin/vendors.php?id=20', 'tok'), 'Bring back'), 'writer sees Bring back');
$_SESSION = ['user_id' => 5, 'user_role' => 'Shopkeep'];
$ok(!str_contains(bcrf_record_notes_box_html($pdo, 'vendor', 20, '/admin/vendors.php?id=20', 'tok'), 'Asked for new insurance'), 'hidden note not shown to others');
$as('Shopkeep');
bcrf_record_note_hide($pdo, $nid, false, 2);
$ok(count(bcrf_record_notes($pdo, 'vendor', 20)) === 1, 'brought back');
// a day later only an Owner
$pdo->exec("UPDATE record_notes SET created_at = UTC_TIMESTAMP() - INTERVAL 2 DAY WHERE id = $nid");
$throws(fn() => bcrf_record_note_hide($pdo, $nid, true, 2), 'writer cannot hide after a day', 'Only the person');
$as('administrator');
bcrf_record_note_hide($pdo, $nid, true, 1);
$ok($one("SELECT deleted_by FROM record_notes WHERE id = ?", [$nid]) == 1, 'Owner hid an old note');
bcrf_record_note_hide($pdo, $nid, false, 1);
$as('moderator');
$cn = bcrf_record_note_add($pdo, 'customer', 10, 'Prefers text over calls', 3);
$pn = bcrf_record_note_add($pdo, 'event_inquiry', 40, 'Wants the goat yoga add-on', 3);
$an = bcrf_record_note_add($pdo, 'animal', 30, 'Limping on left fore, watch', 3);
$ok($cn && $pn && $an, 'Manager notes customers, parties, animals');

// ── Changes panel ────────────────────────────────────────────────────────────────────────────────
$d = bcrf_record_change_diff('{"name":"Feed Barn","phone":"1","updated_at":"x","price":"4.00","password":"a"}', '{"name":"Feed Barn","phone":"2","updated_at":"y","price":"4","password":"b"}');
$ok($d === ['phone: 1 → 2'], 'diff keeps real changes, skips timestamps / passwords / 4.00=4 ' . json_encode($d));
$ok(bcrf_record_change_diff(null, '{"name":"New"}') === ['name: (blank) → New'], 'diff on create');
$ok(bcrf_record_changes_html($pdo, 'vendor', 20) === '', 'no log → no panel');
$pdo->prepare("INSERT INTO audit_events (occurred_at, actor_type, actor_label, action, object_type, object_id, object_label, before_json, after_json)
               VALUES (UTC_TIMESTAMP(), 'user', 'Sam Shop', 'update', 'vendors', '20', 'Feed Barn', ?, ?)")->execute(['{"phone":"828-555-0100"}', '{"phone":"828-555-0102"}']);
$pdo->exec("INSERT INTO audit_events (occurred_at, actor_type, actor_label, action, object_type, object_id, object_label) VALUES (UTC_TIMESTAMP(), 'user', 'X', 'update', 'vendors', '21', 'Other')");
$ch = bcrf_record_changes_html($pdo, 'vendor', 20);
$ok(str_contains($ch, 'id="changes"') && str_contains($ch, 'Sam Shop') && str_contains($ch, 'phone: 828-555-0100 → 828-555-0102') && !str_contains($ch, 'Other'), 'panel shows this vendor\'s change only');

// ── Tasks box ────────────────────────────────────────────────────────────────────────────────────
$pdo->exec("INSERT INTO tasks (title, status, due_date, related_type, related_id, created_at) VALUES
            ('Call Feed Barn about hay', 'open', CURDATE() - INTERVAL 3 DAY, 'vendor', '20', NOW()),
            ('Old done one', 'done', CURDATE(), 'vendor', '20', NOW()),
            ('Other vendor', 'open', NULL, 'vendor', '21', NOW())");
$as('moderator');
$tb = bcrf_record_tasks_box_html($pdo, 'vendor', 20);
$ok(str_contains($tb, 'Call Feed Barn about hay') && !str_contains($tb, 'Other vendor') && !str_contains($tb, 'Old done one'), 'tasks box lists this record\'s open tasks');
$ok(str_contains($tb, 'tasks.php?new=1&amp;related=vendor%3A20') || str_contains($tb, 'related=vendor:20'), 'Add a task link prefilled');
$ok(str_contains($tb, 'text-red'), 'late task in red');
$as('Shopkeep');
$ok(bcrf_record_tasks_box_html($pdo, 'vendor', 20) === '', 'no tasks view → no box');
$ok(count(bcrf_tasks_list($pdo, ['related' => 'event_inquiry:40'])) === 0, 'tasks list accepts event_inquiry:N filter');

// ── CSV ──────────────────────────────────────────────────────────────────────────────────────────
$ok(bcrf_csv_cell('=HYPERLINK("x")') === "'=HYPERLINK(\"x\")", 'formula guarded');
$ok(bcrf_csv_cell('+1 828') === "'+1 828" && bcrf_csv_cell('@sum') === "'@sum", 'plus / at guarded');
$ok(bcrf_csv_cell('-12.50') === '-12.50' && bcrf_csv_cell(5) === 5, 'numbers left alone');
$ok(bcrf_csv_cell(null) === '' && bcrf_csv_money(1250) === '12.50' && bcrf_csv_money(null) === '', 'blank and money cells');
$ok(str_contains(bcrf_csv_link_html('tasks', ['related' => 'vendor:20', 'x' => '']), '/admin/export.php?what=tasks&amp;related=vendor%3A20'), 'CSV link keeps filters, drops blanks');
$exp = file_get_contents($root . '/admin/export.php');
foreach (['customers', 'documents', 'parties', 'bookings', 'waivers', 'incidents', 'tasks'] as $w) $ok(str_contains($exp, "'$w'"), "export.php handles $w");

// ── Papers with an expiry ────────────────────────────────────────────────────────────────────────
$ok(bcrf_docs_expiry_ready($pdo), 'purchase_docs.expires_on added');
$tmp = sys_get_temp_dir() . '/bcrf_rt_src_' . getmypid(); @mkdir($tmp);
$pdf = function (string $name) use ($tmp) { $p = $tmp . '/' . bin2hex(random_bytes(4)) . '.pdf'; file_put_contents($p, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n" . random_bytes(8));
                                             return ['name' => $name, 'type' => 'x', 'tmp_name' => $p, 'error' => UPLOAD_ERR_OK, 'size' => filesize($p)]; };
$today = bcrf_doc_today();
$soon = (new DateTimeImmutable($today))->modify('+10 days')->format('Y-m-d');
$far = (new DateTimeImmutable($today))->modify('+200 days')->format('Y-m-d');
$as('Shopkeep');
$ins = bcrf_doc_create($pdo, ['doc_type' => 'insurance', 'link' => 'vendor:20', 'expires_on' => $soon], [$pdf('coi.pdf')], 2);
$insId = (int)($ins['id'] ?? $ins[0] ?? 0);
$ok($insId > 0 && $one("SELECT expires_on FROM purchase_docs WHERE id = ?", [$insId]) === $soon, 'vendor insurance saved with expiry');
$ok($one("SELECT link_type FROM purchase_docs WHERE id = ?", [$insId]) === 'vendor', 'linked to the vendor');
$throws(fn() => bcrf_doc_create($pdo, ['doc_type' => 'vet', 'link' => 'animal:30'], [$pdf('vet.pdf')], 2), 'Shopkeep cannot add animal papers');
$as('Staff');
$vet = bcrf_doc_create($pdo, ['doc_type' => 'vet', 'link' => 'animal:30', 'expires_on' => $far], [$pdf('rabies.pdf')], 4);
$vetId = (int)($vet['id'] ?? $vet[0] ?? 0);
$ok($vetId > 0, 'Staff with animals adds a vet paper');
$ok(bcrf_doc_can_see(bcrf_doc_get($pdo, $vetId)) && !bcrf_doc_can_see(bcrf_doc_get($pdo, $insId)), 'Staff sees animal papers, not vendor papers');
$as('Shopkeep');
$ok(bcrf_doc_can_see(bcrf_doc_get($pdo, $insId)) && !bcrf_doc_can_see(bcrf_doc_get($pdo, $vetId)), 'Shopkeep sees vendor papers, not animal papers');
$ok(count(bcrf_doc_for($pdo, 'vendor', 20)) === 1 && count(bcrf_doc_for($pdo, 'animal', 30)) === 0, 'Shopkeep: vendor papers listed, animal papers hidden');
$as('administrator');
$ok(count(bcrf_doc_for($pdo, 'animal', 30)) === 1, 'Owner: animal paper listed on the animal');
$as('Shopkeep');
[$xt, $xsoon] = bcrf_doc_expiry(bcrf_doc_get($pdo, $insId));
$ok(str_starts_with($xt, 'expires ') && $xsoon, 'insurance flagged as running out');
[$xt2, $xsoon2] = bcrf_doc_expiry(['expires_on' => $far]);
$ok(!$xsoon2, 'far date not flagged');
$ok(bcrf_doc_expiry(['expires_on' => '2020-01-01'], $today)[0] === 'expired Jan 1, 2020', 'past date says expired');
$exp = array_column(bcrf_doc_expiring($pdo), 'id');
$ok(in_array($insId, $exp) && !in_array($vetId, $exp), 'running-out list: insurance yes, vet paper no');
$ok(str_contains(bcrf_doc_link_url('animal', 30), 'tab=papers'), 'animal paper links to the Papers tab');
$box = bcrf_doc_box_html($pdo, 'vendor', 20, '/admin/vendors.php?id=20', 'tok');
$ok(str_contains($box, 'Papers') && str_contains($box, 'expires_on') && str_contains($box, 'Insurance'), 'vendor box: Papers, type, Expires');

// ── Bell ─────────────────────────────────────────────────────────────────────────────────────
$pdo->exec("INSERT INTO incidents (happened_at, type, severity, location, description, created_at) VALUES (NOW(), 'visitor_injury', 'minor', 'Goat pen', 'Scraped knee', UTC_TIMESTAMP())");
$pdo->exec("INSERT INTO inventory (category, name, is_active, track_inventory, stock_quantity, min_stock_level) VALUES ('Zoo', 'Goat feed cups', 1, 1, 2, 5)");
$pdo->exec("INSERT INTO animal_vaccinations (animal_id, vaccine, given_on, next_due, created_at) VALUES (30, 'CDT', '2025-11-20', CURDATE() + INTERVAL 5 DAY, NOW())");
try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("INSERT INTO applications (job_cycle_id, job_id, first_name, last_name, email, age_bracket, status, is_spam, submitted_at, created_at)
                VALUES (1, 1, 'Ada', 'Applicant', 'ada@x.test', '18_plus', 'new', 0, NOW(), NOW())");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
} catch (Throwable $e) { echo "note: applicant insert skipped: " . $e->getMessage() . "\n"; }
bcrf_notify_sweep($pdo);
$kinds = $pdo->query("SELECT kind FROM notifications")->fetchAll(PDO::FETCH_COLUMN);
foreach (['incident', 'low_stock', 'vaccine_due', 'paper_expiring', 'applicant'] as $k) $ok(in_array($k, $kinds, true), "bell: $k " . json_encode($kinds));
$ok(bcrf_notify_sweep($pdo) === 0, 'second sweep adds nothing');
$ok((int)$one("SELECT COUNT(*) FROM notifications WHERE kind = 'paper_expiring' AND link = ?", ['/admin/documents.php?id=' . $insId]) === 1, 'paper alert links to the paper');
$ok((string)$one("SELECT title FROM notifications WHERE kind = 'paper_expiring'") === 'Insurance certificate for Feed Barn runs out soon', 'paper alert title: ' . $one("SELECT title FROM notifications WHERE kind = 'paper_expiring'"));
$ok(str_contains((string)$one("SELECT title FROM notifications WHERE kind = 'vaccine_due'"), 'Biscuit: CDT due soon'), 'vaccine alert title');

// ── Search ───────────────────────────────────────────────────────────────────────────────────
$run = function (string $role, string $q) use ($pdo) {
    $r = bcrf_search($pdo, $q, bcrf_search_can_for($role), ['id' => 0, 'role' => $role]);
    $out = [];
    foreach ($r['groups'] as $g) foreach ($g['rows'] as $row) $out[$g['key']][] = $row;
    return $out;
};
$s = $run('moderator', 'goat yoga');
$ok(isset($s['notes']) && $s['notes'][0]['url'] === '/admin/party.php?id=40#notes' && $s['notes'][0]['title'] === 'Party: Emily Party', 'Manager finds a party note');
$ok(!isset($run('Shopkeep', 'goat yoga')['notes']), 'Shopkeep cannot find party notes');
$ok(isset($run('Shopkeep', 'insurance cert')['notes']), 'Shopkeep finds vendor notes');
$pdo->exec("UPDATE record_notes SET deleted_at = UTC_TIMESTAMP() WHERE id = $pn");
$ok(!isset($run('moderator', 'goat yoga')['notes']), 'hidden notes not found');
$ok(isset($run('Staff', 'rabies')['documents']) && !isset($run('Staff', 'coi')['documents']), 'Staff finds vet paper by file name, not vendor insurance');
$ok(isset($run('Shopkeep', 'coi')['documents']) && !isset($run('Shopkeep', 'rabies')['documents']), 'Shopkeep finds insurance, not vet paper');
$ok(isset($run('administrator', 'rabies')['documents']) && isset($run('administrator', 'coi')['documents']), 'Owner finds both');

// tidy
array_map('unlink', glob($tmp . '/*') ?: []); @rmdir($tmp);
exec('rm -rf ' . escapeshellarg($upload));
echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
