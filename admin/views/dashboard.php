<?php
// admin/views/dashboard.php
// Dashboard v1 — 02 Navigation & UX (Nov 5, 2026). Order: Action Center → Today → Quick actions → Key metrics → Calendar.
// Cron and Facebook warnings now live in the Action Center as alerts (banners retired from this screen).
// Alert priorities: urgent / today / fyi. Only FYI can be dismissed (on this device, until its count changes).
// Snooze, reassign and server-side alert state arrive with the Phase 0 Action Center tables.

$role  = $current_role ?? 'user';
$h     = fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$money = fn($n): string => '$' . number_format((float)$n, 2);
$canV  = function (string $v) use ($role): bool {
    if ($role === 'administrator') return true;
    if ($role === 'Staff') return $v === 'timecard';
    return function_exists('hasViewAccess') && hasViewAccess($role, $v);
};
$canT  = function (string $t) use ($role): bool {
    if ($role === 'administrator') return true;
    if ($role === 'Staff') return false;
    return function_exists('hasTableAccess') && hasTableAccess($role, $t);
};
$isAdmin = $role === 'administrator';
$A       = '/admin/admin.php';
$today   = date('Y-m-d');                       // Eastern (set in admin.php); MySQL's clock is not.
$soonDay = date('Y-m-d', strtotime('+1 day'));

if (!function_exists('site_open_status')) {
    try { require_once dirname(__DIR__, 2) . '/includes/site_info.php'; } catch (Throwable $e) {}
}

// ═══ ACTION CENTER ═══════════════════════════════════════════════════════════
$alerts = [];
$add = function (array $a) use (&$alerts) { $alerts[] = $a + ['items' => [], 'actions' => [], 'detail' => '', 'count' => 1]; };

// Scheduled jobs (was cron_health_banner) — administrators
if ($isAdmin) {
    try {
        require_once dirname(__DIR__, 2) . '/includes/cron_health.php';
        $ch = bcrf_cron_problems($pdo);
        if ($ch) {
            $fail = array_filter($ch, fn($j) => $j['state'] === 'failing');
            $items = [];
            foreach ($ch as $j) {
                $items[] = $j['label'] . ' — ' . ($j['state'] === 'failing'
                    ? 'failed ' . bcrf_ago($j['last_fail']) . ($j['message'] ? ': ' . mb_substr($j['message'], 0, 120) : '')
                    : 'last ran OK ' . bcrf_ago($j['last_ok']) . ' (expected ' . $j['schedule'] . ')');
            }
            $add(['id' => 'cron', 'priority' => $fail ? 'urgent' : 'today', 'icon' => $fail ? '⛔' : '⏱️',
                  'title' => count($ch) . ' scheduled job' . (count($ch) === 1 ? ' needs' : 's need') . ' attention',
                  'items' => $items, 'count' => count($ch),
                  'actions' => [['Open System Health', '/admin/system_health.php', true]]]);
        }
    } catch (Throwable $e) { /* cron health not installed */ }
    // Background jobs that gave up after their retries (includes/queue.php, Farm OS Phase 0)
    try {
        require_once dirname(__DIR__, 2) . '/includes/queue.php';
        $qd = bcrf_queue_ready($pdo) ? (int)$pdo->query("SELECT COUNT(*) FROM queue_jobs WHERE status = 'dead'")->fetchColumn() : 0;
        if ($qd) {
            $add(['id' => 'queue', 'priority' => 'today', 'icon' => '🔁',
                  'title' => $qd . ' background job' . ($qd === 1 ? '' : 's') . ' gave up after retrying',
                  'items' => ['Retry or discard on System Health (the error is shown there).'], 'count' => $qd,
                  'actions' => [['Open System Health', '/admin/system_health.php#runner', true]]]);
        }
    } catch (Throwable $e) {}
    // Two-step sign-in not set up (includes/staff_auth.php, Farm OS Phase 0)
    try {
        require_once dirname(__DIR__, 2) . '/includes/staff_auth.php';
        if (bcrf_mfa_ready($pdo)) {
            $mine = bcrf_mfa_row($pdo, (int)($_SESSION['user_id'] ?? 0));
            $adminsOff = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'administrator' AND account_status <> 'disabled' AND totp_enabled_at IS NULL")->fetchColumn();
            if ($mine && empty($mine['totp_enabled_at'])) {
                $add(['id' => 'mfa', 'priority' => 'today', 'icon' => '🔐', 'title' => 'Turn on two-step sign-in',
                      'items' => ['A code from an authenticator app on your phone, after your password. Takes about a minute.'],
                      'actions' => [['Set it up', '/admin/two_step.php', true]]]);
            } elseif (!bcrf_mfa_required_admin($pdo)) {
                $add(['id' => 'mfa', 'priority' => 'fyi', 'icon' => '🔐',
                      'title' => $adminsOff ? $adminsOff . ' administrator' . ($adminsOff === 1 ? ' hasn\'t' : 's haven\'t') . ' set up two-step sign-in' : 'Every administrator has two-step sign-in: you can make it required',
                      'items' => [$adminsOff ? 'Once everyone has, turn on "Require for administrators".' : 'Two-step sign-in › Require for administrators.'], 'count' => $adminsOff,
                      'actions' => [['Two-step sign-in', '/admin/two_step.php', true]]]);
            }
        }
    } catch (Throwable $e) {}
}

// Roost Rewards invitation delivery (aggregate-only, administrators).
// Remains hidden until migration 0024 is present; never starts a worker.
if ($isAdmin) {
    try {
        $hasOutbox=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reward_invitation_delivery_outbox'")->fetchColumn();
        if ($hasOutbox) {
            require_once dirname(__DIR__, 2) . '/includes/roost-invitation-delivery-monitor.php';
            $rm=bcrf_roost_invitation_delivery_metrics($pdo);
            $failed=$rm['status_counts']['failed'];
            $stale=$rm['stale'];
            $delayed=$rm['delayed'];
            $ambiguous=$rm['ambiguous'];
            if ($failed || $stale || $delayed) {
                $items=[];
                if ($failed) $items[]=$failed.' invitation delivery job(s) failed; review before manual action.';
                if ($ambiguous) $items[]=$ambiguous.' failed job(s) crossed the SMTP dispatch boundary; delivery may have occurred. Never resend automatically.';
                if ($stale) $items[]=$stale.' invitation delivery job(s) have stale claims.';
                if ($delayed) $items[]=$delayed.' pending invitation delivery job(s) are older than one hour.';
                $add(['id'=>'roost_invitation_delivery',
                    'priority'=>$stale || $failed ? 'today' : 'fyi',
                    'icon'=>'✉️',
                    'title'=>'Roost Rewards invitation delivery needs review',
                    'items'=>$items,'count'=>$failed+$stale+$delayed]);
            }
        }
    } catch (Throwable $e) {
        // Optional, not-yet-deployed Rewards schema must never break the dashboard.
    }
}

// Facebook & Instagram posting (was social_health_banner)
if ($canV('social')) {
    try {
        require_once dirname(__DIR__, 2) . '/includes/social_token.php';
        [$sl, $sm] = bcrf_social_token_summary(bcrf_social_token_status());
        $sf = 0; $se = '';
        try {
            $sf = (int)$pdo->query("SELECT COUNT(*) FROM social_queue WHERE status IN ('failed','partial') AND processed_at >= NOW() - INTERVAL 14 DAY")->fetchColumn();
            if ($sf) $se = (string)$pdo->query("SELECT last_error FROM social_queue WHERE status IN ('failed','partial') AND processed_at >= NOW() - INTERVAL 14 DAY ORDER BY processed_at DESC LIMIT 1")->fetchColumn();
        } catch (Throwable $e) {}
        if ($sl !== 'ok' || $sf > 0) {
            $items = [$sm];
            if ($sf) $items[] = $sf . ' post' . ($sf === 1 ? '' : 's') . ' failed in the last 14 days' . ($se !== '' ? ' — latest: ' . mb_substr($se, 0, 140) : '');
            if ($sl === 'bad') $items[] = 'Fix: make a new Page token (docs/FACEBOOK_TOKEN.md), paste it into bcrf-secrets.php as FB_PAGE_TOKEN, then tap Check now.';
            $add(['id' => 'social', 'priority' => $sl === 'bad' ? 'urgent' : ($sl === 'none' && !$sf ? 'fyi' : 'today'),
                  'icon' => $sl === 'bad' ? '⛔' : '📣', 'title' => 'Facebook & Instagram posting needs attention',
                  'items' => $items, 'count' => $sf . $sl,
                  'actions' => [['Check now', '/admin/social_token_check.php?from=dashboard', true], ['Social Queue', "$A?view=social", false]]]);
        }
    } catch (Throwable $e) { /* social tools not installed */ }
}

// Pinterest (Nov 2026): reconnect needed, Pins that failed, approved Pins held back
if ($canV('social')) {
    try {
        require_once dirname(__DIR__, 2) . '/includes/adapter_pinterest.php';
        if (bcrf_pinapi_tables($pdo)) {
            $pa = bcrf_pinapi_auth($pdo);
            $pr = !empty($pa['refresh_token_enc']) && (int)$pa['needs_reconnect'];
            $pf = (int)$pdo->query("SELECT COUNT(*) FROM social_queue_targets WHERE platform = 'pinterest' AND status = 'failed' AND updated_at >= UTC_TIMESTAMP() - INTERVAL 14 DAY")->fetchColumn();
            $ph = (int)$pdo->query("SELECT COUNT(*) FROM social_queue_targets WHERE platform = 'pinterest' AND status = 'approved' AND last_error_code IS NOT NULL")->fetchColumn();
            if ($pr || $pf || $ph) {
                $items = [];
                if ($pr) $items[] = 'Pinterest sign-in stopped working' . (!empty($pa['last_error']) ? ': ' . mb_substr((string)$pa['last_error'], 0, 140) : '') . '. Reconnect in Settings › Pinterest.';
                if ($pf) $items[] = $pf . ' Pin' . ($pf === 1 ? '' : 's') . ' failed in the last 14 days.';
                if ($ph) $items[] = $ph . ' approved Pin' . ($ph === 1 ? ' is' : 's are') . ' waiting on a fix (board or sign-in).';
                $add(['id' => 'pinterest', 'priority' => $pr ? 'urgent' : 'today', 'icon' => $pr ? '⛔' : '📌', 'title' => 'Pinterest posting needs attention',
                      'items' => $items, 'count' => ($pr ? 'r' : '') . $pf . '-' . $ph,
                      'actions' => array_merge([['Pinterest drafts', "$A?view=social&tab=pinterest&f=" . ($pf ? 'drafts' : 'approved'), false]],
                                               $role === 'administrator' ? [['Settings', '/admin/pinterest_connect.php', false]] : [])]);
            }
        }
    } catch (Throwable $e) { /* Pinterest tables not installed */ }
}

// PayPal / Venmo / Cash App payments to match (Farm OS Phase 1, slice 6; 05 › Peer-to-peer reconciliation)
if ($canV('p2p_reconcile')) {
    try {
        require_once dirname(__DIR__, 2) . '/includes/reconcile.php';
        if (bcrf_sales_ready($pdo)) {
            $pc = bcrf_p2p_counts($pdo);
            $p2pM = fn($c) => '$' . number_format($c / 100, 2);
            if ($pc['flagged']) {
                $add(['id' => 'p2p_flagged', 'priority' => 'today', 'icon' => '⚠️',
                      'title' => $pc['flagged'] . ' PayPal/Venmo/Cash App payment' . ($pc['flagged'] === 1 ? '' : 's') . ' flagged as a problem',
                      'detail' => $p2pM($pc['flagged_cents']) . ' — missing, wrong amount or reversed. Settle with the customer.',
                      'count' => $pc['flagged'], 'actions' => [['Review problems', '/admin/reconcile.php?tab=flagged', true]]]);
            }
            if ($pc['stale']) {
                $add(['id' => 'p2p_stale', 'priority' => 'today', 'icon' => '🤝',
                      'title' => $pc['stale'] . ' P2P payment' . ($pc['stale'] === 1 ? '' : 's') . ' not checked for over ' . bcrf_p2p_stale_days() . ' days',
                      'detail' => $p2pM($pc['stale_cents']) . ' waiting. Match them against the apps on the farm phone.',
                      'count' => $pc['stale'], 'actions' => [['Match payments', '/admin/reconcile.php', true]]]);
            } elseif ($pc['open']) {
                $add(['id' => 'p2p_open', 'priority' => 'fyi', 'icon' => '🤝',
                      'title' => $pc['open'] . ' P2P payment' . ($pc['open'] === 1 ? '' : 's') . ' to match',
                      'detail' => $p2pM($pc['open_cents']) . ' since ' . date('M j', strtotime($pc['oldest_open'])) . '. Check them weekly.',
                      'count' => $pc['open'], 'actions' => [['Match payments', '/admin/reconcile.php', true]]]);
            }
        }
    } catch (Throwable $e) { /* sales tables not installed */ }
}

// Booking requests waiting for confirmation
if ($canV('bookings')) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) n, MIN(booking_date) soonest FROM bookings WHERE status = 'pending' AND booking_date >= ?");
        $st->execute([$today]);
        $bp = $st->fetch(PDO::FETCH_ASSOC);
        if ((int)$bp['n'] > 0) {
            $soon = $bp['soonest'] <= $soonDay;
            $add(['id' => 'bookings_pending', 'priority' => $soon ? 'urgent' : 'today', 'icon' => '📋',
                  'title' => (int)$bp['n'] . ' booking request' . ((int)$bp['n'] === 1 ? '' : 's') . ' waiting for confirmation',
                  'detail' => 'Soonest: ' . date('D, M j', strtotime($bp['soonest'])) . ($soon ? ' — coming right up' : ''),
                  'count' => (int)$bp['n'], 'actions' => [['Review requests', "$A?view=bookings", true]]]);
        }
    } catch (Throwable $e) { /* booking tables not installed */ }
}

// New private event inquiries
if ($canV('event_inquiries')) {
    try {
        $iq = $pdo->query("SELECT COUNT(*) n, MIN(created_at) oldest FROM event_inquiries WHERE status = 'new'")->fetch(PDO::FETCH_ASSOC);
        if ((int)$iq['n'] > 0) {
            $stale = strtotime($iq['oldest']) < time() - 48 * 3600;
            $add(['id' => 'inquiries_new', 'priority' => $stale ? 'urgent' : 'today', 'icon' => '🎉',
                  'title' => (int)$iq['n'] . ' new private event inquir' . ((int)$iq['n'] === 1 ? 'y' : 'ies'),
                  'detail' => 'Oldest from ' . date('D, M j', strtotime($iq['oldest'])) . ($stale ? ' — waiting more than 2 days' : ''),
                  'count' => (int)$iq['n'], 'actions' => [['Reply to inquiries', "$A?view=event_inquiries&status=new", true]]]);
        }
    } catch (Throwable $e) { /* inquiries table not installed */ }
}

// Low stock + reorder forecast
$canInv = $canV('inventory_update') || $canT('inventory');
if ($canInv) {
    // Count differences over 20% or $25 in the last 7 days (Farm OS slice 8; 06 › Alerts)
    try {
        require_once dirname(__DIR__, 2) . '/includes/stock.php';
        if ($cf = bcrf_count_flags_recent($pdo, 7)) {
            $add(['id' => 'count_flags', 'priority' => 'today', 'icon' => '🔎',
                  'title' => count($cf) . ' big count difference' . (count($cf) === 1 ? '' : 's') . ' this week',
                  'items' => array_map(fn($r) => $r['name'] . ' at ' . $r['location_name'] . ': expected ' . bcrf_qtxt($r['expected_qty']) . ', counted ' . bcrf_qtxt($r['counted_qty']), array_slice($cf, 0, 5)),
                  'detail' => 'Over 20% or $25 off. Recount, or check for missed sales, spoilage or theft.', 'count' => count($cf),
                  'actions' => [['Open count', '/admin/stock.php?tab=count&id=' . (int)$cf[0]['count_id'], true], ['Stock history', '/admin/stock.php?tab=history&reason=count', false]]]);
        }
    } catch (Throwable $e) { /* stock tables not installed */ }
    $ls = $low_stock_items ?? [];
    if ($ls) {
        $add(['id' => 'low_stock', 'priority' => 'today', 'icon' => '📦',
              'title' => count($ls) . ' item' . (count($ls) === 1 ? ' is' : 's are') . ' low on stock',
              'items' => array_map(fn($i) => $i['name'] . ' — ' . (float)$i['stock_quantity'] . ' left', array_slice($ls, 0, 5)),
              'detail' => count($ls) > 5 ? '…and ' . (count($ls) - 5) . ' more' : '', 'count' => count($ls),
              'actions' => [function_exists('bcrf_stock_ready') && bcrf_stock_ready($pdo) ? ['See stock', '/admin/stock.php?low=1', true] : ['Count inventory', "$A?view=inventory_update", true],
                            ['Open catalog', "$A?view=schema&table=inventory", false]]]);
    }
    $fc = [];
    foreach (($forecast_items ?? []) as $fi) {
        $vel = $forecast_trends[$fi['id']] ?? 0;
        if ($vel < 0.01) continue;
        $days = ($fi['stock_quantity'] - ($fi['min_stock_level'] ?? 0)) / $vel;
        if ($days <= 14) $fc[] = $fi['name'] . ' — about ' . max(0, (int)round($days)) . ' days left at ' . number_format($vel, 1) . '/day';
    }
    if ($fc) {
        $add(['id' => 'forecast', 'priority' => 'fyi', 'icon' => '📈',
              'title' => count($fc) . ' item' . (count($fc) === 1 ? '' : 's') . ' will run low within 2 weeks',
              'items' => array_slice($fc, 0, 5), 'count' => count($fc),
              'actions' => [['Open catalog', "$A?view=schema&table=inventory", false]]]);
    }
}

// Comments awaiting moderation
$pcc = (int)($pending_comments_count ?? 0);
if ($pcc > 0 && $canV('comment_moderation')) {
    $add(['id' => 'comments', 'priority' => 'fyi', 'icon' => '💬',
          'title' => $pcc . ' comment' . ($pcc === 1 ? '' : 's') . ' awaiting moderation', 'count' => $pcc,
          'actions' => [['Review comments', "$A?view=comment_moderation", true]]]);
}

// Hiring — administrators
if ($isAdmin) {
    try {
        $jq = (int)$pdo->query("SELECT COUNT(*) FROM email_queue WHERE status IN ('pending','failed')")->fetchColumn();
        if ($jq > 0) {
            $add(['id' => 'hiring_emails', 'priority' => 'today', 'icon' => '📧',
                  'title' => $jq . ' hiring email' . ($jq === 1 ? '' : 's') . ' waiting for approval', 'count' => $jq,
                  'actions' => [['Open email queue', "$A?view=jobs_emails&tab=queue", true]]]);
        }
        $jy = (int)$pdo->query("SELECT COUNT(*) FROM user_employment WHERE end_date IS NULL AND is_minor = 1 AND youth_cert_received_at IS NULL")->fetchColumn();
        if ($jy > 0) {
            $add(['id' => 'youth_certs', 'priority' => 'today', 'icon' => '🪪',
                  'title' => $jy . ' youth employment certificate' . ($jy === 1 ? '' : 's') . ' still needed', 'count' => $jy,
                  'actions' => [['Open Staff', "$A?view=jobs_staff", true]]]);
        }
        $jn = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'new' AND is_spam = 0 AND archived_at IS NULL")->fetchColumn();
        if ($jn > 0) {
            $names = $pdo->query("SELECT a.first_name, a.last_name, j.title FROM applications a JOIN jobs j ON j.id = a.job_id
                                  WHERE a.status = 'new' AND a.is_spam = 0 AND a.archived_at IS NULL ORDER BY a.submitted_at DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $add(['id' => 'applicants', 'priority' => 'fyi', 'icon' => '📥',
                  'title' => $jn . ' new job applicant' . ($jn === 1 ? '' : 's'), 'count' => $jn,
                  'items' => array_map(fn($a) => trim($a['first_name'] . ' ' . $a['last_name']) . ' — ' . $a['title'], $names),
                  'actions' => [['Open applicants', "$A?view=jobs", true]]]);
        }
    } catch (Throwable $e) { /* jobs tables not installed */ }
}

$prioOrder = ['urgent' => 0, 'today' => 1, 'fyi' => 2];
usort($alerts, fn($a, $b) => $prioOrder[$a['priority']] <=> $prioOrder[$b['priority']]);
$prioCount = array_count_values(array_column($alerts, 'priority'));
$prioStyle = [
    'urgent' => ['label' => 'Urgent', 'bar' => 'border-l-red-600',   'chip' => 'bg-red-100 text-red-800'],
    'today'  => ['label' => 'Today',  'bar' => 'border-l-amber-500', 'chip' => 'bg-amber-100 text-amber-800'],
    'fyi'    => ['label' => 'FYI',    'bar' => 'border-l-stone-300', 'chip' => 'bg-stone-100 text-stone-600'],
];

// ═══ TODAY ═══════════════════════════════════════════════════════════════════
$openSt = function_exists('site_open_status') ? site_open_status('general') : null;
$hoursToday = '';
if (function_exists('site_hours')) {
    $wk = site_hours('general')[(int)date('w')] ?? ['closed' => true, 'blocks' => []];
    $hoursToday = $wk['closed'] ? 'Closed today'
        : implode(' & ', array_map(fn($b) => date('g:i A', strtotime($b['open'])) . ' – ' . date('g:i A', strtotime($b['close'])), $wk['blocks']));
}
$todayBookings = [];
if ($canV('bookings')) {
    $sql = "SELECT b.start_time, b.customer_name, b.party_size, b.status, b.is_exclusive, t.label
            FROM bookings b JOIN booking_types t ON t.id = b.type_id
            WHERE b.booking_date = ? AND b.status IN ('confirmed','pending') %s ORDER BY b.start_time";
    try {
        $st = $pdo->prepare(sprintf($sql, 'AND COALESCE(b.date_flexible, 0) = 0'));   // any-day tickets aren't scheduled visits
        $st->execute([$today]);
        $todayBookings = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        try { $st = $pdo->prepare(sprintf($sql, '')); $st->execute([$today]); $todayBookings = $st->fetchAll(PDO::FETCH_ASSOC) ?: []; }
        catch (Throwable $e2) { $todayBookings = []; }
    }
}
$todayEvents = [];
try {
    $st = $pdo->prepare("SELECT * FROM events WHERE status = 'published' AND start_datetime BETWEEN ? AND ? ORDER BY start_datetime");
    $st->execute([$today . ' 00:00:00', $today . ' 23:59:59']);
    $todayEvents = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
$todayGuests = array_sum(array_map(fn($b) => $b['status'] === 'confirmed' ? (int)$b['party_size'] : 0, $todayBookings));

// ═══ QUICK ACTIONS ═══════════════════════════════════════════════════════════
$quick = [];
if ($canV('pos'))              $quick[] = ['💳', 'New sale',         '/admin/register.php'];
if ($canV('inventory_update')) $quick[] = ['📦', 'Count inventory',  "$A?view=inventory_update"];
if ($canV('bookings'))         $quick[] = ['📋', "Today's bookings", "$A?view=bookings&day=$today"];
if ($canV('timecard'))         $quick[] = ['⏰', 'Clock in / out',   "$A?view=timecard"];

// ═══ CALENDAR (unchanged) ════════════════════════════════════════════════════
$cal_bookings = []; $cal_events = [];
$cal_first = (isset($_GET['cal']) && preg_match('/^\d{4}-\d{2}$/', (string)$_GET['cal']))
    ? DateTime::createFromFormat('Y-m-d', $_GET['cal'] . '-01') : null;
if (!$cal_first) $cal_first = new DateTime('first day of this month');
$cal_first->setTime(0, 0, 0);
$cal_year = (int)$cal_first->format('Y'); $cal_mon = (int)$cal_first->format('n');
$cal_days = (int)$cal_first->format('t'); $cal_startDow = (int)$cal_first->format('w');
$mStart = $cal_first->format('Y-m-01'); $mEnd = $cal_first->format('Y-m-t');
try {
    $st = $pdo->prepare("SELECT DISTINCT booking_date d FROM bookings WHERE status = 'confirmed' AND booking_date BETWEEN ? AND ?");
    $st->execute([$mStart, $mEnd]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) { $cal_bookings[(int)substr($d, 8, 2)] = true; }
} catch (Throwable $e) {}
try {
    $st = $pdo->prepare("SELECT DISTINCT DATE(start_datetime) d FROM events WHERE status = 'published' AND start_datetime BETWEEN ? AND ?");
    $st->execute([$mStart . ' 00:00:00', $mEnd . ' 23:59:59']);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) { $cal_events[(int)substr($d, 8, 2)] = true; }
} catch (Throwable $e) {}
$cal_prev = (clone $cal_first)->modify('-1 month')->format('Y-m');
$cal_next = (clone $cal_first)->modify('+1 month')->format('Y-m');
$showCal  = $canV('bookings') || $canV('events');
$showSales = $canV('pos') || $canV('sales_analytics') || $canV('receipts');

$card = 'bg-white rounded-xl border border-stone-200 shadow-xs';
?>
<div class="max-w-6xl">

  <div class="flex flex-wrap items-end justify-between gap-2 mb-4">
    <div>
      <h1 class="text-2xl font-serif font-bold text-stone-900">Dashboard</h1>
      <div class="text-xs text-stone-500"><?= $h(date('l, F j')) ?></div>
    </div>
    <?php if ($openSt): ?>
    <span class="px-3 py-1 rounded-full text-xs font-bold <?= $openSt['open'] ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-200 text-stone-600' ?>">
      <?= $openSt['open'] ? '🟢' : '⚪' ?> <?= $h($openSt['label']) ?><?= $openSt['detail'] ? ' · ' . $h($openSt['detail']) : '' ?>
    </span>
    <?php endif; ?>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 lg:gap-6">

    <!-- ═══ ACTION CENTER ═══ -->
    <section class="lg:col-span-2 space-y-3" aria-labelledby="acHead">
      <div class="flex items-center justify-between gap-2">
        <h2 id="acHead" class="text-sm font-black uppercase tracking-widest text-stone-500">Action Center</h2>
        <div class="flex items-center gap-1.5 text-[10px] font-bold">
          <?php foreach ($prioStyle as $pk => $ps): if (empty($prioCount[$pk])) continue; ?>
            <span class="px-2 py-0.5 rounded-full <?= $ps['chip'] ?>"><?= (int)$prioCount[$pk] ?> <?= $ps['label'] ?></span>
          <?php endforeach; ?>
        </div>
      </div>

      <div id="acList" class="space-y-3">
        <div id="acAllClear" class="<?= $card ?> p-6 text-center"<?= $alerts ? ' hidden' : '' ?>>
          <div class="text-2xl mb-1">✅</div>
          <div class="text-sm font-bold text-stone-700">All clear</div>
          <div class="text-xs text-stone-500">Anything that needs you (bookings, inquiries, low stock, system problems) shows up here.</div>
        </div>

        <?php foreach ($alerts as $al): $ps = $prioStyle[$al['priority']]; $dis = $al['priority'] === 'fyi'; ?>
        <article class="<?= $card ?> border-l-4 <?= $ps['bar'] ?> p-4"
                 data-alert="<?= $h($al['id']) ?>" data-sig="<?= $h((string)$al['count']) ?>"<?= $dis ? ' data-dismissible' : '' ?>>
          <div class="flex items-start gap-3">
            <div class="text-xl leading-none mt-0.5 shrink-0"><?= $al['icon'] ?></div>
            <div class="flex-1 min-w-0">
              <div class="flex flex-wrap items-center gap-2 mb-0.5">
                <span class="px-1.5 py-0.5 rounded text-[9px] font-black uppercase tracking-wider <?= $ps['chip'] ?>"><?= $ps['label'] ?></span>
                <h3 class="text-sm font-bold text-stone-900"><?= $h($al['title']) ?></h3>
              </div>
              <?php if ($al['detail'] !== ''): ?><p class="text-xs text-stone-500"><?= $h($al['detail']) ?></p><?php endif; ?>
              <?php if ($al['items']): ?>
              <ul class="mt-1 text-xs text-stone-600 list-disc pl-4 space-y-0.5">
                <?php foreach ($al['items'] as $it): ?><li class="break-words"><?= $h($it) ?></li><?php endforeach; ?>
              </ul>
              <?php endif; ?>
              <div class="flex flex-wrap gap-2 mt-3">
                <?php foreach ($al['actions'] as [$lab, $href, $primary]): ?>
                <a href="<?= $h($href) ?>" class="inline-flex items-center min-h-10 px-4 rounded-lg text-xs font-bold <?= $primary ? 'bg-stone-900 text-white hover:bg-stone-700' : 'bg-stone-100 text-stone-700 hover:bg-stone-200' ?>"><?= $h($lab) ?></a>
                <?php endforeach; ?>
                <?php if ($dis): ?>
                <button type="button" data-dismiss class="inline-flex items-center min-h-10 px-3 rounded-lg text-xs font-bold text-stone-400 hover:text-stone-700 hover:bg-stone-100">Dismiss</button>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
      <button type="button" id="acRestore" hidden class="text-xs font-bold text-stone-500 underline"></button>
    </section>

    <!-- ═══ TODAY + QUICK ACTIONS + METRICS ═══ -->
    <div class="space-y-4">

      <?php if ($quick): ?>
      <section aria-label="Quick actions" class="grid grid-cols-2 gap-2">
        <?php foreach ($quick as [$qi, $ql, $qh]): ?>
        <a href="<?= $h($qh) ?>" class="<?= $card ?> flex flex-col items-center justify-center gap-1 min-h-20 p-3 text-center hover:border-stone-400 transition">
          <span class="text-2xl leading-none"><?= $qi ?></span>
          <span class="text-xs font-bold text-stone-800"><?= $h($ql) ?></span>
        </a>
        <?php endforeach; ?>
      </section>
      <?php endif; ?>

      <section class="<?= $card ?> p-4" aria-labelledby="todayHead">
        <h2 id="todayHead" class="text-sm font-black uppercase tracking-widest text-stone-500 mb-2">Today</h2>
        <?php if ($hoursToday !== ''): ?>
          <div class="text-xs text-stone-700 mb-3"><span class="font-bold">Hours:</span> <?= $h($hoursToday) ?></div>
        <?php endif; ?>

        <?php if ($canV('bookings')): ?>
        <div class="mb-3">
          <div class="flex items-center justify-between text-xs font-bold text-stone-700 mb-1">
            <span>Bookings</span>
            <?php if ($todayBookings): ?><span class="text-stone-400 font-normal"><?= (int)$todayGuests ?> confirmed guests</span><?php endif; ?>
          </div>
          <?php if (!$todayBookings): ?>
            <p class="text-xs text-stone-400">No bookings today.</p>
          <?php else: ?>
          <ul class="divide-y divide-stone-100">
            <?php foreach (array_slice($todayBookings, 0, 6) as $b): ?>
            <li class="py-1.5 flex items-center gap-2 text-xs">
              <span class="font-mono text-stone-500 w-14 shrink-0"><?= $h(date('g:ia', strtotime($b['start_time']))) ?></span>
              <span class="flex-1 min-w-0 truncate"><span class="font-semibold text-stone-800"><?= $h($b['customer_name']) ?></span> <span class="text-stone-400">· <?= (int)$b['party_size'] ?> · <?= $h($b['label']) ?></span></span>
              <?php if ($b['status'] === 'pending'): ?><span class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-amber-100 text-amber-800">Pending</span><?php endif; ?>
              <?php if ((int)$b['is_exclusive'] === 1): ?><span class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-red-100 text-red-700">Private</span><?php endif; ?>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php if (count($todayBookings) > 6): ?><p class="text-[11px] text-stone-400 mt-1">+<?= count($todayBookings) - 6 ?> more</p><?php endif; ?>
          <?php endif; ?>
          <a href="<?= $h("$A?view=bookings&day=$today") ?>" class="inline-block mt-1 text-xs font-bold text-amber-700 underline">Open today's schedule</a>
        </div>
        <?php endif; ?>

        <?php if ($todayEvents): ?>
        <div class="mb-3">
          <div class="text-xs font-bold text-stone-700 mb-1">Farm events</div>
          <ul class="space-y-1">
            <?php foreach ($todayEvents as $ev): ?>
            <li class="text-xs"><span class="font-mono text-stone-500"><?= $h(date('g:ia', strtotime($ev['start_datetime']))) ?></span> <span class="font-semibold text-stone-800"><?= $h($ev['title'] ?? $ev['name'] ?? 'Event') ?></span></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <div>
          <div class="text-xs font-bold text-stone-700 mb-1">On shift now</div>
          <?php if (empty($active_shifts)): ?>
            <p class="text-xs text-stone-400">Nobody is clocked in.</p>
          <?php else: foreach ($active_shifts as $s): ?>
            <div class="flex items-center gap-2 text-xs font-semibold text-emerald-700 py-0.5">
              <span class="w-2 h-2 rounded-full bg-emerald-500 shrink-0"></span><?= $h($s['first_name'] . ' ' . $s['last_name']) ?>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </section>

      <?php if ($showSales):
        $tr = (float)($sales_today['rev'] ?? 0); $yr = (float)($sales_yesterday['rev'] ?? 0); ?>
      <section class="<?= $card ?> p-4" aria-labelledby="salesHead">
        <h2 id="salesHead" class="text-sm font-black uppercase tracking-widest text-stone-500 mb-2">Today's sales</h2>
        <div class="text-3xl font-black text-emerald-800"><?= $money($tr) ?></div>
        <div class="text-xs text-stone-500"><?= (int)($sales_today['count'] ?? 0) ?> transaction<?= (int)($sales_today['count'] ?? 0) === 1 ? '' : 's' ?></div>
        <?php if ($yr > 0): $diff = $tr - $yr; $up = $diff >= 0; ?>
          <div class="mt-2 text-[11px] font-bold font-mono <?= $up ? 'text-emerald-600' : 'text-red-500' ?>">
            <?= $up ? '▲ +' : '▼ -' ?><?= $money(abs($diff)) ?> (<?= (int)round(abs($diff) / $yr * 100) ?>%) vs yesterday
          </div>
        <?php else: ?>
          <div class="mt-2 text-[11px] font-mono text-stone-400">No sales yesterday to compare</div>
        <?php endif; ?>
        <div class="mt-2 pt-2 border-t border-stone-100 text-[11px] font-mono text-stone-500">7-day avg: <span class="font-bold text-stone-700"><?= $money($sales_7day_avg ?? 0) ?>/day</span></div>
      </section>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($showCal): ?>
  <!-- ═══ CALENDAR ═══ -->
  <section class="<?= $card ?> p-4 md:p-6 mt-6" aria-labelledby="calHead">
    <div class="flex items-center justify-between mb-4">
      <h2 id="calHead" class="text-sm font-bold text-stone-700"><?= $h($cal_first->format('F Y')) ?></h2>
      <div class="flex items-center gap-2">
        <a href="<?= $A ?>?view=dashboard&cal=<?= $cal_prev ?>" class="px-3 py-2 rounded bg-stone-100 text-stone-600 hover:bg-stone-200 text-xs" aria-label="Previous month">&larr;</a>
        <a href="<?= $A ?>?view=dashboard" class="px-3 py-2 rounded bg-stone-100 text-stone-600 hover:bg-stone-200 text-xs">Today</a>
        <a href="<?= $A ?>?view=dashboard&cal=<?= $cal_next ?>" class="px-3 py-2 rounded bg-stone-100 text-stone-600 hover:bg-stone-200 text-xs" aria-label="Next month">&rarr;</a>
      </div>
    </div>
    <div class="grid grid-cols-7 gap-1 text-center text-[10px] font-bold text-stone-400 uppercase mb-1">
      <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
    </div>
    <div class="grid grid-cols-7 gap-1">
      <?php for ($i = 0; $i < $cal_startDow; $i++): ?><div></div><?php endfor; ?>
      <?php for ($d = 1; $d <= $cal_days; $d++):
          $ds = sprintf('%04d-%02d-%02d', $cal_year, $cal_mon, $d); $isT = $ds === $today; ?>
        <a href="<?= $A ?>?view=bookings&day=<?= $ds ?>"
           class="aspect-square flex flex-col items-center justify-center rounded border text-xs transition <?= $isT ? 'border-amber-400 bg-amber-50 font-bold text-amber-800' : 'border-stone-100 hover:bg-stone-50 text-stone-600' ?>">
          <span><?= $d ?></span>
          <span class="flex gap-0.5 mt-0.5 h-1.5">
            <?php if (!empty($cal_bookings[$d])): ?><span class="w-1.5 h-1.5 rounded-full bg-green-500"></span><?php endif; ?>
            <?php if (!empty($cal_events[$d])): ?><span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span><?php endif; ?>
          </span>
        </a>
      <?php endfor; ?>
    </div>
    <div class="flex flex-wrap items-center gap-4 mt-4 text-[10px] text-stone-500">
      <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-green-500"></span> Bookings</span>
      <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-amber-500"></span> Farm events</span>
      <span class="ml-auto text-stone-400">Tap a day to manage its bookings</span>
    </div>
  </section>
  <?php endif; ?>
</div>
<script src="/admin/assets/admin-dashboard.js?v=20261105a"></script>
