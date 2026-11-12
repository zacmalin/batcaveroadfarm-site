<?php
// tests/animal_weights_test.php — Farm OS Phase 5 slice 4 (Nov 12, 2026): weights (includes/animal_weights.php, migration 0043)
// against a real, THROWAWAY MySQL 8 database: parsing, weighing one or several animals, change alerts, voiding, the chart.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/animal_weights_test.php
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
require_once $root . '/includes/animal_weights.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (BcrfWeightError $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};
$today = bcrf_weight_today();
$day = fn(int $n) => bcrf_tasks_add_days($today, $n);

// -- Migration and setting --
$ok(bcrf_weights_ready($pdo), 'table exists');
(require $root . '/admin/migrations/0043_animal_weights.php')($pdo);
$ok($one("SELECT setting_value FROM site_settings WHERE setting_key = 'animal_weight_alert_pct'") === '10' && bcrf_weight_alert_pct($pdo) === 10.0, 'alert starts at 10%, migration repeats safely');
$throws(fn() => bcrf_weight_set_alert_pct($pdo, '0'), 'alert 0 refused', '1 to 100');
$throws(fn() => bcrf_weight_set_alert_pct($pdo, 'lots'), 'alert text refused', '1 to 100');

// -- Parsing --
$ok(bcrf_weight_parse('48.5') === 48.5 && bcrf_weight_parse(' 48.5 lb ') === 48.5 && bcrf_weight_parse('1,200 lbs') === 1200.0, 'weights parse, lb suffix ok');
$throws(fn() => bcrf_weight_parse('0'), 'zero refused', 'pounds');
$throws(fn() => bcrf_weight_parse('heavy', 'Hazel'), 'text refused, names the animal', 'Hazel');
$ok(bcrf_weight_bcs('') === null && bcrf_weight_bcs('3.5') === 3.5, 'body condition optional, halves ok');
$throws(fn() => bcrf_weight_bcs('3.3'), 'condition 3.3 refused', 'halves');
$throws(fn() => bcrf_weight_bcs('6'), 'condition 6 refused', '1 to 5');
$ok(bcrf_weight_fmt(48.5) === '48.5 lb' && bcrf_weight_fmt('48.00') === '48 lb' && bcrf_weight_fmt(1200) === '1,200 lb', 'weights shown plainly');
$ok(bcrf_weight_change(50, 45, 10) === ['pct' => -10.0, 'flag' => true] && bcrf_weight_change(50, 54, 10)['flag'] === false, 'exactly 10% flags, 8% does not');

// -- Animals --
$pdo->exec("INSERT INTO classifications (class_name) VALUES ('Goat'), ('Sheep')");
$pdo->exec("INSERT INTO breeds (breed_name, slug) VALUES ('Nigerian Dwarf', 'nd')");
$ins = $pdo->prepare("INSERT INTO animals (name, breed_id, classification_id, date_of_birth, is_active) VALUES (?, 1, ?, '2023-01-01', 1)");
foreach ([['Hazel', 1], ['Bramble', 1], ['Clover', 2]] as [$nm, $c]) $ins->execute([$nm, $c]);
[$hazel, $bramble, $clover] = array_map('intval', $pdo->query("SELECT id FROM animals ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));

// -- One animal --
$throws(fn() => bcrf_weight_add($pdo, $hazel, ['weighed_on' => $day(1), 'weight' => '50']), 'future date refused', 'future');
$throws(fn() => bcrf_weight_add($pdo, $hazel, ['weighed_on' => 'x', 'weight' => '50']), 'bad date refused', 'date');
$throws(fn() => bcrf_weight_add($pdo, $hazel, ['weighed_on' => $today, 'weight' => '']), 'blank weight refused', 'at least one');
$throws(fn() => bcrf_weight_add($pdo, 999, ['weighed_on' => $today, 'weight' => '50']), 'unknown animal refused', 'roster');
$r = bcrf_weight_add($pdo, $hazel, ['weighed_on' => $day(-60), 'weight' => '50', 'body_condition' => '3', 'note' => 'Spring'], 1);
$ok($r['saved'] === 1 && !$r['flagged'], 'first weighing saved, nothing to compare');
$w = $pdo->query("SELECT * FROM animal_weights WHERE id = " . $r['ids'][0])->fetch();
$ok($w['weight_lb'] === '50.00' && $w['body_condition'] === '3.0' && $w['note'] === 'Spring' && (int)$w['created_by'] === 1, 'stored with condition, note, who');
$r = bcrf_weight_add($pdo, $hazel, ['weighed_on' => $day(-30), 'weight' => '52'], 1);
$ok(!$r['flagged'], '4% up: no flag');
$r = bcrf_weight_add($pdo, $hazel, ['weighed_on' => $today, 'weight' => '45.5'], 1);
$ok(count($r['flagged']) === 1 && $r['flagged'][0][0] === 'Hazel' && $r['flagged'][0][1] === -12.5, '12.5% down flagged');
$al = bcrf_weight_alerts($pdo, $hazel);
$ok(count($al) === 1 && $al[0]['from_lb'] === 52.0 && $al[0]['to_lb'] === 45.5 && $al[0]['pct'] === -12.5, 'alert for the animal');
$ok(strpos(bcrf_weight_alert_text($al[0]), 'Weight down 12.5% since ') === 0 && str_ends_with(bcrf_weight_alert_text($al[0]), '(52 to 45.5 lb)'), 'alert wording: ' . bcrf_weight_alert_text($al[0]));
$hist = bcrf_weight_history($pdo, $hazel);
$ok(count($hist) === 3 && $hist[0]['weight_lb'] === '45.50' && $hist[0]['flag'] && $hist[1]['change'] === 4.0 && $hist[2]['change'] === null, 'history newest first with changes');

// -- A late entry compares with the weighing before its own date --
$r = bcrf_weight_add($pdo, $hazel, ['weighed_on' => $day(-45), 'weight' => '40'], 1);
$ok(count($r['flagged']) === 1 && $r['flagged'][0][1] === -20.0, 'back-dated weighing compared with the one before it');
$hist = bcrf_weight_history($pdo, $hazel);
$ok($hist[2]['weight_lb'] === '40.00' && $hist[1]['change'] === 30.0, 'history recomputes around the late entry');

// -- Void --
$late = (int)$one("SELECT id FROM animal_weights WHERE weight_lb = 40");
$throws(fn() => bcrf_weight_void($pdo, $late, ''), 'void needs a reason', 'why');
$ok(bcrf_weight_void($pdo, $late, 'Wrong goat', 1) === $hazel, 'voided');
$throws(fn() => bcrf_weight_void($pdo, $late, 'again'), 'void twice refused', 'already');
$hist = bcrf_weight_history($pdo, $hazel);
$ok(count($hist) === 4 && $hist[1]['change'] === 4.0 && $hist[2]['voided_at'] !== null && $hist[2]['change'] === null, 'voided weighing kept, skipped in changes');
$ok($one("SELECT COUNT(*) FROM animal_weights") == 4, 'nothing deleted');
$throws(fn() => bcrf_weight_void($pdo, 9999, 'x'), 'unknown weighing', 'exist');

// -- Several at once --
$throws(fn() => bcrf_weight_add_many($pdo, $today, [$bramble => ['weight' => '30'], $clover => ['weight' => 'abc']], 1), 'one bad row stops all', 'Clover');
$ok((int)$one("SELECT COUNT(*) FROM animal_weights WHERE animal_id = ?", [$bramble]) === 0, 'nothing saved when one row is bad');
$r = bcrf_weight_add_many($pdo, $day(-10), [$bramble => ['weight' => '30', 'body_condition' => '2.5'], $clover => ['weight' => ''], $hazel => ['weight' => '']], 1);
$ok($r['saved'] === 1 && count($r['ids']) === 1, 'blank rows skipped');
$latest = bcrf_weight_latest($pdo);
$ok($latest[$hazel]['weight_lb'] === '45.50' && $latest[$bramble]['weight_lb'] === '30.00' && !isset($latest[$clover]), 'latest per animal');

// -- Alert setting, old weighings, animals that left --
bcrf_weight_set_alert_pct($pdo, '15');
$ok(bcrf_weight_alert_pct($pdo) === 15.0 && bcrf_weight_alerts($pdo) === [], 'raising the alert to 15% clears a 12.5% change');
bcrf_weight_set_alert_pct($pdo, '10');
$ok(count(bcrf_weight_alerts($pdo)) === 1, 'back at 10%');
$ok(bcrf_weight_alerts($pdo, 0, bcrf_tasks_add_days($today, 61)) === [], 'a change more than 60 days old is not an alert');
bcrf_animal_set_status($pdo, $hazel, 'sold', 'Sold to a neighbor', 'J. Smith', '', 1);
$ok(bcrf_weight_alerts($pdo) === [], 'animals that left raise no alerts');
$throws(fn() => bcrf_weight_add($pdo, $hazel, ['weighed_on' => $today, 'weight' => '45']), 'no weighing animals that left', 'left');

// -- Chart --
$ok(bcrf_weight_chart_svg(bcrf_weight_history($pdo, $bramble)) === '', 'no chart for one weighing');
$svg = bcrf_weight_chart_svg(bcrf_weight_history($pdo, $hazel));
$ok(strpos($svg, '<svg') === 0 && substr_count($svg, '<circle') === 3 && strpos($svg, 'style=') === false && strpos($svg, '<script') === false, 'chart: one dot per weighing kept, no inline styles');

echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
