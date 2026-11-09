<?php
// includes/rewards.php — Roost Rewards (lean version, Nov 9, 2026). Tables: admin/migrations/0016_roost_rewards.php
//
// Rules (approved policy, not to be changed here):
//   • 5 points per eligible dollar, rounded down per sale, no carryover (eligible = pre-tax, after discounts,
//     product types retail, produce, kitchen_good, admission, thrift_item; passes, gift cards, fees and services earn nothing)
//   • 25 points per visit, at most one per member per farm day (automatic on an admission sale, or the staff "Visit" button)
//   • One account per phone number; families share it by giving the same number
//   • Refunds take back purchase points; the balance may go negative
//   • 12 months with no purchase or visit: the remaining positive balance expires (admin/cron/rewards_expire.php)
//   • Staff redeem at the counter: 250, 500, 1,000 or 2,000 points, never below zero
//
// Sales and refunds call bcrf_rewards_after_sale() / bcrf_rewards_after_refund() inside THEIR transaction, wrapped
// in a savepoint: a rewards problem is logged and never blocks the sale. Staff can add missed points with an adjustment.

if (!class_exists('BcrfRewardError')) { class BcrfRewardError extends RuntimeException {} }

const BCRF_REWARD_POINTS_PER_DOLLAR = 5;
const BCRF_REWARD_VISIT_POINTS = 25;
const BCRF_REWARD_EXPIRE_MONTHS = 12;
const BCRF_REWARD_ELIGIBLE_TYPES = ['retail', 'produce', 'kitchen_good', 'admission', 'thrift_item'];

function bcrf_rewards_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready === null) {
        try { $ready = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'reward_tiers'")->fetchColumn(); }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

/** Master switch: Admin › Roost Rewards › Settings. Off unless set to 1. */
function bcrf_rewards_enabled(PDO $pdo): bool {
    if (!bcrf_rewards_ready($pdo)) return false;
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'rewards_enabled'");
        $st->execute();
        return (string)$st->fetchColumn() === '1';
    } catch (Throwable $e) { return false; }
}

/** Digits only; a leading US 1 on an 11-digit number is dropped. Null if it isn't a usable phone number. */
function bcrf_rewards_phone_key(string $phone): ?string {
    $d = preg_replace('/\D/', '', $phone);
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    return (strlen($d) >= 10 && strlen($d) <= 15) ? $d : null;
}

/** floor(cents × 5 ÷ 100): $12.99 → 64 points. */
function bcrf_rewards_points_for_cents(int $cents): int {
    return $cents > 0 ? intdiv($cents * BCRF_REWARD_POINTS_PER_DOLLAR, 100) : 0;
}

/** Eligible cents still standing on a sale (gross − discount − refunded, eligible product types only). */
function bcrf_rewards_eligible_cents(PDO $pdo, int $saleId): int {
    $in = implode(',', array_fill(0, count(BCRF_REWARD_ELIGIBLE_TYPES), '?'));
    $st = $pdo->prepare("SELECT COALESCE(SUM(gross_cents - discount_cents - refunded_net_cents), 0) FROM sale_lines WHERE sale_id = ? AND product_type IN ($in)");
    $st->execute(array_merge([$saleId], BCRF_REWARD_ELIGIBLE_TYPES));
    return max(0, (int)$st->fetchColumn());
}

function bcrf_rewards_member(PDO $pdo, int $customerId, bool $lock = false): ?array {
    $st = $pdo->prepare("SELECT m.*, c.first_name, c.last_name, c.email, c.phone_number FROM reward_members m JOIN customers c ON c.id = m.customer_id
                         WHERE m.customer_id = ?" . ($lock ? " FOR UPDATE" : ""));
    $st->execute([$customerId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Post one ledger entry and move the cached balance. Caller holds a transaction and the member row lock.
 * Returns false when ref_key was already posted (replay), true when posted.
 */
function bcrf_rewards_post(PDO $pdo, int $customerId, string $type, int $points, string $refKey, ?int $saleId = null, ?string $note = null, bool $activity = false): bool {
    if ($points === 0) return false;
    $uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
    $ins = $pdo->prepare("INSERT IGNORE INTO reward_ledger (customer_id, entry_type, points, sale_id, ref_key, note, created_at, created_by)
                          VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)");
    $ins->execute([$customerId, $type, $points, $saleId, $refKey, $note !== null ? mb_substr($note, 0, 255) : null, $uid]);
    if ($ins->rowCount() === 0) return false;
    $pdo->prepare("UPDATE reward_members SET balance_points = balance_points + ?" . ($activity ? ", last_activity_at = UTC_TIMESTAMP()" : "") . " WHERE customer_id = ?")
        ->execute([$points, $customerId]);
    return true;
}

/** Run $fn inside a savepoint of the caller's transaction; on any error roll back only the rewards writes and log it. */
function bcrf_rewards_guarded(PDO $pdo, string $what, callable $fn): void {
    if (!bcrf_rewards_enabled($pdo)) return;
    $pdo->exec("SAVEPOINT bcrf_rewards");
    try {
        $fn();
        $pdo->exec("RELEASE SAVEPOINT bcrf_rewards");
    } catch (Throwable $e) {
        $pdo->exec("ROLLBACK TO SAVEPOINT bcrf_rewards");
        error_log('[BCRF rewards] ' . $what . ' skipped: ' . $e->getMessage());
    }
}

/** Visit points for a farm day (business date YYYY-MM-DD). Caller holds the member lock. */
function bcrf_rewards_visit_locked(PDO $pdo, int $customerId, string $bdate, ?int $saleId, string $note): bool {
    return bcrf_rewards_post($pdo, $customerId, 'visit', BCRF_REWARD_VISIT_POINTS, "visit:$customerId:$bdate", $saleId, $note, true);
}

/** Called from bcrf_sale_complete() before COMMIT. */
function bcrf_rewards_after_sale(PDO $pdo, int $saleId, ?int $customerId, string $bdate): void {
    if (!$customerId) return;
    bcrf_rewards_guarded($pdo, "sale $saleId", function () use ($pdo, $saleId, $customerId, $bdate) {
        if (!bcrf_rewards_member($pdo, $customerId, true)) return;   // not enrolled
        $pts = bcrf_rewards_points_for_cents(bcrf_rewards_eligible_cents($pdo, $saleId));
        bcrf_rewards_post($pdo, $customerId, 'purchase', $pts, "sale:$saleId", $saleId, null, true);
        $st = $pdo->prepare("SELECT COUNT(*) FROM sale_lines WHERE sale_id = ? AND product_type = 'admission'");
        $st->execute([$saleId]);
        if ((int)$st->fetchColumn() > 0) bcrf_rewards_visit_locked($pdo, $customerId, $bdate, $saleId, 'Admission');
    });
}

/** Called from bcrf_refund_complete() before COMMIT, after sale_lines.refunded_* are updated. */
function bcrf_rewards_after_refund(PDO $pdo, int $saleId, int $refundId): void {
    bcrf_rewards_guarded($pdo, "refund $refundId", function () use ($pdo, $saleId, $refundId) {
        // Points go back to whoever earned them on this sale, even if the sale's customer changed later.
        $st = $pdo->prepare("SELECT customer_id FROM reward_ledger WHERE ref_key = ?");
        $st->execute(["sale:$saleId"]);
        $customerId = (int)$st->fetchColumn();
        if (!$customerId || !bcrf_rewards_member($pdo, $customerId, true)) return;
        // Net purchase points still held for this sale vs. what the remaining eligible amount is worth.
        $st = $pdo->prepare("SELECT COALESCE(SUM(points), 0) FROM reward_ledger WHERE sale_id = ? AND entry_type IN ('purchase','refund')");
        $st->execute([$saleId]);
        $held = (int)$st->fetchColumn();
        $back = $held - bcrf_rewards_points_for_cents(bcrf_rewards_eligible_cents($pdo, $saleId));
        if ($back > 0) bcrf_rewards_post($pdo, $customerId, 'refund', -$back, "refund:$refundId", $saleId);
    });
}

/** Called from the void in includes/refunds.php before COMMIT: reverse everything the sale earned (purchase, refunds and its visit). */
function bcrf_rewards_after_void(PDO $pdo, int $saleId): void {
    bcrf_rewards_guarded($pdo, "void $saleId", function () use ($pdo, $saleId) {
        $st = $pdo->prepare("SELECT customer_id, COALESCE(SUM(points), 0) AS pts FROM reward_ledger WHERE sale_id = ? AND entry_type IN ('purchase','refund','visit') GROUP BY customer_id");
        $st->execute([$saleId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((int)$r['pts'] <= 0 || !bcrf_rewards_member($pdo, (int)$r['customer_id'], true)) continue;
            bcrf_rewards_post($pdo, (int)$r['customer_id'], 'refund', -(int)$r['pts'], "void:$saleId", $saleId, 'Sale voided');
        }
    });
}

/** Wrap a staff action in its own transaction with the member locked. */
function bcrf_rewards_tx(PDO $pdo, int $customerId, callable $fn) {
    $pdo->beginTransaction();
    try {
        $m = bcrf_rewards_member($pdo, $customerId, true);
        if (!$m) throw new BcrfRewardError('That customer is not a Roost Rewards member.');
        $out = $fn($m);
        $pdo->commit();
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Staff "Visit" button. Returns false if this member already got today's visit. */
function bcrf_rewards_staff_visit(PDO $pdo, int $customerId): bool {
    $bdate = function_exists('bcrf_farm_now') ? bcrf_farm_now()[1] : date('Y-m-d');
    return bcrf_rewards_tx($pdo, $customerId, fn() => bcrf_rewards_visit_locked($pdo, $customerId, $bdate, null, 'Visit (staff)'));
}

/** Redeem a tier at the counter. $key: a one-time form token so a double tap can't redeem twice. */
function bcrf_rewards_redeem(PDO $pdo, int $customerId, int $tierPoints, string $key): array {
    if (!preg_match('/^[a-f0-9]{16,64}$/', $key)) throw new BcrfRewardError('Reload the page and try again.');
    $st = $pdo->prepare("SELECT points, benefit FROM reward_tiers WHERE points = ? AND active = 1 AND benefit <> ''");
    $st->execute([$tierPoints]);
    $tier = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tier) throw new BcrfRewardError('That reward tier is not turned on.');
    return bcrf_rewards_tx($pdo, $customerId, function (array $m) use ($pdo, $customerId, $tier, $key) {
        $done = $pdo->prepare("SELECT 1 FROM reward_ledger WHERE ref_key = ?");
        $done->execute(["redeem:$key"]);
        if ($done->fetchColumn()) return ['posted' => false, 'benefit' => $tier['benefit']];   // same form submitted twice
        if ((int)$m['balance_points'] < (int)$tier['points']) throw new BcrfRewardError('Not enough points for that reward.');
        $posted = bcrf_rewards_post($pdo, $customerId, 'redeem', -(int)$tier['points'], "redeem:$key", null, $tier['benefit']);
        return ['posted' => $posted, 'benefit' => $tier['benefit']];
    });
}

/** Manual correction (missed points, goodwill). Needs a reason. */
function bcrf_rewards_adjust(PDO $pdo, int $customerId, int $points, string $reason, string $key): bool {
    $reason = trim($reason);
    if ($points === 0 || abs($points) > 100000) throw new BcrfRewardError('Enter a number of points (use minus to take points away).');
    if ($reason === '') throw new BcrfRewardError('Give a reason for the adjustment.');
    if (!preg_match('/^[a-f0-9]{16,64}$/', $key)) throw new BcrfRewardError('Reload the page and try again.');
    return bcrf_rewards_tx($pdo, $customerId, fn() => bcrf_rewards_post($pdo, $customerId, 'adjust', $points, "adjust:$key", null, $reason));
}

/** Enroll an existing customer. Phone is required and must not belong to another member. */
function bcrf_rewards_enroll(PDO $pdo, int $customerId, string $phone, ?int $staffId): array {
    $key = bcrf_rewards_phone_key($phone);
    if (!$key) throw new BcrfRewardError('Enter a 10-digit phone number.');
    $st = $pdo->prepare("SELECT id, email, account_status FROM customers WHERE id = ?");
    $st->execute([$customerId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c || $c['account_status'] !== 'active') throw new BcrfRewardError('Customer not found.');
    $st = $pdo->prepare("SELECT customer_id FROM reward_members WHERE phone_key = ?");
    $st->execute([$key]);
    $owner = $st->fetchColumn();
    if ($owner !== false && (int)$owner !== $customerId) throw new BcrfRewardError('That phone number is already on another Roost Rewards account. Use that account instead.');
    if ($owner !== false) return ['new' => false];
    try {
        $pdo->prepare("INSERT INTO reward_members (customer_id, phone_key, enrolled_at, enrolled_by) VALUES (?, ?, UTC_TIMESTAMP(), ?)")
            ->execute([$customerId, $key, $staffId]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') throw new BcrfRewardError('This customer or phone number is already enrolled.');
        throw $e;
    }
    // The rewards number becomes the customer's phone, so the register's customer search finds them by it.
    $pdo->prepare("UPDATE customers SET phone_number = ? WHERE id = ?")->execute([$key, $customerId]);
    bcrf_rewards_send_welcome($pdo, $customerId);
    return ['new' => true];
}

/** One welcome email through the site mailer. Never throws; marks welcome_sent_at so it goes once. */
function bcrf_rewards_send_welcome(PDO $pdo, int $customerId): void {
    try {
        $m = bcrf_rewards_member($pdo, $customerId);
        if (!$m || $m['welcome_sent_at'] || !filter_var($m['email'], FILTER_VALIDATE_EMAIL)) return;
        $tpl = dirname(__DIR__) . '/admin/includes/email_template.php';
        if (!function_exists('bcf_send_mail')) { if (!is_file($tpl)) return; require_once $tpl; }
        $site = function_exists('bcrf_farm_url') ? bcrf_farm_url() : 'https://batcaveroadfarm.com';
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
        $tiers = $pdo->query("SELECT points, benefit FROM reward_tiers WHERE active = 1 AND benefit <> '' ORDER BY points")->fetchAll(PDO::FETCH_ASSOC);
        $list = '';
        foreach ($tiers as $t) $list .= '<li><strong>' . number_format((int)$t['points']) . ' points</strong> — ' . $h($t['benefit']) . '</li>';
        $html = '<p>Hi ' . $h($m['first_name']) . ',</p>'
              . '<p>Welcome to <strong>Roost Rewards</strong>! You earn 5 points for every dollar you spend at the farm and 25 points each time you visit.</p>'
              . '<p>Just give your phone number at the counter. Anyone in your family can use the same number, and everyone\'s points add up together.</p>'
              . ($list ? '<p>What your points get you:</p><ul>' . $list . '</ul>' : '')
              . '<p>Check your points any time at <a href="' . $h($site) . '/roost-rewards/">' . $h($site) . '/roost-rewards/</a>.</p>'
              . '<p>Points expire after 12 months with no purchases or visits.</p>';
        if (bcf_send_mail($m['email'], 'Welcome to Roost Rewards', $html, ['subtitle' => 'Roost Rewards', 'unsubscribe' => false])) {
            $pdo->prepare("UPDATE reward_members SET welcome_sent_at = UTC_TIMESTAMP() WHERE customer_id = ?")->execute([$customerId]);
        }
    } catch (Throwable $e) {
        error_log('[BCRF rewards] welcome email: ' . $e->getMessage());
    }
}

/** Expire positive balances after 12 months without earning. Returns how many members expired. */
function bcrf_rewards_expire(PDO $pdo): int {
    $st = $pdo->prepare("SELECT customer_id FROM reward_members WHERE balance_points > 0
                         AND COALESCE(last_activity_at, enrolled_at) < UTC_TIMESTAMP() - INTERVAL " . BCRF_REWARD_EXPIRE_MONTHS . " MONTH");
    $st->execute();
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $done = bcrf_rewards_tx($pdo, (int)$cid, function (array $m) use ($pdo) {
            $since = $m['last_activity_at'] ?: $m['enrolled_at'];
            if ((int)$m['balance_points'] <= 0 || strtotime($since . ' UTC') >= strtotime('-' . BCRF_REWARD_EXPIRE_MONTHS . ' months')) return false;
            // ref_key ties the expiry to the activity date, so it can't run twice for the same quiet spell.
            return bcrf_rewards_post($pdo, (int)$m['customer_id'], 'expire', -(int)$m['balance_points'],
                'expire:' . $m['customer_id'] . ':' . str_replace([' ', ':', '-'], '', $since), null, '12 months without a purchase or visit');
        });
        if ($done) $n++;
    }
    return $n;
}

function bcrf_rewards_history(PDO $pdo, int $customerId, int $limit = 50): array {
    $st = $pdo->prepare("SELECT l.*, s.sale_number FROM reward_ledger l LEFT JOIN sales s ON s.id = l.sale_id WHERE l.customer_id = ? ORDER BY l.id DESC LIMIT " . max(1, min(500, $limit)));
    $st->execute([$customerId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_rewards_tiers(PDO $pdo, bool $activeOnly = false): array {
    return $pdo->query("SELECT points, benefit, active FROM reward_tiers" . ($activeOnly ? " WHERE active = 1 AND benefit <> ''" : "") . " ORDER BY points")->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_rewards_type_label(string $t): string {
    return ['purchase' => 'Purchase', 'visit' => 'Visit', 'refund' => 'Refund', 'redeem' => 'Reward used', 'expire' => 'Expired', 'adjust' => 'Adjustment'][$t] ?? $t;
}
