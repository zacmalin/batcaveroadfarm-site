<?php
// tests/nav_badges_test.php — menu "needs attention" counts (includes/nav_badges.php, Nov 13, 2026) against a THROWAWAY MySQL
// database: counts show for people who can act, stay hidden for others, and are cached for a minute.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_nav_test DB_USER=... DB_PASS=... php tests/nav_badges_test.php
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
require_once $root . '/includes/nav_badges.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$_SESSION = ['user_id' => 7, 'user_role' => 'administrator'];
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role) VALUES (7, 'Zac', 'M', 'z@example.com', 'administrator')");

$pdo->exec("INSERT INTO inventory (category, name, price, stock_quantity, min_stock_level, product_type, pricing_mode, unit, track_inventory, is_active)
            VALUES ('Zoo', 'Eggs', 6, 2, 5, 'retail', 'each', 'dozen', 1, 1), ('Zoo', 'Jam', 8, 20, 5, 'retail', 'each', 'jar', 1, 1)");
$pdo->exec("INSERT INTO event_inquiries (event_type, preferred_date, adult_count, child_count, customer_name, email, status) VALUES
            ('birthday', '2027-01-05', 2, 3, 'New One', 'n@x.test', 'new'), ('birthday', '2027-01-06', 2, 3, 'Old One', 'o@x.test', 'quoted')");
$pdo->exec("INSERT INTO ingredients (singular_name, plural_name) VALUES ('flour', 'flours')");
$pdo->exec("INSERT INTO recipes (id, title, slug, date_published, status) VALUES (1, 'Bread', 'bread', '2026-11-12', 'published')");
$pdo->exec("INSERT INTO recipe_ingredients (recipe_id, ingredient_id, quantity, unit_id) VALUES (1, 1, 2, NULL)");

$all = fn(string $t, string $v): bool => true;
$b = bcrf_nav_badges($pdo, 'administrator', $all, fn($t) => true);
$ok(($b['/admin/stock.php']['n'] ?? 0) === 1, 'low stock counted on Stock: ' . json_encode($b['/admin/stock.php'] ?? null));
$ok(($b['?view=event_inquiries']['n'] ?? 0) === 1, 'new inquiry counted on Private Events');
$ok(($b['/admin/kitchen_ingredients.php']['n'] ?? 0) === 1, 'unchecked flour counted on Label details');
$ok(!isset($b['?view=bookings']) && !isset($b['/admin/incidents.php']), 'nothing waiting = no badge');
$ok(($b['/admin/two_step.php']['n'] ?? 0) === 1, 'Owner without two-step gets a badge');

$pdo->exec("UPDATE event_inquiries SET status = 'quoted'");
$ok((bcrf_nav_badges($pdo, 'administrator', $all, fn($t) => true)['?view=event_inquiries']['n'] ?? 0) === 1, 'cached for a minute');
bcrf_nav_badges_clear();
$ok(!isset(bcrf_nav_badges($pdo, 'administrator', $all, fn($t) => true)['?view=event_inquiries']), 'cleared cache recounts');

bcrf_nav_badges_clear();
$_SESSION['user_role'] = 'Shopkeep';
$none = fn(string $t, string $v): bool => $t === 'view' && $v === 'inventory_update';
$s = bcrf_nav_badges($pdo, 'Shopkeep', $none, fn($t) => false);
$ok(array_keys($s) === ['/admin/stock.php'], 'a role only sees badges it can act on: ' . implode(',', array_keys($s)));
$s2 = bcrf_nav_badges_compute($pdo, 'Shopkeep', fn($t, $v) => $t === 'table' && $v === 'ingredients', fn($t) => false);
$ok(!isset($s2['/admin/kitchen_ingredients.php']), 'viewing ingredients without edit rights: no badge');

echo $fails ? "$n checks, $fails failed\n" : "All $n checks passed\n";
exit($fails ? 1 : 0);
