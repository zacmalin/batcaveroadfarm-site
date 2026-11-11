<?php
// tests/migrations_test.php — an EMPTY database builds from the migrations (Farm OS Phase 0 exit check, Nov 11, 2026).
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_migrate_test DB_USER=... DB_PASS=... php tests/migrations_test.php
// Refuses to run unless DB_NAME ends in "_test". Empties that database, then:
//   1. runs every file in admin/migrations/ in order through the real runner (includes/migrations.php)
//   2. runs the runner again: nothing may be pending, nothing may fail
//   3. checks every table that site code still creates on first use (CREATE TABLE IF NOT EXISTS outside
//      admin/migrations/, and the Hiring installer) already exists, i.e. a migration made it
// Exit 0 = all passed. Never touches a real database.
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,     // same options as config.php
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
$root = dirname(__DIR__);
require $root . '/includes/migrations.php';

$fail = 0;
$ok  = function (string $msg) { echo "  ok    $msg\n"; };
$bad = function (string $msg) use (&$fail) { $fail++; echo "  FAIL  $msg\n"; };
$tables = fn(): array => $pdo->query("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);

// -- Start from nothing --
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
if ($tables()) { fwrite(STDERR, "Could not empty the test database.\n"); exit(2); }

// -- 1. Build --
$files = bcrf_migrations_files();
echo 'Building an empty database from ' . count($files) . " migrations\n";
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
foreach ($log as $line) echo '        ' . $line . "\n";
$built ? $ok('every migration ran') : $bad('a migration failed on an empty database (see the last line above)');

// Version numbers must be a gap-free run from 0001, or a fresh install and the live site could differ.
$want = array_map(fn($i) => sprintf('%04d', $i), range(1, count($files)));
array_keys($files) === $want ? $ok('migration numbers run 0001 to ' . end($want) . ' with no gaps') : $bad('migration numbers have a gap or a duplicate: ' . implode(' ', array_keys($files)));

// -- 2. Nothing left, and safe to ask again --
$pending = array_filter(bcrf_migrations_status($pdo), fn($r) => $r['state'] !== 'applied');
!$pending ? $ok('nothing pending or changed afterwards') : $bad('still not applied: ' . implode(', ', array_keys($pending)));
[$again, $log2] = bcrf_migrations_run_pending($pdo, null);
($again && $log2 === ['Nothing to run: the database is up to date.']) ? $ok('a second run does nothing') : $bad('second run: ' . implode(' | ', $log2));

// -- 3. First-use tables are covered by migrations --
$have = array_flip($tables());
echo count($have) . " tables built\n";
$firstUse = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', substr((string)$f, strlen($root) + 1));
    if (!str_ends_with($p, '.php') || preg_match('#^(vendor|node_modules|tests|tools|admin/migrations)/#', $p)) continue;
    $src = (string)file_get_contents((string)$f);
    if (preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`?([a-z_][a-z0-9_]*)`?\s*\(/i', $src, $m)) foreach ($m[1] as $t) $firstUse[$t][] = $p;
    if ($p === 'includes/jobs/schema.php' && preg_match_all('/=>\s*"CREATE TABLE\s+([a-z_][a-z0-9_]*)\s*\(/i', $src, $m)) foreach ($m[1] as $t) $firstUse[$t][] = $p;
}
ksort($firstUse);
$missing = array_diff_key($firstUse, $have);
if (!$missing) $ok(count($firstUse) . ' tables that code can still create on first use all come from a migration');
else foreach ($missing as $t => $where) $bad("table `$t` is only created at first use (" . implode(', ', array_unique($where)) . '): add it to a migration');

echo $fail ? "\n$fail check(s) failed.\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
