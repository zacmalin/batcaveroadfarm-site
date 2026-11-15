<?php
// tests/staff_prefs_test.php — phone shortcuts saved on the account (includes/staff_prefs.php, migration 0068, Nov 15, 2026)
// against a THROWAWAY MySQL database: save, read back, clean bad input, clear back to defaults, one person can't see another's.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_prefs_test DB_USER=... DB_PASS=... php tests/staff_prefs_test.php
// Refuses to run unless DB_NAME ends in "_test". Empties that database and builds it from every migration. Exit 0 = all passed.
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$dsn = 'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4';
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', $opts);
$root = dirname(__DIR__);
ini_set('log_errors', '0');

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }
require_once $root . '/includes/staff_prefs.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };

$ok(bcrf_staff_prefs_ready($pdo), 'table exists after migrations');
$ok(bcrf_staff_pref_get($pdo, 7, 'nav_shortcuts') === null, 'nothing saved = null (role defaults)');

$clean = bcrf_nav_shortcuts_clean(['dashboard', 'dashboard', 'storefront.pos', '<script>', 'team.tasks', 'visitors.bookings', 'content.blog']);
$ok($clean === ['dashboard', 'storefront.pos', 'team.tasks', 'visitors.bookings'], 'clean: no repeats, no bad ids, max 4: ' . json_encode($clean));
$ok(bcrf_nav_shortcuts_clean('nope') === [], 'clean: not a list = empty');

$ok(bcrf_staff_pref_set($pdo, 7, 'nav_shortcuts', ['dashboard', 'content.social-queue']), 'save');
$ok(bcrf_staff_pref_get($pdo, 7, 'nav_shortcuts') === ['dashboard', 'content.social-queue'], 'read back the same list');
bcrf_staff_pref_set($pdo, 7, 'nav_shortcuts', ['team.tasks']);
$ok(bcrf_staff_pref_get($pdo, 7, 'nav_shortcuts') === ['team.tasks'], 'second save replaces the first');
$ok(bcrf_staff_pref_get($pdo, 8, 'nav_shortcuts') === null, "someone else doesn't get your shortcuts");
$ok(!bcrf_staff_pref_set($pdo, 7, 'not_a_pref', ['x']), 'unknown setting refused');
$ok(!bcrf_staff_pref_set($pdo, 0, 'nav_shortcuts', ['x']), 'no user refused');
$utc = $pdo->query("SELECT TIMESTAMPDIFF(MINUTE, updated_at, UTC_TIMESTAMP()) FROM staff_prefs WHERE user_id = 7")->fetchColumn();
$ok(abs((int)$utc) <= 1, 'updated_at is UTC');
bcrf_staff_pref_set($pdo, 7, 'nav_shortcuts', null);
$ok(bcrf_staff_pref_get($pdo, 7, 'nav_shortcuts') === null, 'clear = back to defaults');

// The migration is safe to repeat
$m = require $root . '/admin/migrations/0068_staff_prefs.php';
try { $m($pdo); $ok(true, 'repeat'); } catch (Throwable $e) { $ok(false, 'migration repeat: ' . $e->getMessage()); }

echo $fails ? "$fails of $n checks failed\n" : "All $n checks passed\n";
exit($fails ? 1 : 0);
