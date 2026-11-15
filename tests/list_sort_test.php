<?php
// tests/list_sort_test.php — the Sort box on admin lists (includes/list_sort.php, Nov 15, 2026)
// against a THROWAWAY MySQL database: sorting rows (blanks last, ties keep the list's order), the <select>, and
// remembering each person's pick per list on their account (staff_prefs 'list_sort').
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_sort_test DB_USER=... DB_PASS=... php tests/list_sort_test.php
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
require_once $root . '/includes/list_sort.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };

$rows = [
    ['id' => 1, 'name' => 'eggs', 'price' => '4.00', 'stock' => 12],
    ['id' => 2, 'name' => 'Apple butter', 'price' => '9.50', 'stock' => null],
    ['id' => 3, 'name' => 'Bread 10', 'price' => '7', 'stock' => 3],
    ['id' => 4, 'name' => 'Bread 9', 'price' => '7', 'stock' => 0],
    ['id' => 5, 'name' => '', 'price' => '1.25', 'stock' => 40],
];
$o = ['default' => ['By category'], 'az' => ['Name A to Z', 'name', 'asc'], 'za' => ['Name Z to A', 'name', 'desc'],
      'newest' => ['Newest first', 'id', 'desc'], 'price_lo' => ['Price low to high', 'price', 'asc'], 'price_hi' => ['Price high to low', 'price', 'desc'],
      'stock_lo' => ['Stock low to high', fn($r) => $r['stock'], 'asc'], 'sql' => ['By SQL', null, 'asc', 'x.id DESC']];
$ids = fn($k) => array_column(bcrf_list_sort_rows($rows, $o, $k), 'id');
$ok($ids('default') === [1, 2, 3, 4, 5], 'first choice keeps the list order');
$ok($ids('nope') === [1, 2, 3, 4, 5], 'unknown choice keeps the list order');
$ok($ids('az') === [2, 4, 3, 1, 5], 'A to Z: any case, natural numbers (Bread 9 before Bread 10), blank name last');
$ok($ids('za') === [1, 3, 4, 2, 5], 'Z to A: blank still last');
$ok($ids('newest') === [5, 4, 3, 2, 1], 'newest = highest id first');
$ok($ids('price_lo') === [5, 1, 3, 4, 2], 'price low to high, numeric (7 = 7.00), ties keep order');
$ok($ids('price_hi') === [2, 3, 4, 1, 5], 'price high to low, ties keep order');
$ok($ids('stock_lo') === [4, 3, 1, 5, 2], 'function field; 0 is a value, not blank; null last');
$ok(bcrf_list_sort_sql($o, 'sql', 'fallback') === 'x.id DESC' && bcrf_list_sort_sql($o, 'az', 'fallback') === 'fallback', 'SQL order or fallback');

$sel = bcrf_list_sort_select($o, 'price_hi', ['id' => 'ps', 'class' => 'c"x']);
$ok(str_contains($sel, '<select name="sort" data-sort-auto id="ps" class="c&quot;x" aria-label="Sort">'), 'select: name, auto-submit hook, label, escaped attributes');
$ok(str_contains($sel, '<option value="price_hi" selected>Price high to low</option>') && substr_count($sel, ' selected') === 1, 'select: current choice picked');

// Remembering the pick
$_SESSION = []; $_GET = [];
$ok(bcrf_list_sort_pick($pdo, 'products', $o) === 'default', 'not signed in, nothing asked: first choice');
$_SESSION['user_id'] = 7;
$_GET['sort'] = 'price_hi';
$ok(bcrf_list_sort_pick($pdo, 'products', $o) === 'price_hi', '?sort= is used');
$_GET = [];
$ok(bcrf_list_sort_pick($pdo, 'products', $o) === 'price_hi', 'remembered without ?sort=');
$ok(bcrf_list_sort_pick($pdo, 'vendors', $o) === 'default', 'each list remembers its own');
$_SESSION['user_id'] = 8;
$ok(bcrf_list_sort_pick($pdo, 'products', $o) === 'default', 'another person does not get my pick');
$_SESSION['user_id'] = 7;
$_GET['sort'] = 'evil\' OR 1=1';
$ok(bcrf_list_sort_pick($pdo, 'products', $o) === 'price_hi', 'unknown ?sort= ignored, saved pick kept');
$_GET['sort'] = 'default';
$ok(bcrf_list_sort_pick($pdo, 'products', $o) === 'default', 'back to the first choice');
$_GET = [];
$ok(bcrf_list_sort_pick($pdo, 'products', $o) === 'default' && bcrf_staff_pref_get($pdo, 7, 'list_sort') === null, 'first choice is not stored');
$ok(bcrf_staff_pref_get($pdo, 7, 'nav_shortcuts') === null, 'phone shortcuts untouched');
$_GET['sort'] = 'az';
bcrf_list_sort_pick($pdo, 'animals', $o);
$_GET = [];
$o2 = ['default' => ['By kind'], 'young' => ['Youngest', 'dob', 'desc']];
$ok(bcrf_list_sort_pick($pdo, 'animals', $o2) === 'default', 'a saved pick the list no longer offers falls back to the first');

echo $fails ? "$fails of $n checks failed\n" : "All $n checks passed\n";
exit($fails ? 1 : 0);
