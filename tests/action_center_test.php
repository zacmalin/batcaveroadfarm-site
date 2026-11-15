<?php
// tests/action_center_test.php — Action Center as one function + Owners' "Problems" in the morning email (Nov 15, 2026)
// against a THROWAWAY MySQL database: papers out of date stay listed until replaced (no 60-day cutoff), renewals due/late,
// who sees what, the email part (Owners only, urgent + today only, no tasks or two-step), and the staff settings check.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_ac_test DB_USER=... DB_PASS=... php tests/action_center_test.php
// Refuses to run unless DB_NAME ends in "_test". Empties that database and builds it from every migration. Exit 0 = all passed.
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$dsn = 'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4';
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', $opts);
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

// Mail stub: bcrf_task_send() uses bcf_send_mail() when it exists.
$GLOBALS['sent'] = [];
function bcf_send_mail($to, $subject, $html, $opts = []) { $GLOBALS['sent'][] = ['to' => $to, 'subject' => $subject, 'html' => $html]; return true; }

require $root . '/admin/includes/permission_checks.php';
require $root . '/includes/documents.php';
require $root . '/includes/renewals.php';
require $root . '/includes/task_notify.php';
require $root . '/includes/action_center.php';
require $root . '/includes/staff_prefs.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$as = function (string $role, int $uid = 1) { $_SESSION = ['user_id' => $uid, 'user_role' => $role]; };
$ids = fn(array $alerts) => array_column($alerts, 'id');
$find = function (array $alerts, string $id) { foreach ($alerts as $a) if ($a['id'] === $id) return $a; return null; };

$today = bcrf_doc_today();
$d = fn(int $days) => (new DateTimeImmutable($today))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');

// ── Fixtures ──
$pdo->exec("DELETE FROM renewals");
$pdo->exec("DELETE FROM task_recurrences");
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role, account_status) VALUES
            (1, 'Zac', 'Owner', 'zac@x.test', 'administrator', 'active'), (3, 'Mo', 'Manager', 'mo@x.test', 'moderator', 'active'),
            (4, 'Sam', 'Staff', 'sam@x.test', 'Staff', 'active')");
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$pdo->exec("INSERT INTO vendors (id, name, created_at) VALUES (20, 'Feed Barn', NOW()), (21, 'Hay Guy', NOW())");
$pdo->exec("INSERT INTO purchase_docs (id, doc_type, link_type, link_id, expires_on, created_at) VALUES
            (60, 'insurance', 'vendor', 20, '" . $d(-120) . "', UTC_TIMESTAMP() - INTERVAL 2 DAY),
            (61, 'insurance', 'vendor', 21, '" . $d(-200) . "', UTC_TIMESTAMP() - INTERVAL 2 DAY),
            (62, 'insurance', 'vendor', 21, '" . $d(300) . "',  UTC_TIMESTAMP() - INTERVAL 1 DAY),
            (63, 'w9',        'vendor', 20, '" . $d(10) . "',   UTC_TIMESTAMP() - INTERVAL 2 DAY),
            (64, 'other',     'vendor', 20, '" . $d(-5) . "',   UTC_TIMESTAMP() - INTERVAL 2 DAY),
            (65, 'other',     'vendor', 20, NULL,               UTC_TIMESTAMP() - INTERVAL 1 DAY),
            (66, 'contract',  'vendor', 20, '" . $d(-3) . "',   UTC_TIMESTAMP() - INTERVAL 2 DAY)");
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// ── Papers: no 60-day cutoff, gone once replaced ──
$exp = array_map('intval', array_column(bcrf_doc_expiring($pdo, $today), 'id'));
$ok(in_array(60, $exp, true), '120 days out of date, not replaced: still listed (was cut off at 60)');
$ok(!in_array(61, $exp, true) && !in_array(62, $exp, true), 'old insurance replaced by a newer one: gone; the new one is far off');
$ok(in_array(63, $exp, true), 'running out in 10 days: listed');
$ok(in_array(64, $exp, true), '"Other" not replaced by another "Other" with no expiry');
$ok(in_array(66, $exp, true), 'contract 3 days out of date: listed');
$pdo->exec("INSERT INTO purchase_docs (id, doc_type, link_type, link_id, expires_on, created_at) VALUES (67, 'contract', 'vendor', 20, NULL, UTC_TIMESTAMP())");
$ok(!in_array(66, array_map('intval', array_column(bcrf_doc_expiring($pdo, $today), 'id')), true), 'contract replaced by a newer one with no end date');
$pdo->exec("UPDATE purchase_docs SET deleted_at = UTC_TIMESTAMP() WHERE id = 62");
$ok(in_array(61, array_map('intval', array_column(bcrf_doc_expiring($pdo, $today), 'id')), true), 'deleting the newer one brings the old one back');
$pdo->exec("UPDATE purchase_docs SET deleted_at = NULL WHERE id = 62");
$as('administrator');
$list = array_map('intval', array_column(bcrf_doc_list($pdo, ['show' => 'expiring'], 100), 'id'));
$ok(in_array(60, $list, true) && !in_array(61, $list, true) && !in_array(66, $list, true), 'Documents › Expiring hides replaced papers: ' . json_encode($list));
$all20 = bcrf_doc_for($pdo, 'vendor', 21);
$ok(bcrf_doc_is_replaced(bcrf_doc_get($pdo, 61), $all20) && !bcrf_doc_is_replaced(bcrf_doc_get($pdo, 62), $all20), 'vendor box marks the old one "replaced"');
$box = bcrf_doc_box_html($pdo, 'vendor', 21, '/admin/vendors.php?id=21', 'tok');
$ok(str_contains($box, 'Replaced by a newer one'), 'vendor box says Replaced');

// ── Action Center: papers + renewals ──
$pdo->exec("INSERT INTO renewals (id, title, category, renews_on, repeat_months, remind_days, assignee_role, created_at) VALUES
            (1, 'Liability insurance', 'insurance', '" . $d(-2) . "', 12, 30, 'administrator', UTC_TIMESTAMP()),
            (2, 'Domain', 'website', '" . $d(20) . "', 12, 30, 'administrator', UTC_TIMESTAMP()),
            (3, 'Far one', 'other', '" . $d(200) . "', 12, 30, 'administrator', UTC_TIMESTAMP())");
$al = bcrf_action_alerts($pdo, 'administrator', ['today' => $today, 'low_stock_items' => []]);
$pa = $find($al, 'papers');
$ok($pa !== null && $pa['priority'] === 'today' && str_contains($pa['title'], 'run out'), 'Owner: papers alert, today (some have run out): ' . ($pa['title'] ?? 'none'));
$ok($pa && str_contains(implode('|', $pa['items']), 'Insurance certificate for Feed Barn'), 'papers alert names the paper');
$rn = $find($al, 'renewals');
$ok($rn !== null && $rn['priority'] === 'urgent' && str_contains($rn['title'], '2 renewals') && str_contains($rn['title'], '1 late'), 'Owner: renewals alert urgent with 1 late: ' . ($rn['title'] ?? 'none'));
$ok($rn && !str_contains(implode('|', $rn['items']), 'Far one'), 'renewal outside its warning window left out');
$prio = array_column($al, 'priority');
$ok($prio === array_values(array_merge(array_filter($prio, fn($p) => $p === 'urgent'), array_filter($prio, fn($p) => $p === 'today'), array_filter($prio, fn($p) => $p === 'fyi'))), 'urgent first, then today, then FYI');

$as('Staff', 4);
$alS = bcrf_action_alerts($pdo, 'Staff', ['today' => $today, 'low_stock_items' => []]);
$ok(!in_array('papers', $ids($alS), true) && !in_array('renewals', $ids($alS), true), 'Staff: no vendor papers, no renewals');
$as('moderator', 3);
$alM = bcrf_action_alerts($pdo, 'moderator', ['today' => $today, 'low_stock_items' => []]);
$ok(in_array('renewals', $ids($alM), true), 'Manager: renewals (default view)');

// Low stock looked up when the caller doesn't pass it
$pdo->exec("INSERT INTO inventory (name, category, stock_quantity, min_stock_level, is_active, track_inventory) VALUES ('Goat feed', 'Zoo', 1, 5, 1, 1)");
$as('administrator');
$ok(in_array('low_stock', $ids(bcrf_action_alerts($pdo, 'administrator', ['today' => $today])), true), 'low stock found without the dashboard loader');

// ── Owners' morning email ──
$probs = bcrf_owner_problems($pdo, ['id' => 1, 'name' => 'Zac Owner'], $today);
$pids = $ids($probs);
$ok(in_array('papers', $pids, true) && in_array('renewals', $pids, true) && in_array('low_stock', $pids, true), 'problems: papers, renewals, low stock: ' . json_encode($pids));
$ok(!in_array('tasks', $pids, true) && !in_array('mfa', $pids, true), 'problems: no tasks, no two-step');
$ok(!array_filter($probs, fn($a) => $a['priority'] === 'fyi'), 'problems: FYI left out');
$_SESSION = ['marker' => 1];
bcrf_owner_problems($pdo, ['id' => 1], $today);
$ok(($_SESSION['marker'] ?? 0) === 1, 'session put back after');
$html = bcrf_owner_problems_html($probs);
$ok(str_contains($html, 'Problems · ' . count($probs)) && str_contains($html, 'Urgent: ') && str_contains($html, '/admin/renewals.php'), 'email block: heading, urgent label, link');
$ok(!str_contains(bcrf_owner_problems_html([['priority' => 'today', 'title' => '<b>x</b>', 'items' => [], 'detail' => '', 'actions' => []]]), '<b>x</b>'), 'email block escapes text');

$GLOBALS['sent'] = [];
$r = bcrf_task_digest_run($pdo);
$to = array_column($GLOBALS['sent'], 'to');
$ok(in_array('zac@x.test', $to, true), 'Owner with no tasks still gets the email when there are problems');
$ok(!in_array('mo@x.test', $to, true) && !in_array('sam@x.test', $to, true), 'Manager and Staff with no tasks: no email');
$zac = array_values(array_filter($GLOBALS['sent'], fn($m) => $m['to'] === 'zac@x.test'))[0] ?? null;
$ok($zac && str_contains($zac['subject'], 'problem') && !str_contains($zac['subject'], 'task'), 'subject counts problems only: ' . ($zac['subject'] ?? ''));
$GLOBALS['sent'] = [];
bcrf_task_digest_run($pdo);
$ok(!in_array('zac@x.test', array_column($GLOBALS['sent'], 'to'), true), 'once per farm day');
$pdo->exec("DELETE FROM task_user_prefs");
$pdo->exec("INSERT INTO task_user_prefs (user_id, digest, late_alerts, updated_at) VALUES (1, 0, 1, UTC_TIMESTAMP())");
$GLOBALS['sent'] = [];
bcrf_task_digest_run($pdo);
$ok(!in_array('zac@x.test', array_column($GLOBALS['sent'], 'to'), true), 'morning email turned off = no problems email either');

// ── Dashboard uses the shared function ──
$dash = file_get_contents($root . '/admin/views/dashboard.php');
$ok(str_contains($dash, 'bcrf_action_alerts(') && !str_contains($dash, "'id' => 'cron'"), 'dashboard builds its list from includes/action_center.php');

// ── Staff settings: "not ready" is not remembered ──
$pdo->exec("RENAME TABLE staff_prefs TO staff_prefs_away");
$ok(!bcrf_staff_prefs_ready($pdo), 'no table: not ready');
$pdo->exec("RENAME TABLE staff_prefs_away TO staff_prefs");
$ok(bcrf_staff_prefs_ready($pdo), 'table made later in the same run: ready');

echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
