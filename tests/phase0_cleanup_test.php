<?php
// tests/phase0_cleanup_test.php — migrations 0024 (drop old audit tables) and 0025 (remove public user rows)
// against seeded data on a THROWAWAY MySQL 8 database (Farm OS Phase 0 closeout, Nov 11, 2026).
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_migrate_test DB_USER=... DB_PASS=... php tests/phase0_cleanup_test.php
// Refuses to run unless DB_NAME ends in "_test". Empties that database first. Exit 0 = all passed.
if (PHP_SAPI !== 'cli') exit(1);
set_exception_handler(function (Throwable $e) { fwrite(STDERR, 'Stopped: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n"); exit(1); });
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
]);
require dirname(__DIR__) . '/includes/migrations.php';
ini_set('log_errors', '0');   // 0025 logs the rows it keeps; that's expected here

$fail = 0;
$check = function (bool $cond, string $msg) use (&$fail) { if (!$cond) $fail++; echo ($cond ? '  ok    ' : '  FAIL  ') . $msg . "\n"; };
$one   = fn(string $sql) => $pdo->query($sql)->fetchColumn();
$table = fn(string $t) => (int)$one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($t)) === 1;

// Empty the database
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE `$t`");
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$files = bcrf_migrations_files();
$apply = function (string $v) use ($pdo, $files) {
    [$ok, $msg] = bcrf_migration_apply($pdo, $files[$v], null);
    if (!$ok) { fwrite(STDERR, $msg . "\n"); exit(1); }
};

// -- The live site's history, in order --
$apply('0001'); $apply('0002');
// Old audit rows written before 0003
$pdo->exec("INSERT INTO audit_log (user_id, user_name, action, table_name, record_id, record_label) VALUES
            (1, 'Zac', 'update', 'inventory', 5, 'Goat feed'), (NULL, 'Login guard', 'failed', 'staff_login', NULL, 'LOGIN FAILED: x@y.z')");
$pdo->exec("INSERT INTO site_audit (admin_user_id, admin_label, area, change_key, old_value, new_value) VALUES
            (1, 'Zac', 'hours', 'tue_open', '09:00', '10:00'), (1, 'Zac', 'contact', 'email.general', 'a@b.c', 'support@x.y')");
// Accounts before the 0006 split
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, password, role) VALUES
            (1,  'Zac',   'Staff',  'zac@farm.test',   'x', 'administrator'),
            (10, 'Ann',   'Public', 'ann@farm.test',   'x', 'user'),
            (11, 'Bob',   'Clock',  'bob@farm.test',   'x', 'user'),
            (12, 'Cara',  'Dup',    'ZAC@farm.test',   'x', 'user')");   // same email as staff: 0006 can't copy it
$pdo->exec("INSERT INTO time_cards (user_id, clock_in, ip_address) VALUES (11, UTC_TIMESTAMP(), '127.0.0.1')");
foreach (array_keys($files) as $v) if ($v >= '0003' && $v <= '0023') $apply($v);
// A row the fallback wrote after 0003 ran (the case 0024 must copy before dropping)
$pdo->exec("INSERT INTO audit_log (user_id, user_name, action, table_name, record_id, record_label) VALUES (1, 'Zac', 'delete', 'recipes', 9, 'Old pie')");

echo "Before: audit_log " . $one('SELECT COUNT(*) FROM audit_log') . ", site_audit " . $one('SELECT COUNT(*) FROM site_audit')
   . ", audit_events " . $one('SELECT COUNT(*) FROM audit_events') . ", users " . $one('SELECT COUNT(*) FROM users')
   . ", customers " . $one('SELECT COUNT(*) FROM customers') . "\n";
$customersBefore = (int)$one('SELECT COUNT(*) FROM customers');

// -- 0024 --
echo "0024_drop_old_audit_tables\n";
$apply('0024');
$check(!$table('audit_log') && !$table('site_audit'), 'audit_log and site_audit are gone');
$check((int)$one("SELECT COUNT(*) FROM audit_events WHERE legacy_source = 'audit_log'") === 3, 'all 3 audit_log rows are in audit_events, including the one written after 0003');
$check((int)$one("SELECT COUNT(*) FROM audit_events WHERE legacy_source = 'site_audit'") === 2, 'both site_audit rows are in audit_events');
$check($one("SELECT object_label FROM audit_events WHERE legacy_source = 'audit_log' AND action = 'delete'") === 'Old pie', 'the late row kept its details');

// -- 0025 --
echo "0025_remove_public_user_rows\n";
$apply('0025');
$ids = array_map('intval', $pdo->query('SELECT id FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$check(!in_array(10, $ids, true), 'Ann (public, copied to customers) is removed from users');
$check(in_array(11, $ids, true), 'Bob (public, but a time card points at him) is kept');
$check(in_array(12, $ids, true), 'Cara (public, never copied: duplicate email) is kept');
$check(in_array(1, $ids, true), 'staff are untouched');
$check((int)$one('SELECT COUNT(*) FROM time_cards WHERE user_id = 11') === 1, "Bob's time card is still there");
$check((int)$one('SELECT COUNT(*) FROM customers') === $customersBefore, 'customers is untouched');
$check((int)$one('SELECT COUNT(*) FROM customers WHERE id = 10 AND email = "ann@farm.test"') === 1, "Ann's customer account is still there");

// -- Both are safe to repeat --
echo "Repeat\n";
try {
    (require $files['0024']['file'])($pdo);
    (require $files['0025']['file'])($pdo);
    $check(true, 'running 0024 and 0025 again is harmless');
} catch (Throwable $e) { $check(false, 'repeat failed: ' . $e->getMessage()); }
$check(count($pdo->query('SELECT id FROM users')->fetchAll()) === count($ids), 'the repeat removed nothing more');

echo $fail ? "\n$fail check(s) failed.\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
