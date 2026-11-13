<?php
// tests/search_test.php — Farm OS global search (includes/search.php, Nov 13, 2026) against a real, THROWAWAY MySQL 8
// database: who sees which kinds of records, codes that jump straight to a record, phone numbers, LIKE wildcards.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/search_test.php
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
require_once $root . '/includes/search.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };

// -- Data --
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['customers', 'tickets', 'pass_types', 'passes', 'sales', 'refunds', 'gift_cards', 'inventory', 'animals', 'kitchen_batches', 'users', 'organizations', 'bookings', 'event_inquiries'] as $t) $pdo->exec("DELETE FROM `$t`");
$pdo->exec("INSERT INTO customers (id, first_name, last_name, email, phone_number, password, created_at) VALUES
    (1, 'Emily', 'Jenkins', 'emily@example.test', '(828) 555-0101', '', NOW()),
    (2, 'Sam', 'Percent', 'sam@example.test', NULL, '', NOW()),
    (3, 'Old', 'Copy', 'emily.old@example.test', NULL, '', NOW())");
$pdo->exec("UPDATE customers SET merged_into_id = 1 WHERE id = 3");
$pdo->exec("INSERT INTO tickets (id, public_code, source, purchaser_name, purchaser_email, purchaser_phone, quantity, quantity_redeemed, status, expires_at, amount_cents, created_at, payment_status)
            VALUES (1, 'ABCDEFGH23', 'online', 'Emily Jenkins', 'emily@example.test', '828-555-0101', 2, 0, 'paid', DATE_ADD(NOW(), INTERVAL 300 DAY), 1600, NOW(), 'paid')");
$tok = str_repeat('ab12', 12);
$pdo->exec("INSERT INTO pass_types (id, type_key, label, price, max_guests) VALUES (1, 'family', 'Family', 100, 4)");
$pdo->prepare("INSERT INTO passes (pass_type_id, holder_name, email, token, valid_from, valid_until, status, created_at) VALUES (1, 'Emily Jenkins', 'emily@example.test', ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 'active', NOW())")->execute([$tok]);
$pdo->exec("INSERT INTO sales (id, sale_number, channel, customer_id, status, subtotal_cents, total_cents, business_date, completed_at, idempotency_key, created_at)
            VALUES (1, 'R-2026-000012', 'pos', 1, 'completed', 1600, 1600, CURDATE(), NOW(), 'k1', NOW())");
$pdo->exec("INSERT INTO refunds (id, refund_number, sale_id, business_date, net_cents, tax_cents, total_cents, reason, idempotency_key, created_at)
            VALUES (1, 'RF-2026-000003', 1, CURDATE(), 500, 0, 500, 'test', 'r1', NOW())");
$pdo->exec("INSERT INTO gift_cards (id, code, status, source, initial_cents, balance_cents, recipient_name, created_at) VALUES (1, 'ABCDEFGHJKLMNPQR', 'active', 'pos', 2500, 2000, 'Grandma Jenkins', NOW())");
$pdo->exec("INSERT INTO inventory (id, category, name, sku, price, stock_quantity, track_inventory, is_active) VALUES (1, 'Zoo', 'Goat feed cup', 'BCF-001', 1.00, 40, 1, 1), (2, 'Pantry', 'Strawberry jam', 'JAM', 8.00, 0, 1, 1)");
for ($i = 1; $i <= 7; $i++) $pdo->exec("INSERT INTO inventory (category, name, sku, price, is_active) VALUES ('Zoo', 'Feed bag $i', 'FB$i', 5, 1)");
$pdo->exec("INSERT INTO animals (id, name, breed_id, classification_id, date_of_birth, status, tag, is_active) VALUES (1, 'Hazel', 1, 1, '2022-04-01', 'active', 'G-7', 1)");
$pdo->exec("INSERT INTO kitchen_batches (id, lot_code, inventory_id, recipe_id, recipe_version_id, batches, units_made, made_on, created_at) VALUES (1, '261112-JAM-1', 2, 1, 1, 1, 12, '2026-11-12', NOW())");
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, password, role) VALUES (5, 'Casey', 'Farmer', 'casey@example.test', '', 'administrator')");
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$admin = fn(array $c) => true;
$role = function (array $views, array $tables = []) {
    return function (array $c) use ($views, $tables): bool {
        if ($c[0] === 'view') return in_array($c[1], $views, true);
        if ($c[0] === 'table') return in_array($c[1] . ':' . ($c[2] ?? 'view'), $tables, true);
        return false;
    };
};
$shopkeep = $role(['pos']);
$keys = fn(array $r) => array_column($r['groups'], 'key');
$group = function (array $r, string $k) { foreach ($r['groups'] as $g) if ($g['key'] === $k) return $g; return null; };

// -- Who sees what --
$r = bcrf_search($pdo, 'Jenkins', $admin);
$ok(array_values(array_intersect(['customers', 'tickets', 'passes', 'sales', 'gift_cards'], $keys($r))) === ['customers', 'tickets', 'passes', 'sales', 'gift_cards'], 'owner sees customers, tickets, passes, sales, gift cards: ' . implode(',', $keys($r)));
$ok(count($group($r, 'customers')['rows']) === 1 && $group($r, 'customers')['rows'][0]['url'] === '/admin/customers.php?id=1', 'merged duplicate left out; links to the customer');
$r = bcrf_search($pdo, 'Jenkins', $shopkeep);
$ok($keys($r) === ['tickets', 'gift_cards'], 'shopkeep (register only) sees tickets and gift cards, not customers, passes or sales: ' . implode(',', $keys($r)));
$r = bcrf_search($pdo, 'Hazel', $shopkeep);
$ok($r['groups'] === [], 'animals hidden without animals access');
$r = bcrf_search($pdo, 'Hazel', $role([], ['animals:view']));
$ok($keys($r) === ['animals'] && $group($r, 'animals')['rows'][0]['url'] === '/admin/animals.php?id=1', 'animals with animals access');
$r = bcrf_search($pdo, 'Casey', $role(['customers', 'pos', 'passes']));
$ok(!in_array('staff', $keys($r), true), 'staff list is Owners only');
$r = bcrf_search($pdo, 'Casey', $admin);
$ok(in_array('staff', $keys($r), true) && str_contains($group($r, 'staff')['rows'][0]['sub'], 'Owner'), 'Owner finds staff, role shown by its display name');

// -- Phone numbers, wildcards, short searches --
$r = bcrf_search($pdo, '555-0101', $admin);
$ok($group($r, 'customers') !== null && $group($r, 'tickets') !== null, 'phone typed with dashes finds (828) 555-0101');
$r = bcrf_search($pdo, '%', $admin);
$ok($r['groups'] === [], 'a lone % is too short');
$r = bcrf_search($pdo, '%%', $admin);
$ok($r['groups'] === [], '%% matches nothing (wildcards are escaped)');
$r = bcrf_search($pdo, 'e', $admin);
$ok($r['groups'] === [] && $r['jump'] === null, 'one letter searches nothing');

// -- Limits --
$r = bcrf_search($pdo, 'Feed', $admin);
$g = $group($r, 'products');
$ok($g && count($g['rows']) === BCRF_SEARCH_PER_GROUP && $g['more'] === '/admin/products.php?q=Feed', 'five per group with a See all link');
$r = bcrf_search($pdo, 'Goat feed', $role(['inventory_update']));
$ok(($group($r, 'products')['rows'][0]['url'] ?? '') === '/admin/stock.php?id=1', 'count-only staff get the Stock page, not the product editor');

// -- Codes jump --
$j = bcrf_search_jump($pdo, 'R-2026-000012', $admin);
$ok($j && $j['url'] === '/admin/admin.php?view=receipts&search_q=R-2026-000012', 'receipt number jumps to the sale');
$j = bcrf_search_jump($pdo, 'r-2026-12', $admin);
$ok($j && str_contains($j['url'], 'R-2026-000012'), 'short receipt number is padded');
$j = bcrf_search_jump($pdo, 'RF-2026-000003', $admin);
$ok($j && str_contains($j['url'], 'R-2026-000012') && str_contains($j['label'], 'Refund'), 'refund number opens its sale');
$ok(bcrf_search_jump($pdo, 'R-2026-000012', $shopkeep) === null, 'no sales access, no receipt jump');
$j = bcrf_search_jump($pdo, 'abcd-efgh-23', $shopkeep);
$ok($j && $j['url'] === '/admin/gate.php?q=ABCDEFGH23', 'ticket code with dashes, any case, opens it at the gate');
$j = bcrf_search_jump($pdo, 'https://example.test/tickets/view.php?c=ABCD-EFGH-23', $shopkeep);
$ok($j && str_contains($j['url'], 'ABCDEFGH23'), 'ticket link jumps');
$j = bcrf_search_jump($pdo, 'ABCD-EFGH-JKLM-NPQR', $shopkeep);
$ok($j && $j['url'] === '/admin/gift_cards.php?id=1' && str_contains($j['label'], '$20.00'), 'gift card code jumps, with its balance');
$j = bcrf_search_jump($pdo, 'https://example.test/passes/verify.php?token=' . $tok, $shopkeep);
$ok($j && str_contains($j['url'], 'gate.php?q=' . $tok), 'pass link jumps to the gate');
$j = bcrf_search_jump($pdo, '261112-jam-1', $role([], ['recipes:view']));
$ok($j && $j['url'] === '/admin/kitchen_trace.php?lot=261112-JAM-1', 'lot code jumps to the trace');
$ok(bcrf_search_jump($pdo, '261112-JAM-1', $shopkeep) === null, 'no kitchen access, no lot jump');
$ok(bcrf_search_jump($pdo, 'ZZZZ-ZZZZ-ZZ', $admin) === null, 'unknown code does not jump');
$r = bcrf_search($pdo, 'R-2026-000012', $admin);
$ok($r['jump'] !== null, 'bcrf_search carries the jump');

// -- Every kind's query runs on the real schema --
$logf = tempnam(sys_get_temp_dir(), 'srch');
ini_set('log_errors', '1'); ini_set('error_log', $logf);
foreach (['zz', 'Jenkins', '555-0101', 'Hazel', 'Feed'] as $q) bcrf_search($pdo, $q, $admin);
ini_set('log_errors', '0');
$errs = trim((string)file_get_contents($logf)); @unlink($logf);
$ok($errs === '', 'no SQL errors in any kind: ' . $errs);

// -- Default $can --
$c = bcrf_search_can_for('user');
$ok(!$c(['view', 'customers']), 'a public account can open nothing');
$c = bcrf_search_can_for('administrator');
$ok($c(['admin']) && $c(['table', 'animals']), 'administrator can open everything');

echo $n . ' checks, ' . ($fails ? $fails . ' FAILED' : '0 failed') . "\n";
exit($fails ? 1 : 0);
