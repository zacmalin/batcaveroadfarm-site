<?php
// tests/animal_expenses_test.php — Farm OS Phase 5 slice 7 (Nov 12, 2026): expenses (includes/animal_expenses.php, migration 0046)
// against a real, THROWAWAY MySQL 8 database: other expenses, vet and feed costs, feed split, totals by month/species/animal, CSV rows.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/animal_expenses_test.php
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
require_once $root . '/includes/animal_expenses.php';
require_once $root . '/includes/animal_care.php';
require_once $root . '/includes/animal_health.php';
function hasViewAccess($role, $view) { return $role === 'administrator' || ($role === 'moderator' && $view === 'animal_expenses'); }

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (BcrfAnimalError $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};
$today = bcrf_expense_today();
$day = fn(int $n) => (new DateTimeImmutable($today))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');

// -- Migration, permission --
$ok(bcrf_expenses_ready($pdo), 'table exists');
(require $root . '/admin/migrations/0046_animal_expenses.php')($pdo);
$ok((int)$one("SELECT can_access FROM view_permissions WHERE role = 'moderator' AND view_name = 'animal_expenses'") === 1, 'Managers get the view, migration repeats safely');
$ok(bcrf_expenses_can(), 'owner can see costs');
$_SESSION['user_role'] = 'Staff'; $ok(!bcrf_expenses_can(), 'staff cannot'); $_SESSION['user_role'] = 'administrator';

// -- Money --
$ok(bcrf_expense_money('85') === 8500 && bcrf_expense_money(' $1,200.50 ') === 120050, 'dollars parse');
$throws(fn() => bcrf_expense_money('0'), 'zero refused', 'dollars');
$throws(fn() => bcrf_expense_money('lots'), 'text refused', 'dollars');
$ok(bcrf_expense_fmt(120050) === '$1,200.50' && bcrf_expense_fmt(5) === '$0.05', 'money shown');

// -- Animals --
$pdo->exec("INSERT INTO classifications (class_name) VALUES ('Goat'), ('Sheep')");
$pdo->exec("INSERT INTO breeds (breed_name, slug) VALUES ('Nigerian Dwarf', 'nd')");
$ins = $pdo->prepare("INSERT INTO animals (name, breed_id, classification_id, date_of_birth, is_active) VALUES (?, 1, ?, '2023-01-01', 1)");
foreach ([['Hazel', 1], ['Bramble', 1], ['Clover', 2]] as [$nm, $c]) $ins->execute([$nm, $c]);
[$hazel, $bramble, $clover] = array_map('intval', $pdo->query("SELECT id FROM animals ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));

// -- Other expenses --
$base = ['spent_on' => $today, 'amount' => '40', 'category' => 'farrier', 'description' => 'Hoof trim', 'for' => 'animal', 'animal_id' => $hazel];
$throws(fn() => bcrf_expense_add($pdo, ['spent_on' => $day(1)] + $base), 'future refused', 'future');
$throws(fn() => bcrf_expense_add($pdo, ['spent_on' => 'x'] + $base), 'bad date refused', 'real date');
$throws(fn() => bcrf_expense_add($pdo, ['category' => 'toys'] + $base), 'bad category refused', 'what it was for');
$throws(fn() => bcrf_expense_add($pdo, ['description' => ' '] + $base), 'no description refused', 'Say what');
$throws(fn() => bcrf_expense_add($pdo, ['animal_id' => 999] + $base), 'unknown animal refused', 'animal');
$throws(fn() => bcrf_expense_add($pdo, ['for' => 'species', 'classification_id' => 99] + $base), 'unknown species refused', 'species');
$throws(fn() => bcrf_expense_add($pdo, ['for' => 'everyone'] + $base), 'bad for refused', 'who');
$e1 = bcrf_expense_add($pdo, ['vendor' => 'Joe the farrier'] + $base, 1);
$e = bcrf_expense_get($pdo, $e1);
$ok((int)$e['amount_cents'] === 4000 && (int)$e['animal_id'] === $hazel && $e['classification_id'] === null && $e['vendor'] === 'Joe the farrier', 'animal expense stored');
$e2 = bcrf_expense_add($pdo, ['for' => 'species', 'classification_id' => 1, 'animal_id' => $hazel, 'category' => 'bedding', 'description' => 'Shavings', 'amount' => '25.10'] + $base, 1);
$e = bcrf_expense_get($pdo, $e2);
$ok($e['animal_id'] === null && (int)$e['classification_id'] === 1, 'species expense ignores the animal picker');
$e3 = bcrf_expense_add($pdo, ['for' => 'all', 'category' => 'equipment', 'description' => 'Wheelbarrow', 'amount' => '120', 'spent_on' => $day(-40)] + $base, 1);
$e = bcrf_expense_get($pdo, $e3);
$ok($e['animal_id'] === null && $e['classification_id'] === null, 'all-animals expense');
$e4 = bcrf_expense_add($pdo, ['amount' => '10', 'description' => 'Entered twice'] + $base, 1);
$throws(fn() => bcrf_expense_void($pdo, $e4, ' '), 'void needs a reason', 'why');
bcrf_expense_void($pdo, $e4, 'duplicate', 1);
$ok(bcrf_expense_get($pdo, $e4)['void_reason'] === 'duplicate', 'voided with reason');
$throws(fn() => bcrf_expense_void($pdo, $e4, 'again'), 'second void refused', 'already');
$throws(fn() => bcrf_expense_void($pdo, 999, 'x'), 'unknown void refused', "doesn't exist");

// -- Vet --
bcrf_medical_save($pdo, 0, $hazel, ['kind' => 'exam', 'title' => 'Checkup', 'record_date' => $day(-3), 'vet_name' => 'Dr. Lee', 'cost' => '75'], 1);
bcrf_medical_save($pdo, 0, $clover, ['kind' => 'exam', 'title' => 'No charge look', 'record_date' => $day(-3)], 1);

// -- Feed: 3 bags at $18.50 = $55.50 shared by 2 goats → 2775 each; 1 bag for Clover → 1850; one voided --
$pdo->exec("INSERT INTO inventory (category, name, price, stock_quantity, product_type, pricing_mode, unit, track_inventory, is_active, avg_cost_cents)
            VALUES ('Zoo', 'Goat feed 50 lb', 0, 20, 'retail', 'each', 'bag', 1, 0, 1850)");
$feed = (int)$pdo->lastInsertId();
bcrf_care_set_feed_ids($pdo, [$feed]);
$feeding = (int)$one("SELECT id FROM animal_care_types WHERE seed_key = 'feeding'");
$r1 = bcrf_care_log($pdo, ['type' => $feeding, 'animals' => [$hazel, $bramble], 'feed_product' => $feed, 'feed_qty' => '3'], 1);
$r2 = bcrf_care_log($pdo, ['type' => $feeding, 'animals' => [$clover], 'feed_product' => $feed, 'feed_qty' => '1'], 1);
$r3 = bcrf_care_log($pdo, ['type' => $feeding, 'animals' => [$clover], 'feed_product' => $feed, 'feed_qty' => '1'], 1);
$logId = fn($r) => (int)($r['id'] ?? $r['log_id'] ?? $r);
bcrf_care_void($pdo, $logId($r3), 'wrong', 1);
// odd cents: 3 cents shared by 2 → 2 and 1
$pdo->prepare("INSERT INTO animal_care_logs (care_type_id, logged_at, feed_product_id, feed_qty, feed_cost_cents) VALUES (?, UTC_TIMESTAMP(), ?, 1, 3)")->execute([$feeding, $feed]);
$odd = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO animal_care_log_animals (log_id, animal_id) VALUES (?, ?), (?, ?)")->execute([$odd, $hazel, $odd, $bramble]);
// an old feeding last year, outside this year's range
$pdo->prepare("UPDATE animal_care_logs SET logged_at = ? WHERE id = ?")->execute([((int)substr($today, 0, 4) - 1) . '-06-01 15:00:00', $logId($r2)]);

$from = substr($today, 0, 4) . '-01-01';
$lines = bcrf_expense_lines($pdo, $from, $today);
$by = fn($src) => array_values(array_filter($lines, fn($l) => $l['source'] === $src));
$ok(count($by('vet')) === 1 && $by('vet')[0]['cents'] === 7500 && $by('vet')[0]['description'] === 'Checkup (Dr. Lee)' && $by('vet')[0]['species'] === 'Goat', 'vet line with cost only');
$feedLines = $by('feed');
$shares = array_map(fn($l) => [$l['animal'], $l['cents']], $feedLines);
$ok(count($feedLines) === 4, 'feed lines: 2 shares + 2 odd-cent shares (voided and last year left out): ' . json_encode($shares));
$ok(in_array(['Hazel', 2775], $shares, true) && in_array(['Bramble', 2775], $shares, true), '$55.50 split evenly');
$ok(in_array(['Hazel', 2], $shares, true) && in_array(['Bramble', 1], $shares, true) && array_sum(array_column($feedLines, 'cents')) === 5553, 'odd cents: 3 split as 2 and 1, nothing lost');
$ok(strpos($feedLines[0]['description'], 'Goat feed 50 lb') === 0 && strpos($feedLines[0]['description'], 'shared by 2') !== false, 'feed description: ' . $feedLines[0]['description']);
$ok(count($by('other')) === 3 && array_sum(array_column($by('other'), 'cents')) === 4000 + 2510 + 12000 - ($day(-40) < $from ? 12000 : 0), 'other lines, voided left out');
$hidden = bcrf_expense_lines($pdo, $from, $today, 0, false);
$ok(array_values(array_filter($hidden, fn($l) => $l['source'] === 'vet'))[0]['description'] === 'Vet visit', 'vet title hidden without health access');

// -- Totals --
$t = bcrf_expense_totals($lines);
$otherSum = array_sum(array_column($by('other'), 'cents'));
$ok($t['total']['vet'] === 7500 && $t['total']['feed'] === 5553 && $t['total']['other'] === $otherSum && $t['total']['all'] === 7500 + 5553 + $otherSum, 'totals: ' . json_encode($t['total']));
$ok($t['animal'][$hazel]['vet'] === 7500 && $t['animal'][$hazel]['other'] === 4000 && $t['animal'][$hazel]['feed'] === 2777, 'Hazel: vet, farrier, feed share (2775 + 2)');
$ok($t['animal'][$bramble]['feed'] === 2776 && !isset($t['animal'][$clover]), 'Bramble feed 2775 + 1; Clover has nothing this year');
$ok($t['species']['Goat']['all'] === 7500 + 4000 + 2510 + 5553, 'Goat species includes the shavings');
$ok(($day(-40) < $from) || $t['species']['All animals']['other'] === 12000, 'wheelbarrow under All animals');
$ok(array_key_first($t['species']) === 'Goat', 'species sorted by spend');
$ok(isset($t['month'][substr($today, 0, 7)]), 'month bucket');
$lastYear = bcrf_expense_totals(bcrf_expense_lines($pdo, ((int)substr($today, 0, 4) - 1) . '-01-01', ((int)substr($today, 0, 4) - 1) . '-12-31'));
$ok($lastYear['total']['feed'] === 1850 && $lastYear['animal'][$clover]['feed'] === 1850, 'last year: Clover\'s bag');

// -- One animal --
$hz = bcrf_expense_lines($pdo, '2000-01-01', $today, $hazel);
$ok(count($hz) === 4 && array_sum(array_column($hz, 'cents')) === 7500 + 4000 + 2777, 'Hazel only: vet, farrier, two feed shares');
$ok(!array_filter($hz, fn($l) => $l['animal_id'] !== $hazel), 'no other animal\'s lines');
$ok(count(bcrf_expense_recent($pdo, $hazel)) === 2 && count(bcrf_expense_recent($pdo)) === 4, 'recent other expenses, voided included');
$ok(bcrf_expense_for_text(['animal_id' => null, 'classification_id' => 1, 'class_name' => 'Goat']) === 'All Goat' && bcrf_expense_for_text(['animal_id' => null, 'classification_id' => null]) === 'All animals', 'for text');

// -- CSV --
$rows = bcrf_expense_csv_rows($lines);
$ok(count($rows) === count($lines) && count($rows[0]) === count(BCRF_EXPENSE_CSV_COLUMNS), 'CSV rows match columns');
$vetRow = array_values(array_filter($rows, fn($r) => $r[1] === 'Vet'))[0];
$ok($vetRow[6] === '75.00' && $vetRow[4] === 'Hazel' && $vetRow[2] === '', 'CSV vet row: ' . json_encode($vetRow));
$farRow = array_values(array_filter($rows, fn($r) => $r[2] === 'Farrier'))[0];
$ok($farRow[5] === 'Hoof trim (Joe the farrier)' && $farRow[7] === 'expense ' . $e1, 'CSV other row');

// -- Farm time: a feeding at 11 PM farm time counts on that farm day --
$late = (new DateTimeImmutable($day(-1) . ' 23:30:00', bcrf_expense_tz()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$pdo->prepare("UPDATE animal_care_logs SET logged_at = ? WHERE id = ?")->execute([$late, $logId($r1)]);
$y = array_values(array_filter(bcrf_expense_lines($pdo, $day(-1), $day(-1)), fn($l) => $l['source'] === 'feed'));
$ok(count($y) === 2 && $y[0]['date'] === $day(-1), 'late-evening feeding counts on the farm day');

echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
