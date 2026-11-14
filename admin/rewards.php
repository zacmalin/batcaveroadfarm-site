<?php
// admin/rewards.php — Roost Rewards for staff (lean version, Nov 9, 2026). Rules: includes/rewards.php
//   GET  rewards.php[?q=]              find a member or customer by phone, name or email; enroll
//   GET  rewards.php?id=N              one member: balance, visit, redeem, history (+ adjust for Owner/Manager)
//   GET  rewards.php?tab=settings      on/off switch and tier rewards (administrators)
//   POST rewards.php  act=enroll|visit|redeem|adjust|settings, csrf_token → redirect back
// Plain forms, no script (strict CSP, like stock.php).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['view', 'pos']]);   // anyone who can ring sales can look up, enroll, add visits and redeem
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/sales.php';
require_once __DIR__ . '/../includes/refunds.php';
require_once __DIR__ . '/../includes/rewards.php';
require_once __DIR__ . '/../includes/admin_shell.php';   // shared Farm OS shell (Nov 11, 2026)
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$isAdmin = ($_SESSION['user_role'] ?? '') === 'administrator';
$canAdjust = bcrf_is_approver();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$n = fn($p) => number_format((int)$p);
$phone = fn($k) => strlen((string)$k) === 10 ? sprintf('(%s) %s-%s', substr($k, 0, 3), substr($k, 3, 3), substr($k, 6)) : (string)$k;
$local = fn($utc) => $utc ? (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('n/j/Y g:i A') : '';

if (!bcrf_rewards_ready($pdo)) {
    bcrf_shell_open('Roost Rewards', ['legacy' => true]); echo '',
         '<p class="flash err">Run migration 0016 (Roost Rewards) in Settings › Database first.</p></div></main></body></html>';
    exit;
}

// -- Actions (POST → redirect back) --
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $back = (string)($_POST['back'] ?? '');
    if (!preg_match('/^rewards\.php(\?[A-Za-z0-9_=&%.\-+]*)?$/', $back)) $back = 'rewards.php';
    $say = function (string $kind, string $msg) { $_SESSION['rewards_flash'] = [$kind, $msg]; };
    $cid = (int)($_POST['customer_id'] ?? 0);
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $say('err', 'Security token expired; try again.');
    } else {
        $act = (string)($_POST['act'] ?? '');
        try {
            if ($act === 'enroll') {
                $r = bcrf_rewards_enroll($pdo, $cid, (string)($_POST['phone'] ?? ''), $uid);
                if (function_exists('erp_audit')) erp_audit($pdo, 'create', 'reward_members', $cid, 'Enrolled in Roost Rewards');
                $say('ok', $r['new'] ? 'Enrolled. A welcome email is on its way.' : 'Already enrolled.');
                $back = 'rewards.php?id=' . $cid;
            } elseif ($act === 'visit') {
                $say('ok', bcrf_rewards_staff_visit($pdo, $cid) ? '+25 points for today\'s visit.' : 'Today\'s visit was already counted.');
            } elseif ($act === 'redeem') {
                $r = bcrf_rewards_redeem($pdo, $cid, (int)($_POST['tier'] ?? 0), (string)($_POST['key'] ?? ''));
                if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'reward_members', $cid, 'Redeemed ' . (int)$_POST['tier'] . ' points: ' . $r['benefit']);
                $say('ok', $r['posted'] ? 'Redeemed: ' . $r['benefit'] . '. Give the customer their reward.' : 'That reward was already redeemed.');
            } elseif ($act === 'adjust') {
                if (!$canAdjust) throw new BcrfRewardError('Only an Owner or Manager can adjust points.');
                $pts = (int)($_POST['points'] ?? 0);
                bcrf_rewards_adjust($pdo, $cid, $pts, (string)($_POST['reason'] ?? ''), (string)($_POST['key'] ?? ''));
                if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'reward_members', $cid, sprintf('Adjusted %+d points: %s', $pts, $_POST['reason'] ?? ''));
                $say('ok', sprintf('%+d points saved.', $pts));
            } elseif ($act === 'settings') {
                if (!$isAdmin) throw new BcrfRewardError('Only an administrator can change Roost Rewards settings.');
                $up = $pdo->prepare("UPDATE reward_tiers SET benefit = ?, active = ? WHERE points = ?");
                foreach (bcrf_rewards_tiers($pdo) as $t) {
                    $p = (int)$t['points'];
                    $b = mb_substr(trim(strip_tags((string)($_POST['benefit'][$p] ?? ''))), 0, 160);
                    $up->execute([$b, $b !== '' && !empty($_POST['active'][$p]) ? 1 : 0, $p]);
                }
                $on = !empty($_POST['enabled']) ? '1' : '0';
                $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('rewards_enabled', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$on]);
                if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'site_settings', 0, 'Roost Rewards settings saved (' . ($on === '1' ? 'on' : 'off') . ')');
                // Menu, footer, FAQ and home page links change with the switch: clear the page caches so it shows at once.
                header('X-LiteSpeed-Purge: *');
                require_once __DIR__ . '/../includes/adapter_cloudflare.php';
                require_once __DIR__ . '/../includes/queue.php';
                if (!bcrf_cdn_purge()) bcrf_queue_push('cdn.purge', [], ['dedupe' => 'cdn.purge:all']);
                $say('ok', 'Settings saved. Roost Rewards is ' . ($on === '1' ? 'ON' : 'OFF') . '.');
            }
        } catch (BcrfRewardError $e) {
            $say('err', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[BCRF rewards admin] ' . $e->getMessage());
            $say('err', 'Something went wrong; nothing was saved.');
        }
    }
    header('Location: ' . $back, true, 303);
    exit;
}

$flash = $_SESSION['rewards_flash'] ?? null;
unset($_SESSION['rewards_flash']);
$tok = fn() => bin2hex(random_bytes(16));   // one-time key per form: a double tap can't redeem or adjust twice
$hid = fn(string $back, int $cid = 0) => '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '"><input type="hidden" name="back" value="' . $h($back) . '">'
                                         . ($cid ? '<input type="hidden" name="customer_id" value="' . $cid . '">' : '');
$on = bcrf_rewards_enabled($pdo);
$tab = ($_GET['tab'] ?? '') === 'settings' && $isAdmin ? 'settings' : (isset($_GET['id']) ? 'member' : 'find');

bcrf_shell_open('Roost Rewards', ['legacy' => true]);
echo '<nav class="rtabs" aria-label="Roost Rewards"><a href="rewards.php"', $tab !== 'settings' ? ' aria-current="page"' : '', '>Find member</a>',
     $isAdmin ? '<a href="rewards.php?tab=settings"' . ($tab === 'settings' ? ' aria-current="page"' : '') . '>Settings</a>' : '', '</nav>';
echo '<h1>Roost Rewards</h1>';
if (!$on) echo '<p class="flash err">Roost Rewards is OFF: sales are not earning points.', $isAdmin ? ' Turn it on in <a href="rewards.php?tab=settings">Settings</a>.' : '', '</p>';
if ($flash) echo '<p class="flash', $flash[0] === 'err' ? ' err' : '', '" role="status">', $h($flash[1]), '</p>';

if ($tab === 'find') {
    $q = trim((string)($_GET['q'] ?? ''));
    echo '<form class="rform" method="GET" action="rewards.php"><input type="search" name="q" value="', $h($q), '" placeholder="Phone, name or email" aria-label="Search" autofocus>',
         '<button class="btn sm" type="submit">Find</button></form>';
    if ($q !== '') {
        $digits = preg_replace('/\D/', '', $q);
        $like = '%' . $q . '%';
        $st = $pdo->prepare("SELECT c.id, c.first_name, c.last_name, c.email, c.phone_number, m.phone_key, m.balance_points
                             FROM customers c LEFT JOIN reward_members m ON m.customer_id = c.id
                             WHERE c.account_status = 'active' AND (CONCAT(c.first_name, ' ', c.last_name) LIKE ? OR c.email LIKE ?
                                   OR (? <> '' AND (m.phone_key LIKE ? OR REGEXP_REPLACE(COALESCE(c.phone_number, ''), '[^0-9]', '') LIKE ?)))
                             ORDER BY m.customer_id IS NULL, c.last_name, c.first_name LIMIT 25");
        $st->execute([$like, $like, strlen($digits) >= 3 ? $digits : '', '%' . $digits . '%', '%' . $digits . '%']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) echo '<p>No customer found. Add them at the register (name, email and phone) first, then enroll them here.</p>';
        else {
            echo '<div class="card scroll"><table class="rt grid"><tr class="th"><td>Customer</td><td>Phone</td><td>Points</td><td></td></tr>';
            foreach ($rows as $r) {
                $name = trim($r['first_name'] . ' ' . $r['last_name']);
                echo '<tr><td>', $h($name), '<br><small>', $h($r['email']), '</small></td>';
                if ($r['phone_key'] !== null) {
                    echo '<td>', $h($phone($r['phone_key'])), '</td><td>', $n($r['balance_points']), '</td><td><a class="btn sm primary" href="rewards.php?id=', (int)$r['id'], '">Open</a></td></tr>';
                } else {
                    echo '<td colspan="3"><form class="rform" method="POST" action="rewards.php">', $hid('rewards.php?q=' . rawurlencode($q), (int)$r['id']),
                         '<input type="hidden" name="act" value="enroll"><input type="tel" name="phone" value="', $h($r['phone_number']), '" placeholder="Phone" aria-label="Phone for ', $h($name), '" required>',
                         '<button class="btn sm" type="submit">Enroll</button></form></td></tr>';
                }
            }
            echo '</table></div>';
        }
    }
} elseif ($tab === 'member') {
    $cid = (int)$_GET['id'];
    $m = bcrf_rewards_member($pdo, $cid);
    if (!$m) { echo '<p>Not a member. <a href="rewards.php">Find a customer</a>.</p></div></main></body></html>'; exit; }
    $back = 'rewards.php?id=' . $cid;
    $bal = (int)$m['balance_points'];
    echo '<section class="card"><h2>', $h(trim($m['first_name'] . ' ' . $m['last_name'])), '</h2>',
         '<p>', $h($phone($m['phone_key'])), ' · ', $h($m['email']), '</p>',
         '<p class="big"><strong>', $n($bal), '</strong> points', $bal < 0 ? ' (negative after a refund; earning brings it back up)' : '', '</p>',
         '<p><small>Member since ', $h($local($m['enrolled_at'])), $m['last_activity_at'] ? ' · last earned ' . $h($local($m['last_activity_at'])) : '', '</small></p>',
         '<form method="POST" action="rewards.php">', $hid($back, $cid), '<input type="hidden" name="act" value="visit"><button class="btn" type="submit">Visit today (+25)</button></form></section>';

    echo '<section class="card"><h3>Use a reward</h3>';
    $tiers = bcrf_rewards_tiers($pdo, true);
    if (!$tiers) echo '<p>No rewards are set up yet.', $isAdmin ? ' Add them in <a href="rewards.php?tab=settings">Settings</a>.' : '', '</p>';
    foreach ($tiers as $t) {
        $ok = $bal >= (int)$t['points'];
        echo '<form class="rform" method="POST" action="rewards.php">', $hid($back, $cid),
             '<input type="hidden" name="act" value="redeem"><input type="hidden" name="tier" value="', (int)$t['points'], '"><input type="hidden" name="key" value="', $tok(), '">',
             '<button class="btn', $ok ? ' primary' : '', '" type="submit"', $ok ? '' : ' disabled', '>', $n($t['points']), ' pts — ', $h($t['benefit']), '</button></form>';
    }
    echo '</section>';

    if ($canAdjust) {
        echo '<section class="card"><h3>Adjust points</h3><form class="stackf" method="POST" action="rewards.php">', $hid($back, $cid),
             '<input type="hidden" name="act" value="adjust"><input type="hidden" name="key" value="', $tok(), '">',
             '<label>Points (minus to remove) <input type="number" name="points" step="1" required></label>',
             '<label>Reason <input type="text" name="reason" maxlength="200" required placeholder="e.g. points missed on receipt R-2026-000123"></label>',
             '<button class="btn" type="submit">Save adjustment</button></form></section>';
    }

    echo '<section class="card scroll"><h3>History</h3><table class="rt grid"><tr class="th"><td>When</td><td>What</td><td>Points</td></tr>';
    foreach (bcrf_rewards_history($pdo, $cid) as $l) {
        $what = bcrf_rewards_type_label($l['entry_type']) . ($l['sale_number'] ? ' · ' . $l['sale_number'] : '') . ($l['note'] ? ' · ' . $l['note'] : '');
        echo '<tr><td>', $h($local($l['created_at'])), '</td><td>', $h($what), '</td><td>', sprintf('%+d', (int)$l['points']), '</td></tr>';
    }
    echo '</table></section>';
} else {   // settings
    echo '<section class="card"><h2>Settings</h2><form class="stackf" method="POST" action="rewards.php">', $hid('rewards.php?tab=settings'),
         '<input type="hidden" name="act" value="settings">',
         '<label><input type="checkbox" name="enabled" value="1"', $on ? ' checked' : '',
         ' data-confirm-on="Turn Roost Rewards ON? Register sales start earning points and the customer page opens."',
         ' data-confirm-off="Turn Roost Rewards OFF? Register sales stop earning points and the customer page closes. Points already earned are kept.">',
         ' Roost Rewards is ON (register sales earn points, the customer page is open)</label>',
         '<h3>Rewards</h3><p><small>A tier can be used only when it has a reward written in and is turned on.</small></p>';
    foreach (bcrf_rewards_tiers($pdo) as $t) {
        $p = (int)$t['points'];
        echo '<label>', $n($p), ' points <input type="text" name="benefit[', $p, ']" maxlength="160" value="', $h($t['benefit']), '" placeholder="e.g. Free bag of feed"></label>',
             '<label><input type="checkbox" name="active[', $p, ']" value="1"', (int)$t['active'] ? ' checked' : '', '> Offer the ', $n($p), '-point reward</label>';
    }
    echo '<button class="btn primary" type="submit">Save settings</button></form></section>';
}
echo '</div></main></body></html>';
