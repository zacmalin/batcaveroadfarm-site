<?php
// tests/vendors_web_test.php — vendor website and address (includes/vendors.php, migration 0065, Nov 14, 2026)
// against a real, THROWAWAY MySQL 8 database built from every migration.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_vendors_test DB_USER=... DB_PASS=... php tests/vendors_web_test.php
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
(require $root . '/admin/migrations/0065_vendor_website_address.php')($pdo);   // safe to repeat
require_once $root . '/includes/vendors.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$throws = function (callable $f): bool { try { $f(); return false; } catch (InvalidArgumentException $e) { return true; } };

$ok(bcrf_vendors_ready($pdo) && bcrf_vendors_has_web($pdo), 'migration 0065 ran (twice)');

// Links
$ok(bcrf_vendor_web_url('tractorsupply.com') === 'https://tractorsupply.com', 'bare domain gets https://');
$ok(bcrf_vendor_web_url('http://feed.example.com/store') === 'http://feed.example.com/store', 'http kept');
$ok(bcrf_vendor_web_url('') === '', 'empty website is fine');
foreach (['javascript:alert(1)', 'ftp://a.com', 'nodot', 'a b.com'] as $bad) $ok($throws(fn() => bcrf_vendor_web_url($bad)), "bad website refused: $bad");
$ok(bcrf_vendor_tel('(828) 555-1234') === '8285551234' && bcrf_vendor_tel('+1 828.555.1234') === '+18285551234' && bcrf_vendor_tel('n/a') === '', 'tel links');
$ok(bcrf_vendor_map_url("12 Main St\nOld Fort, NC") === 'https://www.google.com/maps/search/?api=1&query=12%20Main%20St%20Old%20Fort%2C%20NC' && bcrf_vendor_map_url('') === '', 'map link');

// Save
$id = bcrf_vendor_save($pdo, null, ['name' => 'Feed Barn', 'phone' => '828-555-0101', 'website' => 'feedbarn.example.com', 'address' => "  1 Mill Rd \n Marion, NC ", 'is_active' => 1], 1);
$v = bcrf_vendor_get($pdo, $id);
$ok($v && $v['website'] === 'https://feedbarn.example.com' && $v['address'] === '1 Mill Rd Marion, NC' && $v['phone'] === '828-555-0101', 'new vendor saves website and address');
bcrf_vendor_save($pdo, $id, ['name' => 'Feed Barn', 'website' => '', 'address' => '', 'is_active' => 0], 1);
$v = bcrf_vendor_get($pdo, $id);
$ok($v['website'] === null && $v['address'] === null && (int)$v['is_active'] === 0 && $v['phone'] === null, 'clearing website and address saves NULL');
$ok($throws(fn() => bcrf_vendor_save($pdo, $id, ['name' => 'Feed Barn', 'website' => 'not a site'], 1)), 'bad website blocks Save');
$ok(bcrf_vendor_get($pdo, $id)['name'] === 'Feed Barn', 'vendor unchanged after a refused Save');

echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
