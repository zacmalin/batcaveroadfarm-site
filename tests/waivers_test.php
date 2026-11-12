<?php
// tests/waivers_test.php — Farm OS Phase 3 (Nov 12, 2026): waivers (includes/waivers.php, migration 0039): versioned wording,
// publishing rules, signing, how long a signature lasts, voiding, the gate badge and the block-mode check, against a real,
// THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/waivers_test.php
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
require_once $root . '/includes/crm.php';
require_once $root . '/includes/waivers.php';
require_once $root . '/includes/party_emails.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (BcrfWaiverError $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};
$client = ['ip' => '203.0.113.9', 'ua' => 'test'];
$good = ['name' => 'Emily Jenkins', 'email' => 'Emily@Example.test', 'phone' => '828-555-0101', 'minors' => "Ava, 7\nBen 4 years\n\nCara", 'adult' => '1', 'agree' => '1', 'typed' => 'emily jenkins'];

// -- Migration --
$ok(bcrf_waivers_ready($pdo), 'tables exist');
(require $root . '/admin/migrations/0039_waivers.php')($pdo);
$ok((int)$one("SELECT COUNT(*) FROM waiver_templates") === 1, 'one placeholder draft, not duplicated on repeat');
$ok(bcrf_waiver_setting($pdo, 'waiver_mode', '') === 'off' && bcrf_waiver_valid_months($pdo) === 12, 'starts off, 12 months');

// -- Nothing published: nothing to sign, gate off --
$ok(bcrf_waiver_current($pdo) === null, 'no version in effect');
$throws(fn() => bcrf_waiver_sign($pdo, $good, 'online', null, $client), 'can\'t sign before publishing', 'aren\'t open');
bcrf_waiver_set_setting($pdo, 'waiver_mode', 'block');
$ok(bcrf_waiver_mode($pdo) === 'off', 'block mode stays off until a version is published');
$ok(bcrf_waiver_badge($pdo, 'emily@example.test') === null, 'no gate badge while off');
$ok(bcrf_waiver_gate_check($pdo, 'emily@example.test', '', false) === '', 'gate doesn\'t block while off');

// -- Publishing rules --
$throws(fn() => bcrf_waiver_publish($pdo, date('Y-m-d'), 'J. Doe, attorney', true, 1), 'placeholder can\'t be published', 'placeholder');
bcrf_waiver_draft_save($pdo, 'Release and waiver', "I understand animals can bite, kick and carry germs.\n\nI release Bat Cave Road Farm from claims.", 1);
$throws(fn() => bcrf_waiver_publish($pdo, date('Y-m-d'), 'J. Doe, attorney', false, 1), 'attorney box required', 'attorney');
$throws(fn() => bcrf_waiver_publish($pdo, date('Y-m-d'), '', true, 1), 'reviewer required', 'who reviewed');
$throws(fn() => bcrf_waiver_publish($pdo, date('Y-m-d', strtotime('-2 days')), 'J. Doe', true, 1), 'no past effective date', 'past');
$throws(fn() => bcrf_waiver_draft_save($pdo, '', 'x', 1), 'title required', 'title');
$v1 = bcrf_waiver_publish($pdo, date('Y-m-d'), 'J. Doe, attorney, Nov 2026', true, 1);
$t1 = bcrf_waiver_current($pdo);
$ok($v1 === 1 && $t1 && (int)$t1['version'] === 1, 'version 1 in effect today');
$ok(bcrf_waiver_mode($pdo) === 'block', 'block mode applies once published');
$draft = bcrf_waiver_draft($pdo);
$ok((int)$draft['id'] !== (int)$t1['id'] && $draft['published_at'] === null && $draft['body'] === $t1['body'], 'a new draft starts from the published text');
bcrf_waiver_draft_save($pdo, 'Release and waiver', $t1['body'] . "\n\nPhotos may be taken.", 1);
$ok(bcrf_waiver_current($pdo)['body'] === $t1['body'], 'editing the draft doesn\'t change the published version');

// -- Signing --
$throws(fn() => bcrf_waiver_sign($pdo, ['agree' => ''] + $good, 'online', null, $client), 'must agree', 'agree');
$throws(fn() => bcrf_waiver_sign($pdo, ['adult' => ''] + $good, 'online', null, $client), 'must be 18+', '18');
$throws(fn() => bcrf_waiver_sign($pdo, ['typed' => 'E J'] + $good, 'online', null, $client), 'typed name must match', 'same as above');
$throws(fn() => bcrf_waiver_sign($pdo, ['name' => 'Emily'] + $good, 'online', null, $client), 'first and last name', 'first and last');
$throws(fn() => bcrf_waiver_sign($pdo, ['email' => 'nope'] + $good, 'online', null, $client), 'email checked', 'email');
$throws(fn() => bcrf_waiver_sign($pdo, $good, 'gate', 7, $client), 'gate needs a drawn signature', 'finger');
$wid = bcrf_waiver_sign($pdo, $good, 'online', null, $client);
$w = bcrf_waiver_get($pdo, $wid);
$ok($w['signer_email'] === 'emily@example.test' && $w['signer_name'] === 'Emily Jenkins', 'email lower-cased, name kept');
$ok($w['minors'] === [['name' => 'Ava', 'age' => '7'], ['name' => 'Ben', 'age' => '4'], ['name' => 'Cara', 'age' => '']], 'children parsed with ages');
$ok((int)$w['template_id'] === (int)$t1['id'] && $w['body_sha256'] === hash('sha256', $t1['title'] . "\n\n" . $t1['body']), 'points to the exact version and its text hash');
$ok($w['via'] === 'online' && $w['staff_user_id'] === null && $w['ip'] === '203.0.113.9', 'how and from where');
$ok((int)$one("SELECT TIMESTAMPDIFF(MONTH, signed_at, expires_at) FROM waivers WHERE id = ?", [$wid]) === 12, 'good for 12 months');
$ok((int)$w['customer_id'] > 0 && $one("SELECT email FROM customers WHERE id = ?", [$w['customer_id']]) === 'emily@example.test', 'linked to a customer record');
$png = 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\n" . str_repeat("\0", 200));
$gid = bcrf_waiver_sign($pdo, ['email' => 'mark@example.test', 'name' => 'Mark Jenkins', 'typed' => 'Mark Jenkins', 'minors' => '', 'signature' => $png] + $good, 'gate', 7, $client);
$g = bcrf_waiver_get($pdo, $gid);
$ok($g['via'] === 'gate' && (int)$g['staff_user_id'] === 7 && $g['signature_png'] !== null && $g['minors'] === [], 'gate signature with finger signature and staff');
$ok(bcrf_waiver_clean_png('data:image/png;base64,' . base64_encode('not a png at all, just text that is long enough to pass the size check......................................')) === '', 'non-PNG signature rejected');

// -- Gate --
$b = bcrf_waiver_badge($pdo, 'EMILY@example.test', 'Emily Jenkins', 'warn');
$ok($b['signed'] && str_contains($b['label'], 'Emily Jenkins (+3 children)'), 'badge: signed, with children');
$b = bcrf_waiver_badge($pdo, 'new@example.test', 'New Person', 'block');
$ok(!$b['signed'] && $b['block'] && str_starts_with($b['sign_url'], 'waiver_sign.php?') && str_contains($b['sign_url'], 'email=new%40example.test'), 'badge: not signed, sign link prefilled');
$ok(bcrf_waiver_gate_check($pdo, 'emily@example.test', '', false) === '', 'block: signed party goes in');
$throws(fn() => bcrf_waiver_gate_check($pdo, 'new@example.test', '', true), 'block: unsigned party stopped', 'No waiver');
$throws(fn() => bcrf_waiver_gate_check($pdo, 'new@example.test', 'signed on paper', false), 'block: staff can\'t override', 'No waiver');
$ok(bcrf_waiver_gate_check($pdo, 'new@example.test', 'signed on paper', true) === 'signed on paper', 'block: manager lets them in with a reason');
bcrf_waiver_set_setting($pdo, 'waiver_mode', 'warn');
$ok(bcrf_waiver_gate_check($pdo, 'new@example.test', '', false) === '', 'warn: never stops anyone');

// -- Running out and voiding --
$pdo->prepare("UPDATE waivers SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE id = ?")->execute([$gid]);
$ok(bcrf_waiver_valid_for($pdo, 'mark@example.test') === null, 'expired signature doesn\'t count');
$throws(fn() => bcrf_waiver_void($pdo, $wid, '', 1), 'void needs a reason', 'why');
bcrf_waiver_void($pdo, $wid, 'Signed under the wrong email', 1);
$ok(bcrf_waiver_valid_for($pdo, 'emily@example.test') === null && (int)$one("SELECT COUNT(*) FROM waivers") === 2, 'voided doesn\'t count, but stays on file');
$throws(fn() => bcrf_waiver_void($pdo, $wid, 'again', 1), 'can\'t void twice', 'already');

// -- Search, customer list, new version --
$ok(count(bcrf_waiver_search($pdo, 'Ava')) === 1 && count(bcrf_waiver_search($pdo, 'jenkins')) === 2 && count(bcrf_waiver_search($pdo, '%')) === 0, 'search by child, name; % is literal');
$ok(count(bcrf_waiver_for_customer($pdo, (int)$w['customer_id'], 'emily@example.test')) === 1, 'customer\'s waivers');
$v2 = bcrf_waiver_publish($pdo, date('Y-m-d'), 'J. Doe, attorney, Dec 2026', true, 1);
$ok($v2 === 2 && (int)bcrf_waiver_current($pdo)['version'] === 2, 'version 2 takes over');
$ok((int)bcrf_waiver_get($pdo, $wid)['version'] === 1, 'old signature still points to version 1');
$v3 = bcrf_waiver_publish($pdo, date('Y-m-d', strtotime('+10 days')), 'J. Doe', true, 1);
$ok((int)bcrf_waiver_current($pdo)['version'] === 2, 'a future version waits for its date');
$ok(count(bcrf_waiver_versions($pdo)) === 3, 'all versions kept');

// -- Copy email and the party reminder link --
[$subj, $inner] = bcrf_waiver_copy_email(bcrf_waiver_get($pdo, $gid));
$ok(str_contains($subj, 'signed waiver') && str_contains($inner, 'I release Bat Cave Road Farm') && str_contains($inner, 'Mark Jenkins'), 'signer\'s copy has the text and signature');
$pdo->exec("INSERT INTO event_inquiries (event_type, preferred_date, adult_count, child_count, customer_name, email, status, created_at)
            VALUES ('birthday', CURDATE() + INTERVAL 1 DAY, 2, 3, 'Pat Lee', 'pat@example.test', 'confirmed', NOW())");
$pat = bcrf_party_get($pdo, (int)$pdo->lastInsertId());
$line = bcrf_party_waiver_line($pdo, $pat);
$ok(str_contains($line, '/waiver.php?email=pat%40example.test'), 'party reminder links to the waiver when not signed');
bcrf_waiver_sign($pdo, ['email' => 'pat@example.test', 'name' => 'Pat Lee', 'typed' => 'Pat Lee'] + $good, 'online', null, $client);
$ok(bcrf_party_waiver_line($pdo, $pat) === '', '… and not once they have signed');

echo ($fails ? "$fails of $n checks FAILED\n" : "All $n checks passed\n");
exit($fails ? 1 : 0);
