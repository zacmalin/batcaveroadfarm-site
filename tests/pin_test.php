<?php
// tests/pin_test.php — PIN sign-in on shared devices and manager approval by PIN (includes/pin_auth.php, migration 0058,
// Nov 13, 2026) against a real, THROWAWAY MySQL 8 database: PIN rules, lockout, who can sign in or approve, devices,
// the PIN session's role, and the manager checks on the register and gate passing only with a manager's PIN.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/pin_test.php
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

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }
require_once $root . '/includes/pin_auth.php';
require_once $root . '/includes/admission.php';
require_once $root . '/includes/refunds.php';
require_once $root . '/includes/drawers.php';
require_once $root . '/includes/receipts.php';
require_once $root . '/includes/waivers.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$_SESSION = [];

$ok(bcrf_pin_ready($pdo), 'migration 0058 adds the PIN columns and staff_devices');

// -- PIN rules --
foreach (['1234' => false, '9876' => false, '0000' => false, '123' => false, '123456789' => false, '12a4' => false,
          '4821' => true, '13579' => true, '00112233' => true] as $pin => $good) {
    $ok((bcrf_pin_problem((string)$pin) === null) === $good, "PIN rule for $pin");
}

// -- People --
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$pdo->exec('DELETE FROM users');
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, password, role, account_status) VALUES
    (1, 'Zac', 'Owner', 'z@example.test', '', 'administrator', 'active'),
    (2, 'Casey', 'Manager', 'c@example.test', '', 'moderator', 'active'),
    (3, 'Sam', 'Shop', 's@example.test', '', 'Shopkeep', 'active'),
    (4, 'Sam', 'Other', 's2@example.test', '', 'Shopkeep', 'active'),
    (5, 'Mia', 'Media', 'm@example.test', '', 'Media', 'active'),
    (6, 'Gone', 'Person', 'g@example.test', '', 'Shopkeep', 'disabled')");
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
foreach ([1 => '4821', 2 => '5937', 3 => '2468', 5 => '1357', 6 => '8642'] as $id => $pin) bcrf_pin_set($pdo, $id, $pin);
$threw = false; try { bcrf_pin_set($pdo, 4, '1111'); } catch (InvalidArgumentException $e) { $threw = true; }
$ok($threw, 'a weak PIN is refused when saving');
bcrf_pin_set($pdo, 4, '7531');

$people = bcrf_pin_people($pdo);
$ok(array_column($people, 'id') === [2, 4, 3, 1], 'sign-in list: Owner, Manager, Shopkeep with a PIN, by first name; not Media or disabled');
$names = array_column($people, 'name', 'id');
$ok($names[3] === 'Sam S.' && $names[4] === 'Sam O.' && $names[2] === 'Casey', 'two people with the same first name get a last initial');
$ok(array_column(bcrf_pin_people($pdo, true), 'id') === [2, 1], 'approver list: Owner and Manager only');

// -- Checking PINs and the lockout --
$ok(bcrf_pin_check($pdo, 3, '2468')['ok'], 'right PIN works');
$ok(!bcrf_pin_check($pdo, 5, '1357')['ok'], 'Media cannot sign in by PIN');
$ok(!bcrf_pin_check($pdo, 6, '8642')['ok'], 'a disabled account cannot sign in by PIN');
$ok(!bcrf_pin_check($pdo, 3, '2468', bcrf_pin_manager_roles())['ok'], 'a Shopkeep PIN cannot approve');
$ok(bcrf_pin_check($pdo, 2, '5937', bcrf_pin_manager_roles())['ok'], 'a Manager PIN can approve');
for ($i = 1; $i <= 4; $i++) $r = bcrf_pin_check($pdo, 3, '0000');
$ok(!$r['ok'] && str_contains($r['message'], '1 more try'), 'warning before the lock');
$r = bcrf_pin_check($pdo, 3, '0000');
$ok(!$r['ok'] && str_contains($r['message'], 'locked for 15 minutes'), 'fifth wrong PIN locks it');
$r = bcrf_pin_check($pdo, 3, '2468');
$ok(!$r['ok'] && str_contains($r['message'], 'Too many wrong PINs'), 'locked PIN refuses even the right PIN');
$pdo->exec("UPDATE users SET pin_locked_until = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = 3");
$ok(bcrf_pin_check($pdo, 3, '2468')['ok'], 'works again after the lock runs out');
$ok((int)$pdo->query("SELECT pin_failed FROM users WHERE id = 3")->fetchColumn() === 0 && $pdo->query("SELECT pin_locked_until FROM users WHERE id = 3")->fetchColumn() === null, 'a right PIN clears the count and the lock');
bcrf_pin_check($pdo, 2, '1111');
bcrf_pin_check($pdo, 2, '5937');
$ok((int)$pdo->query("SELECT pin_failed FROM users WHERE id = 2")->fetchColumn() === 0, 'wrong-try count resets after a right PIN');
$ok(!str_contains((string)json_encode(bcrf_pin_check($pdo, 2, '5937')), 'pin_hash'), 'the PIN hash never leaves bcrf_pin_check');

// -- Devices --
$_COOKIE = [];
$ok(bcrf_pin_device($pdo) === null, 'no cookie: not a set-up device');
$id = bcrf_pin_device_create($pdo, ' Gate tablet <b> ', 1);
$d = bcrf_pin_device($pdo);
$ok($d && $d['id'] === $id && $d['name'] === 'Gate tablet', 'set up a device; it is found by its cookie (tags stripped)');
$ok(strlen((string)$pdo->query("SELECT token_hash FROM staff_devices WHERE id = $id")->fetchColumn()) === 64
    && $pdo->query("SELECT token_hash FROM staff_devices WHERE id = $id")->fetchColumn() !== $_COOKIE[BCRF_PIN_COOKIE], 'only a hash of the device cookie is stored');
$_COOKIE[BCRF_PIN_COOKIE] = str_repeat('a', 64);
$ok(bcrf_pin_device($pdo) === null, 'a made-up cookie is not a device');
$tok2 = null;
$id2 = bcrf_pin_device_create($pdo, 'Register iPad', 1);
bcrf_pin_device_revoke($pdo, $id2);
$_COOKIE[BCRF_PIN_COOKIE] = $_COOKIE[BCRF_PIN_COOKIE];   // still the iPad's cookie
$ok(bcrf_pin_device($pdo) === null, 'a stopped device no longer signs in');
$ok(count(bcrf_pin_devices($pdo)) === 2, 'settings list shows the device and the recently stopped one');
$threw = false; try { bcrf_pin_device_create($pdo, '   ', 1); } catch (InvalidArgumentException $e) { $threw = true; }
$ok($threw, 'a device needs a name');

// -- PIN session --
$owner = $pdo->query("SELECT id, first_name, last_name, role FROM users WHERE id = 1")->fetch();
@bcrf_pin_session_start($owner, ['id' => $id, 'name' => 'Gate tablet']);
$ok($_SESSION['user_role'] === 'moderator' && $_SESSION['pin']['real_role'] === 'administrator', 'an Owner signed in by PIN acts as a Manager');
$ok(!empty($_SESSION['mfa_ok']) && (int)$_SESSION['pin']['device'] === $id, 'PIN session records the device');
bcrf_pin_session_end();
$ok(!isset($_SESSION['user_id']) && !isset($_SESSION['pin']) && !isset($_SESSION['user_role']), 'Lock ends the session');
$ok(in_array('register.php', bcrf_pin_pages(), true) && !in_array('admin.php', bcrf_pin_pages(), true), 'PIN pages: register yes, console no');

// -- Manager approval --
$_SESSION = ['user_id' => 3, 'user_role' => 'Shopkeep', 'user_name' => 'Sam'];
$GLOBALS['bcrf_pin_approver'] = null; $GLOBALS['bcrf_mgr_needed'] = false;
$ok(!bcrf_can_override_capacity() && !bcrf_is_approver() && !bcrf_can_approve_variance() && !bcrf_mgr_ok(), 'a Shopkeep alone is not a manager');
$ok(bcrf_pin_take_approver($pdo, ['lines' => []]) === null, 'no approver in the request: nothing happens');
$r = bcrf_pin_take_approver($pdo, ['approver' => ['id' => 3, 'pin' => '2468']]);
$ok(is_string($r) && bcrf_pin_approver() === null, 'a Shopkeep PIN is not an approval');
$r = bcrf_pin_take_approver($pdo, ['approver' => ['id' => 2, 'pin' => '0000']]);
$ok(is_string($r) && bcrf_pin_approver() === null, 'a wrong Manager PIN is not an approval');
// Refused for want of a manager: the screen is told to ask for a PIN
$threw = false;
try { bcrf_waiver_gate_check($pdo, 'x@example.test', 'Grandma signed on paper', false); } catch (Throwable $e) { $threw = true; }
$mode = bcrf_waiver_mode($pdo);
if ($mode === 'block') {
    $f = bcrf_pin_need_fields($pdo);
    $ok($threw && !empty($f['need_manager']) && array_column($f['managers'], 'id') === [2, 1], 'waiver override by a Shopkeep asks for a manager PIN');
}
$GLOBALS['bcrf_mgr_needed'] = false;
$ok(bcrf_pin_need_fields($pdo) === [], 'other refusals do not ask for a PIN');
$why = bcrf_discount_problem($pdo, 1700, []);
$ok($why !== null && !empty(bcrf_pin_need_fields($pdo)['need_manager']), 'an odd discount by a Shopkeep asks for a manager PIN');
$r = bcrf_pin_take_approver($pdo, ['approver' => ['id' => 2, 'pin' => '5937']]);
$ok($r === true && bcrf_pin_approver()['id'] === 2, 'right Manager PIN approves this request');
$ok(bcrf_can_override_capacity() && bcrf_is_approver() && bcrf_can_approve_variance() && bcrf_can_override_discount() && bcrf_mgr_ok(), 'with the approval every manager check passes');
$ok(bcrf_discount_problem($pdo, 1700, []) === null, 'the odd discount goes through with the approval');
$ok(bcrf_pin_need_fields($pdo) === [], 'no PIN prompt once approved');
$ok(bcrf_mgr_id(3) === 2, 'the approver is recorded as approving');
$ok(bcrf_pin_approval_on($pdo), 'approval by PIN is on when a manager has a PIN');
bcrf_pin_audit_approval($pdo, 'register refund');
$a = $pdo->query("SELECT actor_id, action, object_label FROM audit_events WHERE action = 'approve' ORDER BY id DESC LIMIT 1")->fetch();
$ok($a && (int)$a['actor_id'] === 2 && str_contains($a['object_label'], 'for Sam: register refund'), 'the approval is in the audit log under the manager');
$GLOBALS['bcrf_pin_approver'] = null;
$ok(bcrf_mgr_id(3) === 3, 'without an approver the signed-in person is recorded');

echo $fails ? "$fails of $n checks FAILED\n" : "All $n checks passed\n";
exit($fails ? 1 : 0);
