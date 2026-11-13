<?php
// tests/permission_sections_test.php — the Permissions page save (includes/permission_sections.php, Nov 12, 2026) against
// a real, THROWAWAY MySQL 8 database built from every migration: ticks save, unticks save, and access the page doesn't
// show (or another role's) is left alone.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/permission_sections_test.php
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '',
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
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
require_once $root . '/includes/permission_sections.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$view = fn(string $role, string $v) => $pdo->query("SELECT can_access FROM view_permissions WHERE role = " . $pdo->quote($role) . " AND view_name = " . $pdo->quote($v))->fetchColumn();
$tbl = fn(string $role, string $t) => $pdo->query("SELECT CONCAT(can_view, can_edit) FROM role_permissions WHERE role = " . $pdo->quote($role) . " AND table_name = " . $pdo->quote($t))->fetchColumn();

$keys = [];
foreach (bcrf_permission_sections() as $sec) foreach ($sec['items'] as [$t, $k]) { $ok(!isset($keys[$t . $k]), "listed once: $k"); $keys[$t . $k] = 1; }
foreach (['animal_health', 'animal_expenses', 'incidents', 'tasks', 'tasks_manage', 'calendar', 'customers'] as $v) $ok(isset($keys['view' . $v]), "page lists $v");

// Something the page doesn't show, for Managers and for another role
$pdo->exec("INSERT INTO view_permissions (role, view_name, can_access) VALUES ('moderator', 'not_on_page', 1), ('Shopkeep', 'incidents', 1) ON DUPLICATE KEY UPDATE can_access = 1");
$pdo->exec("INSERT INTO role_permissions (role, table_name, can_view, can_edit) VALUES ('moderator', 'not_on_page_table', 1, 1) ON DUPLICATE KEY UPDATE can_view = 1");
$ok((string)$view('moderator', 'animal_health') === '1', 'migrations gave Managers health');

[$p, $tv, $te] = bcrf_permission_save($pdo, 'moderator', ['incidents' => '1', 'tasks' => '1'], ['animals' => ['view' => '1', 'edit' => '1'], 'breeds' => ['view' => '1']]);
$ok($p === 2 && $tv === 2 && $te === 1, "counts $p/$tv/$te");
$ok((string)$view('moderator', 'incidents') === '1' && (string)$view('moderator', 'tasks') === '1', 'ticked views saved');
$ok((string)$view('moderator', 'animal_health') === '0', 'unticked view on the page saved as off');
$ok((string)$view('moderator', 'not_on_page') === '1', 'view not on the page left alone');
$ok($tbl('moderator', 'animals') === '11' && $tbl('moderator', 'breeds') === '10' && $tbl('moderator', 'users') === '00', 'tables saved');
$ok($tbl('moderator', 'not_on_page_table') === '11', 'table not on the page left alone');
$ok((string)$view('Shopkeep', 'incidents') === '1', 'other roles left alone');
try { bcrf_permission_save($pdo, 'administrator', [], []); $ok(false, 'administrator refused'); } catch (RuntimeException $e) { $ok(true, ''); }
try { bcrf_permission_save($pdo, 'user', [], []); $ok(false, 'removed public user role refused'); } catch (RuntimeException $e) { $ok(true, ''); }
$ok(bcrf_permission_roles() === ['moderator', 'Shopkeep', 'Media'], 'page roles: Manager, Shopkeep, Content');
require_once $root . '/includes/roles.php';
$ok(bcrf_role_label('administrator') === 'Owner' && bcrf_role_label('moderator') === 'Manager' && bcrf_role_label('Media') === 'Content'
    && bcrf_role_label('Staff') === 'Staff' && bcrf_role_label('odd') === 'odd', 'role display names');

echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
