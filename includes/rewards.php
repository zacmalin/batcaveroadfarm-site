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
//   • A product can override its type (Products › Roost Rewards points): always earns / never earns   (migration 0017)
//   • Online tickets earn purchase points when paid, and a visit when scanned at the gate
//   • Reminder emails 30 and 7 days before points expire (sent by the daily expiry job)                (migration 0017)
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

/** Has migration 0017 added the per-product override (inventory.rewards_eligible)? */
function bcrf_rewards_override_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inventory' AND column_name = 'rewards_eligible'")->fetchColumn();
        } catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

/** Does a product earn points? $override: null = follow the product type, 1 = always, 0 = never. */
function bcrf_rewards_product_earns(string $productType, $override): bool {
    if ($override !== null && $override !== '') return (int)$override === 1;
    return in_array($productType, BCRF_REWARD_ELIGIBLE_TYPES, true);
}

/** Eligible cents still standing on a sale (gross − discount − refunded) for lines that earn points. */
function bcrf_rewards_eligible_cents(PDO $pdo, int $saleId): int {
    $in = implode(',', array_fill(0, count(BCRF_REWARD_ELIGIBLE_TYPES), '?'));
    if (bcrf_rewards_override_ready($pdo)) {
        $st = $pdo->prepare("SELECT COALESCE(SUM(sl.gross_cents - sl.discount_cents - sl.refunded_net_cents), 0)
                             FROM sale_lines sl LEFT JOIN inventory i ON i.id = sl.product_id
                             WHERE sl.sale_id = ? AND (i.rewards_eligible = 1 OR (i.rewards_eligible IS NULL AND sl.product_type IN ($in)))");
    } else {
        $st = $pdo->prepare("SELECT COALESCE(SUM(gross_cents - discount_cents - refunded_net_cents), 0) FROM sale_lines WHERE sale_id = ? AND product_type IN ($in)");
    }
    $st->execute(array_merge([$saleId], BCRF_REWARD_ELIGIBLE_TYPES));
    return max(0, (int)$st->fetchColumn());
}

/** The enrolled member for a customer id, else an email, else a phone number (online orders). Null if none. */
function bcrf_rewards_find_member(PDO $pdo, ?int $customerId, ?string $email, ?string $phone): ?int {
    if ($customerId) {
        $st = $pdo->prepare("SELECT customer_id FROM reward_members WHERE customer_id = ?");
        $st->execute([$customerId]);
        if ($id = $st->fetchColumn()) return (int)$id;
    }
    $email = strtolower(trim((string)$email));
    if ($email !== '') {
        $st = $pdo->prepare("SELECT m.customer_id FROM reward_members m JOIN customers c ON c.id = m.customer_id WHERE c.email = ? AND c.account_status = 'active'");
        $st->execute([$email]);
        if ($id = $st->fetchColumn()) return (int)$id;
    }
    if ($key = bcrf_rewards_phone_key((string)$phone)) {
        $st = $pdo->prepare("SELECT customer_id FROM reward_members WHERE phone_key = ?");
        $st->execute([$key]);
        if ($id = $st->fetchColumn()) return (int)$id;
    }
    return null;
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

/**
 * Online ticket paid (includes/tickets_online.php, once per order). Purchase points for the buyer if they're a member,
 * matched by account, email or phone. Own transaction; never throws.
 */
function bcrf_rewards_after_ticket_paid(PDO $pdo, int $ticketId): void {
    try {
        if (!bcrf_rewards_enabled($pdo)) return;
        $st = $pdo->prepare("SELECT id, customer_id, purchaser_email, purchaser_phone, order_json, amount_cents, payment_status FROM tickets WHERE id = ?");
        $st->execute([$ticketId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t || $t['payment_status'] !== 'paid') return;
        $cid = bcrf_rewards_find_member($pdo, $t['customer_id'] ? (int)$t['customer_id'] : null, $t['purchaser_email'], $t['purchaser_phone']);
        if (!$cid) return;
        $cents = 0;
        $items = json_decode((string)$t['order_json'], true);
        if (is_array($items) && $items) {
            $look = $pdo->prepare("SELECT product_type" . (bcrf_rewards_override_ready($pdo) ? ", rewards_eligible" : ", NULL AS rewards_eligible") . " FROM inventory WHERE id = ?");
            foreach ($items as $it) {
                $look->execute([(int)($it['product_id'] ?? 0)]);
                $p = $look->fetch(PDO::FETCH_ASSOC) ?: ['product_type' => 'admission', 'rewards_eligible' => null];
                if (bcrf_rewards_product_earns((string)$p['product_type'], $p['rewards_eligible'])) $cents += (int)($it['unit_cents'] ?? 0) * (int)($it['qty'] ?? 0);
            }
        } else {
            $cents = (int)$t['amount_cents'];   // older orders without item detail: all admission
        }
        $pts = bcrf_rewards_points_for_cents($cents);
        if ($pts <= 0) return;
        bcrf_rewards_tx($pdo, $cid, fn() => bcrf_rewards_post($pdo, $cid, 'purchase', $pts, "ticket:$ticketId", null, 'Online tickets', true));
    } catch (Throwable $e) {
        error_log('[BCRF rewards] online ticket ' . $ticketId . ' skipped: ' . $e->getMessage());
    }
}

/** Ticket scanned at the gate (bcrf_ticket_redeem, inside its transaction): the buyer's visit for today. */
function bcrf_rewards_after_ticket_redeem(PDO $pdo, array $ticket, string $bdate): void {
    bcrf_rewards_guarded($pdo, 'ticket ' . (int)$ticket['id'], function () use ($pdo, $ticket, $bdate) {
        $cid = bcrf_rewards_find_member($pdo, !empty($ticket['customer_id']) ? (int)$ticket['customer_id'] : null,
                                       $ticket['purchaser_email'] ?? null, $ticket['purchaser_phone'] ?? null);
        if (!$cid || !bcrf_rewards_member($pdo, $cid, true)) return;
        bcrf_rewards_visit_locked($pdo, $cid, $bdate, null, 'Visit (ticket)');
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

/**
 * Reminder emails 30 and 7 days before a positive balance expires (daily job). Each is sent once per quiet spell;
 * any purchase or visit starts a new spell. Informational only, no marketing. Returns how many were sent.
 */
function bcrf_rewards_send_reminders(PDO $pdo): int {
    try {
        if (!$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'reward_members' AND column_name = 'reminder_stage'")->fetchColumn()) return 0;
    } catch (Throwable $e) { return 0; }
    $st = $pdo->prepare("SELECT m.customer_id, m.balance_points, COALESCE(m.last_activity_at, m.enrolled_at) AS since, m.reminder_for, m.reminder_stage,
                                c.first_name, c.email
                         FROM reward_members m JOIN customers c ON c.id = m.customer_id
                         WHERE m.balance_points > 0 AND c.account_status = 'active'
                           AND COALESCE(m.last_activity_at, m.enrolled_at) < UTC_TIMESTAMP() - INTERVAL " . BCRF_REWARD_EXPIRE_MONTHS . " MONTH + INTERVAL 30 DAY
                           AND COALESCE(m.last_activity_at, m.enrolled_at) >= UTC_TIMESTAMP() - INTERVAL " . BCRF_REWARD_EXPIRE_MONTHS . " MONTH");
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;
    $tpl = dirname(__DIR__) . '/admin/includes/email_template.php';
    if (!function_exists('bcf_send_mail')) { if (!is_file($tpl)) return 0; require_once $tpl; }
    if (!function_exists('bcrf_email_prefs_ready')) require_once __DIR__ . '/email_prefs.php';
    $site = function_exists('bcrf_farm_url') ? bcrf_farm_url() : 'https://batcaveroadfarm.com';
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    $mark = $pdo->prepare("UPDATE reward_members SET reminder_for = ?, reminder_stage = ? WHERE customer_id = ? AND COALESCE(last_activity_at, enrolled_at) = ?");
    $sent = 0;
    foreach ($rows as $r) {
        $expires = (new DateTimeImmutable($r['since'], new DateTimeZone('UTC')))->modify('+' . BCRF_REWARD_EXPIRE_MONTHS . ' months');
        $daysLeft = (int)ceil(($expires->getTimestamp() - time()) / 86400);
        $stage = ($r['reminder_for'] === $r['since']) ? (int)$r['reminder_stage'] : 0;
        $want = $daysLeft <= 7 ? 2 : 1;
        if ($daysLeft <= 0 || $stage >= $want || !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) continue;
        if (!bcrf_email_pref_allowed($pdo, (string)$r['email'], 'rewards_reminders')) continue;   // turned off (includes/email_prefs.php)
        $date = $expires->setTimezone($tz)->format('F j, Y');
        $html = '<p>Hi ' . $h($r['first_name']) . ',</p>'
              . '<p>Just a heads-up: your <strong>' . number_format((int)$r['balance_points']) . ' Roost Rewards points</strong> will expire on <strong>' . $h($date) . '</strong>.</p>'
              . '<p>Any visit or purchase at the farm before then keeps them active. Just give your phone number at the counter.</p>'
              . '<p>See your points and rewards at <a href="' . $h($site) . '/roost-rewards/">' . $h($site) . '/roost-rewards/</a>.</p>'
              . '<p><small>This is a one-time reminder about your points, not a marketing email.</small></p>';
        $html .= bcrf_email_prefs_footer($pdo, (string)$r['email'], 'Roost Rewards reminders', 'you\'re a Roost Rewards member');
        $subject = $want === 2 ? 'Your Roost Rewards points expire in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') : 'Your Roost Rewards points expire next month';
        // Mark first, so a crash after sending can't send twice; unmark if the send fails.
        $mark->execute([$r['since'], $want, (int)$r['customer_id'], $r['since']]);
        if ($mark->rowCount() === 0) continue;   // activity just reset the clock
        if (bcf_send_mail($r['email'], $subject, $html, ['subtitle' => 'Roost Rewards', 'unsubscribe' => false])) { $sent++; }
        else { $mark->execute([$r['reminder_for'], $stage, (int)$r['customer_id'], $r['since']]); }
    }
    return $sent;
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
