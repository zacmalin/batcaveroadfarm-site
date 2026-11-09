<?php
// tests/rewards_test.php — Roost Rewards (lean) rules against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_test DB_USER=... DB_PASS=... php tests/rewards_test.php
// Refuses to run unless DB_NAME ends in "_test". Drops and recreates only the tables it uses.
// Exit 0 = all passed.
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '',
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Stand-ins for the site mailer and farm clock, so the test needs nothing else from the site.
$GLOBALS['mails'] = [];
function bcf_send_mail(string $to, string $subject, string $html, array $opts = []): bool { $GLOBALS['mails'][] = [$to, $subject]; return true; }
function bcrf_farm_now(): array { $n = new DateTimeImmutable('now'); return [$n, $n->format('Y-m-d'), (int)$n->format('Y')]; }
require __DIR__ . '/../includes/rewards.php';
ini_set('log_errors', '0');   // a guarded failure is expected in one test

// Minimal copies of the columns rewards reads from the real tables (0006 customers, 0009 sales).
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
foreach (['reward_ledger', 'reward_members', 'reward_tiers', 'sale_lines', 'sales', 'customers', 'site_settings'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
$pdo->exec("CREATE TABLE customers (id INT AUTO_INCREMENT PRIMARY KEY, first_name VARCHAR(50) NOT NULL, last_name VARCHAR(50) NOT NULL, email VARCHAR(100) NOT NULL,
            phone_number VARCHAR(20) NULL, account_status ENUM('active','disabled') NOT NULL DEFAULT 'active') ENGINE=InnoDB");
$pdo->exec("CREATE TABLE sales (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, sale_number VARCHAR(30) NOT NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE sale_lines (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, sale_id BIGINT UNSIGNED NOT NULL, product_type VARCHAR(20) NOT NULL,
            gross_cents BIGINT NOT NULL, discount_cents BIGINT NOT NULL DEFAULT 0, refunded_net_cents BIGINT NOT NULL DEFAULT 0) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE site_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT) ENGINE=InnoDB");
(require __DIR__ . '/../admin/migrations/0016_roost_rewards.php')($pdo);
(require __DIR__ . '/../admin/migrations/0016_roost_rewards.php')($pdo);   // safe to repeat

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$throws = function (callable $f, string $what) use ($ok) { try { $f(); $ok(false, "$what (no error)"); } catch (BcrfRewardError $e) { $ok(true, $what); } };
$bal = function (int $cid) use ($pdo) { $st = $pdo->prepare("SELECT balance_points FROM reward_members WHERE customer_id = ?"); $st->execute([$cid]); return (int)$st->fetchColumn(); };
$ledgerSum = function (int $cid) use ($pdo) { $st = $pdo->prepare("SELECT COALESCE(SUM(points),0) FROM reward_ledger WHERE customer_id = ?"); $st->execute([$cid]); return (int)$st->fetchColumn(); };
$sale = function (array $lines) use ($pdo): int {   // [[type, gross, discount], ...]
    $pdo->exec("INSERT INTO sales (sale_number) VALUES ('R-TEST')");
    $id = (int)$pdo->lastInsertId();
    $st = $pdo->prepare("INSERT INTO sale_lines (sale_id, product_type, gross_cents, discount_cents) VALUES (?, ?, ?, ?)");
    foreach ($lines as $l) $st->execute([$id, $l[0], $l[1], $l[2] ?? 0]);
    return $id;
};
$inTx = function (callable $f) use ($pdo) { $pdo->beginTransaction(); $f(); $pdo->commit(); };
$today = bcrf_farm_now()[1];

// 1. Arithmetic and phone numbers
$ok(bcrf_rewards_points_for_cents(1299) === 64, '$12.99 → 64 points');
$ok(bcrf_rewards_points_for_cents(100) === 5 && bcrf_rewards_points_for_cents(19) === 0 && bcrf_rewards_points_for_cents(20) === 1, 'rounded down per sale');
$ok(bcrf_rewards_phone_key('(828) 555-0101') === '8285550101' && bcrf_rewards_phone_key('+1 828-555-0101') === '8285550101', 'phone normalised');
$ok(bcrf_rewards_phone_key('555-0101') === null, 'short phone rejected');

// 2. Enrollment
$pdo->exec("INSERT INTO customers (first_name, last_name, email) VALUES ('Ann','Hen','ann@example.com'), ('Bo','Hen','bo@example.com'), ('Cy','Coop','cy@example.com')");
[$ann, $bo, $cy] = [1, 2, 3];
$ok(bcrf_rewards_enroll($pdo, $ann, '828-555-0101', 7)['new'] === true, 'enroll');
$ok(count($GLOBALS['mails']) === 1 && $GLOBALS['mails'][0][0] === 'ann@example.com', 'one welcome email');
$ok(bcrf_rewards_enroll($pdo, $ann, '8285550101', 7)['new'] === false && count($GLOBALS['mails']) === 1, 're-enroll is a no-op, no second email');
$throws(fn() => bcrf_rewards_enroll($pdo, $bo, '(828) 555-0101', 7), 'second account on the same phone refused');
$st = $pdo->query("SELECT phone_number FROM customers WHERE id = 1"); $ok($st->fetchColumn() === '8285550101', 'customer phone set for register search');

// 3. OFF: nothing posts
$s0 = $sale([['retail', 1000]]);
$inTx(fn() => bcrf_rewards_after_sale($pdo, $s0, $ann, $today));
$ok($bal($ann) === 0, 'no points while Roost Rewards is OFF');
$pdo->exec("INSERT INTO site_settings VALUES ('rewards_enabled','1')");

// 4. Purchase + visit
$s1 = $sale([['retail', 1299], ['gift_card', 2000], ['admission', 900, 100], ['pass', 5000]]);
$inTx(fn() => bcrf_rewards_after_sale($pdo, $s1, $ann, $today));
$ok($bal($ann) === 104 + 25, 'eligible $20.99 → 104 + 25 visit (gift card and pass excluded, discount applied)');
$inTx(fn() => bcrf_rewards_after_sale($pdo, $s1, $ann, $today));
$ok($bal($ann) === 129, 'same sale twice posts once');
$s2 = $sale([['admission', 800], ['produce', 450]]);
$inTx(fn() => bcrf_rewards_after_sale($pdo, $s2, $ann, $today));
$ok($bal($ann) === 129 + 62, 'second admission the same day: purchase points, no second visit');
$ok(bcrf_rewards_staff_visit($pdo, $ann) === false, 'staff visit button respects one visit per day');
$sNot = $sale([['retail', 5000]]);
$inTx(fn() => bcrf_rewards_after_sale($pdo, $sNot, $cy, $today));
$ok((int)$pdo->query("SELECT COUNT(*) FROM reward_ledger WHERE customer_id = 3")->fetchColumn() === 0, 'non-member earns nothing');

// 5. Refunds (partial, then the rest) and void
$pdo->exec("UPDATE sale_lines SET refunded_net_cents = 1299 WHERE sale_id = $s1 AND product_type = 'retail'");
$inTx(fn() => bcrf_rewards_after_refund($pdo, $s1, 1));
$ok($bal($ann) === 191 - 64, 'partial refund takes back 64 (104 → 40 held for $8.00)');
$inTx(fn() => bcrf_rewards_after_refund($pdo, $s1, 1));
$ok($bal($ann) === 127, 'same refund twice posts once');
$pdo->exec("UPDATE sale_lines SET refunded_net_cents = 800 WHERE sale_id = $s1 AND product_type = 'admission'");
$inTx(fn() => bcrf_rewards_after_refund($pdo, $s1, 2));
$ok($bal($ann) === 87, 'refund of the rest takes back the last 40; the visit stays');
$inTx(fn() => bcrf_rewards_after_void($pdo, $s2));
$ok($bal($ann) === 87 - 62, 'void takes back the purchase points of that sale');

// 6. Redemption at the counter
$throws(fn() => bcrf_rewards_redeem($pdo, $ann, 250, bin2hex(random_bytes(16))), 'tier without a reward cannot be used');
$pdo->exec("UPDATE reward_tiers SET benefit = 'Free bag of feed', active = 1 WHERE points = 250");
$throws(fn() => bcrf_rewards_redeem($pdo, $ann, 250, bin2hex(random_bytes(16))), 'not enough points');
bcrf_rewards_adjust($pdo, $ann, 300, 'Test top-up', bin2hex(random_bytes(16)));
$throws(fn() => bcrf_rewards_adjust($pdo, $ann, 10, '  ', bin2hex(random_bytes(16))), 'adjustment needs a reason');
$k = bin2hex(random_bytes(16));
$r = bcrf_rewards_redeem($pdo, $ann, 250, $k);
$ok($r['posted'] && $bal($ann) === 325 - 250, 'redeem 250');
$ok(bcrf_rewards_redeem($pdo, $ann, 250, $k)['posted'] === false && $bal($ann) === 75, 'double tap redeems once');

// 7. Negative balance after a refund
$s3 = $sale([['retail', 4000]]);
$inTx(fn() => bcrf_rewards_after_sale($pdo, $s3, $ann, $today));
bcrf_rewards_redeem($pdo, $ann, 250, bin2hex(random_bytes(16)));   // 75 + 200 = 275 → 25
$pdo->exec("UPDATE sale_lines SET refunded_net_cents = 4000 WHERE sale_id = $s3");
$inTx(fn() => bcrf_rewards_after_refund($pdo, $s3, 3));
$ok($bal($ann) === -175, 'refund after redemption leaves a negative balance');
$throws(fn() => bcrf_rewards_redeem($pdo, $ann, 250, bin2hex(random_bytes(16))), 'no redemption while negative');

// 8. Expiry
bcrf_rewards_enroll($pdo, $cy, '828 555 0303', null);
bcrf_rewards_adjust($pdo, $cy, 400, 'Test', bin2hex(random_bytes(16)));
$pdo->exec("UPDATE reward_members SET last_activity_at = UTC_TIMESTAMP() - INTERVAL 11 MONTH WHERE customer_id = 3");
$ok(bcrf_rewards_expire($pdo) === 0 && $bal($cy) === 400, '11 months quiet: nothing expires');
$pdo->exec("UPDATE reward_members SET last_activity_at = UTC_TIMESTAMP() - INTERVAL 13 MONTH WHERE customer_id = 3");
$ok(bcrf_rewards_expire($pdo) === 1 && $bal($cy) === 0, '13 months quiet: balance expires');
$ok(bcrf_rewards_expire($pdo) === 0, 'expiry runs once');
$pdo->exec("UPDATE reward_members SET last_activity_at = UTC_TIMESTAMP() - INTERVAL 13 MONTH WHERE customer_id = 1");
$ok(bcrf_rewards_expire($pdo) === 0 && $bal($ann) === -175, 'negative balances are left alone');

// 9. A rewards failure never blocks the sale
$pdo->beginTransaction();
$pdo->exec("INSERT INTO sales (sale_number) VALUES ('R-KEEP')");
bcrf_rewards_guarded($pdo, 'test', function () use ($pdo) {
    bcrf_rewards_post($pdo, 1, 'adjust', 5, 'adjust:should-roll-back');
    throw new RuntimeException('boom');
});
$pdo->commit();
$ok((int)$pdo->query("SELECT COUNT(*) FROM sales WHERE sale_number = 'R-KEEP'")->fetchColumn() === 1, 'sale commits when rewards fails');
$ok((int)$pdo->query("SELECT COUNT(*) FROM reward_ledger WHERE ref_key = 'adjust:should-roll-back'")->fetchColumn() === 0, 'failed rewards writes are rolled back');

// 10. Cached balance always equals the ledger
foreach ([$ann, $cy] as $c) $ok($bal($c) === $ledgerSum($c), "balance matches ledger for customer $c");

echo $fails ? "$fails of $n checks FAILED\n" : "All $n checks passed\n";
exit($fails ? 1 : 0);
