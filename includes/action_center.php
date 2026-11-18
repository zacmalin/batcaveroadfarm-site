<?php
// includes/action_center.php — the dashboard's Action Center list, as one function (Nov 15, 2026).
// Moved out of admin/views/dashboard.php so the Owners' morning email (includes/task_notify.php) shows the same
// problems the dashboard does. Same rules as before: each alert only for people allowed to see that thing.
//   bcrf_action_alerts($pdo, $role, $ctx)   list of alerts, urgent first:
//       ['id', 'priority' => urgent|today|fyi, 'icon', 'title', 'items' => [...], 'detail', 'count', 'actions' => [[label, url, primary]]]
//   $ctx (all optional): low_stock_items, forecast_items, forecast_trends, pending_comments_count (the dashboard loader
//       has them already; low stock is looked up here when missing), today (farm date), email => true (leaves out the
//       ones that only make sense on screen: your own tasks, two-step sign-in, reorder forecast, comments).
// Nov 15, 2026 additions: papers out of date or running out (not yet replaced), and renewals due or late.
// Permission helpers for incidents, papers and renewals read $_SESSION['user_role']; the email sets it for each Owner.
if (!function_exists('bcrf_is_owner')) require_once __DIR__ . '/roles.php';   // bcrf_is_owner() (Nov 18, 2026)
if (!function_exists('bcrf_action_alerts')) {

function bcrf_action_alerts(PDO $pdo, string $role, array $ctx = []): array {
    $alerts = [];
    $add = function (array $a) use (&$alerts) { $alerts[] = $a + ['items' => [], 'actions' => [], 'detail' => '', 'count' => 1]; };
    $email = !empty($ctx['email']);
    $canV  = function (string $v) use ($role): bool {
        if (bcrf_is_owner($role)) return true;
        if ($role === 'Staff') return $v === 'timecard';
        return function_exists('hasViewAccess') && hasViewAccess($role, $v);
    };
    $canT  = function (string $t) use ($role): bool {
        if (bcrf_is_owner($role)) return true;
        if ($role === 'Staff') return false;
        return function_exists('hasTableAccess') && hasTableAccess($role, $t);
    };
    $isAdmin = bcrf_is_owner($role);
    $A       = '/admin/admin.php';
    $today   = (string)($ctx['today'] ?? date('Y-m-d'));   // Eastern (admin.php sets it); MySQL's clock is not.
    $soonDay = date('Y-m-d', strtotime($today . ' +1 day'));
    $forecast_items  = $email ? [] : ($ctx['forecast_items'] ?? []);
    $forecast_trends = $ctx['forecast_trends'] ?? [];
    $pending_comments_count = $email ? 0 : (int)($ctx['pending_comments_count'] ?? 0);
    $low_stock_items = $ctx['low_stock_items'] ?? null;
    if ($low_stock_items === null) {
        try {   // the one low-stock rule (includes/low_stock.php, Nov 18, 2026)
            require_once __DIR__ . '/low_stock.php';
            $low_stock_items = bcrf_low_stock_items($pdo);
        } catch (Throwable $e) { $low_stock_items = []; }
    }


    // Scheduled jobs (was cron_health_banner) — administrators
    if ($isAdmin) {
        try {
            require_once dirname(__DIR__) . '/includes/cron_health.php';
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
            require_once dirname(__DIR__) . '/includes/queue.php';
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
            require_once dirname(__DIR__) . '/includes/staff_auth.php';
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

    // Facebook & Instagram posting (was social_health_banner)
    if ($canV('social')) {
        try {
            require_once dirname(__DIR__) . '/includes/social_token.php';
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
            require_once dirname(__DIR__) . '/includes/adapter_pinterest.php';
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
                                                   bcrf_is_owner($role) ? [['Settings', '/admin/pinterest_connect.php', false]] : [])]);
                }
            }
        } catch (Throwable $e) { /* Pinterest tables not installed */ }
    }

    // PayPal / Venmo / Cash App payments to match (Farm OS Phase 1, slice 6; 05 › Peer-to-peer reconciliation)
    if ($canV('p2p_reconcile')) {
        try {
            require_once dirname(__DIR__) . '/includes/reconcile.php';
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
            require_once dirname(__DIR__) . '/includes/stock.php';
            if ($cf = bcrf_count_flags_recent($pdo, 7)) {
                $add(['id' => 'count_flags', 'priority' => 'today', 'icon' => '🔎',
                      'title' => count($cf) . ' big count difference' . (count($cf) === 1 ? '' : 's') . ' this week',
                      'items' => array_map(fn($r) => $r['name'] . ' at ' . $r['location_name'] . ': expected ' . bcrf_qtxt($r['expected_qty']) . ', counted ' . bcrf_qtxt($r['counted_qty']), array_slice($cf, 0, 5)),
                      'detail' => 'Over 20% or $25 off. Recount, or check for missed sales, spoilage or theft.', 'count' => count($cf),
                      'actions' => [['Open count', '/admin/stock.php?tab=count&id=' . (int)$cf[0]['count_id'], true], ['Stock history', '/admin/stock.php?tab=history&reason=count', false]]]);
            }
        } catch (Throwable $e) { /* stock tables not installed */ }
        $ls = $low_stock_items;
        if ($ls) {
            $add(['id' => 'low_stock', 'priority' => 'today', 'icon' => '📦',
                  'title' => count($ls) . ' item' . (count($ls) === 1 ? ' is' : 's are') . ' low on stock',
                  'items' => array_map(fn($i) => $i['name'] . ' — ' . (float)$i['stock_quantity'] . ' left', array_slice($ls, 0, 5)),
                  'detail' => count($ls) > 5 ? '…and ' . (count($ls) - 5) . ' more' : '', 'count' => count($ls),
                  'actions' => [function_exists('bcrf_stock_ready') && bcrf_stock_ready($pdo) ? ['See stock', '/admin/stock.php?low=1', true] : ['Count inventory', "$A?view=inventory_update", true],
                                ['Open catalog', "$A?view=schema&table=inventory", false]]]);
        }
        $fc = [];
        foreach ($forecast_items as $fi) {
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
    $pcc = (int)$pending_comments_count;
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

    // Tasks and chores due today or late (Farm OS Phase 3, Nov 11, 2026; includes/tasks.php)
    if (!$email && $canV('tasks')) {   // the email lists tasks itself
        try {
            require_once dirname(__DIR__) . '/includes/tasks.php';
            if (bcrf_tasks_ready($pdo)) {
                bcrf_tasks_generate($pdo);   // cheap when this week is already made
                $tm = bcrf_tasks_mine($pdo);
                $tl = count($tm['overdue']); $tt = count($tm['today']);
                if ($tl + $tt > 0) {
                    $items = [];
                    foreach (array_slice(array_merge($tm['overdue'], $tm['today']), 0, 4) as $t) {
                        $items[] = $t['title'] . ($t['due_date'] < bcrf_tasks_today() ? ' (late, ' . date('D', strtotime($t['due_date'])) . ')' : ($t['due_time'] ? ' — ' . bcrf_tasks_fmt_time($t['due_time']) : ''))
                                 . (count($t['checklist']) ? ' · ' . $t['checked'] . '/' . count($t['checklist']) : '');
                    }
                    if ($tl + $tt > 4) $items[] = '+' . ($tl + $tt - 4) . ' more';
                    $add(['id' => 'tasks', 'priority' => $tl ? 'urgent' : 'today', 'icon' => '✅',
                          'title' => ($tt ? $tt . ' task' . ($tt === 1 ? '' : 's') . ' today' : '') . ($tt && $tl ? ', ' : '') . ($tl ? $tl . ' late' : ''),
                          'items' => $items, 'count' => $tl * 1000 + $tt,
                          'actions' => [['Open my tasks', '/admin/tasks.php', true]]]);
                }
            }
        } catch (Throwable $e) { /* tasks not installed */ }
    }

    // Open incident reports (Farm OS Phase 5 slice 8 follow-up, Nov 12, 2026; includes/incidents.php) — Owners and anyone
    // with the 'incidents' view. A serious one makes it urgent.
    try {
        if (is_file(dirname(__DIR__) . '/includes/incidents.php')) {
            require_once dirname(__DIR__) . '/includes/incidents.php';
            if (bcrf_incidents_ready($pdo) && bcrf_incidents_can()) {
                $oi = bcrf_incident_list($pdo, ['status' => 'open'], 50);
                if ($oi) {
                    $serious = count(array_filter($oi, fn($i) => $i['severity'] === 'serious'));
                    $add(['id' => 'incidents', 'priority' => $serious ? 'urgent' : 'today', 'icon' => '🩹',
                          'title' => count($oi) . ' open incident report' . (count($oi) === 1 ? '' : 's') . ($serious ? ' (' . $serious . ' serious)' : ''),
                          'items' => array_map(fn($i) => '#' . (int)$i['id'] . ' ' . (BCRF_INCIDENT_TYPES[$i['type']] ?? $i['type']) . ' · ' . (BCRF_INCIDENT_SEVERITY[$i['severity']] ?? $i['severity'])
                                                         . ' · ' . bcrf_incident_local((string)$i['happened_at'])->format('D M j') . ($i['animal_names'] ? ' (' . $i['animal_names'] . ')' : ''), array_slice($oi, 0, 4)),
                          'detail' => (count($oi) > 4 ? '…and ' . (count($oi) - 4) . ' more. ' : '') . 'Follow up, then close each report.', 'count' => count($oi) * 10 + $serious,
                          'actions' => [count($oi) === 1 ? ['Open report', '/admin/incidents.php?id=' . (int)$oi[0]['id'], true] : ['Open incidents', '/admin/incidents.php?status=open', true]]]);
                }
            }
        }
    } catch (Throwable $e) { /* incidents not installed */ }

    // Kitchen products below the margin alert (Farm OS Phase 4 slice 6, Nov 13, 2026; includes/kitchen_costing.php). Costs from
    // stock follow receiving, so a price rise on an ingredient or jar shows here. Anyone who can see recipes.
    try {
        if ($canT('recipes') && is_file(dirname(__DIR__) . '/includes/kitchen_costing.php')) {
            require_once dirname(__DIR__) . '/includes/kitchen_costing.php';
            if (bcrf_kitchen_ready($pdo) && bcrf_kitchen_versions_ready($pdo) && bcrf_kitchen_costing_ready($pdo)) {
                $lowM = array_values(array_filter(bcrf_kitchen_cost_overview($pdo), fn($c) => $c['status'] === 'low'));
                if ($lowM) {
                    $add(['id' => 'kitchen_margin', 'priority' => 'fyi', 'icon' => '💲',
                          'title' => count($lowM) . ' kitchen product' . (count($lowM) === 1 ? '' : 's') . ' below the ' . rtrim(rtrim(number_format(bcrf_kitchen_margin_alert($pdo), 1), '0'), '.') . '% margin alert',
                          'items' => array_map(fn($c) => $c['product']['name'] . ' · ' . number_format($c['margin'], 1) . '% (costs ' . bcrf_kitchen_money($c['unit_cost']) . ', sells ' . bcrf_kitchen_money((float)$c['price']) . ')', array_slice($lowM, 0, 4)),
                          'detail' => 'Check the costs, or raise the price.', 'count' => count($lowM),
                          'actions' => [['Open costing', count($lowM) === 1 ? '/admin/kitchen_costing.php?product=' . (int)$lowM[0]['product']['id'] : '/admin/kitchen_costing.php', true]]]);
                }
            }
        }
    } catch (Throwable $e) { /* kitchen costing not installed */ }

    // Animals off visitor contact, and vaccinations due within 14 days or late (Nov 13, 2026; includes/animals.php,
    // includes/animal_health.php). Off-contact: anyone who can see the roster. Vaccinations: the 'animal_health' view.
    try {
        if (is_file(dirname(__DIR__) . '/includes/animal_health.php')) {
            require_once dirname(__DIR__) . '/includes/animal_health.php';
            if (bcrf_animals_ready($pdo) && $canT('animals')) {
                $offA = bcrf_animals_off_contact($pdo);
                if ($offA) {
                    $offQ = count(array_filter($offA, fn($a) => $a['status'] === 'quarantined'));
                    $add(['id' => 'animals_off', 'priority' => 'today', 'icon' => '🚧',
                          'title' => count($offA) . ' animal' . (count($offA) === 1 ? '' : 's') . ' off visitor contact' . ($offQ ? ' (' . $offQ . ' quarantined)' : ''),
                          'items' => array_map(fn($a) => $a['name'] . ' · ' . ($a['status'] === 'quarantined' ? 'Quarantined' : 'Resting') . ($a['enclosure_name'] ? ' · ' . $a['enclosure_name'] : ''), array_slice($offA, 0, 4)),
                          'detail' => (count($offA) > 4 ? '…and ' . (count($offA) - 4) . ' more. ' : '') . 'Keep visitors away until they are back to Active.',
                          'count' => count($offA) * 10 + $offQ,
                          'actions' => [['Open roster', '/admin/animals.php?f=off', true]]]);
                }
            }
            if (bcrf_health_ready($pdo) && $canV('animal_health')) {
                $hd = bcrf_health_due($pdo);
                $vd = $hd['vaccinations'];
                if ($vd) {
                    $late = count(array_filter($vd, fn($v) => $v['next_due'] < $hd['today']));
                    $add(['id' => 'vacc_due', 'priority' => $late ? 'urgent' : 'today', 'icon' => '💉',
                          'title' => count($vd) . ' vaccination' . (count($vd) === 1 ? '' : 's') . ' due in the next 14 days' . ($late ? ' (' . $late . ' late)' : ''),
                          'items' => array_map(fn($v) => $v['animal_name'] . ' · ' . $v['vaccine'] . ' · ' . ($v['next_due'] < $hd['today'] ? 'late since ' : 'due ') . date('D M j', strtotime($v['next_due'])), array_slice($vd, 0, 4)),
                          'detail' => (count($vd) > 4 ? '…and ' . (count($vd) - 4) . ' more. ' : '') . 'Record each one on the animal\'s Health tab when given.',
                          'count' => count($vd) * 10 + $late,
                          'actions' => [['Open Health', '/admin/animal_health.php', true]]]);
                }
            }
        }
    } catch (Throwable $e) { /* animals not installed */ }

    // Papers out of date or running out (Nov 15, 2026; includes/documents.php). Stays until a newer paper of the same
    // type is added for that vendor or animal, or it is deleted. Each person only sees papers they may open.
    try {
        require_once dirname(__DIR__) . '/includes/documents.php';
        if (bcrf_docs_ready($pdo) && bcrf_docs_expiry_ready($pdo) && bcrf_doc_can_any()) {
            $px = array_values(array_filter(bcrf_doc_expiring($pdo, $today, 200), 'bcrf_doc_can_see'));
            if ($px) {
                $out = count(array_filter($px, fn($d) => (string)$d['expires_on'] < $today));
                $what = fn($d) => (BCRF_DOC_TYPES[$d['doc_type']] ?? 'Paper') . (trim((string)($d['link_name'] ?? '')) !== '' ? ' for ' . $d['link_name'] : '');
                $add(['id' => 'papers', 'priority' => $out ? 'today' : 'fyi', 'icon' => '📎',
                      'title' => $out ? $out . ' paper' . ($out === 1 ? ' has' : 's have') . ' run out' . (count($px) > $out ? ', ' . (count($px) - $out) . ' more running out' : '')
                                      : count($px) . ' paper' . (count($px) === 1 ? '' : 's') . ' running out within ' . BCRF_DOC_EXPIRY_WARN_DAYS . ' days',
                      'items' => array_map(fn($d) => $what($d) . ' · ' . ((string)$d['expires_on'] < $today ? 'ran out ' : 'runs out ') . date('M j, Y', strtotime((string)$d['expires_on'])), array_slice($px, 0, 4)),
                      'detail' => (count($px) > 4 ? '…and ' . (count($px) - 4) . ' more. ' : '') . 'Ask for a new one and add it on the vendor or animal, then this clears.',
                      'count' => count($px) * 1000 + $out,
                      'actions' => [count($px) === 1 ? ['Open paper', '/admin/documents.php?id=' . (int)$px[0]['id'], true] : ['Open papers', '/admin/documents.php?show=expiring', true]]]);
            }
        }
    } catch (Throwable $e) { /* documents not installed */ }

    // Renewals due or late (Nov 15, 2026; includes/renewals.php): Owners, and anyone with the 'renewals' view.
    try {
        require_once dirname(__DIR__) . '/includes/renewals.php';
        if (bcrf_renewals_ready($pdo) && bcrf_renewals_can($role)) {
            $rd = array_values(array_filter(bcrf_renewal_list($pdo, 'upcoming'), fn($r) => bcrf_renewal_due_soon($r, $today)));
            if ($rd) {
                $late = count(array_filter($rd, fn($r) => (string)$r['renews_on'] < $today));
                $week = count(array_filter($rd, fn($r) => bcrf_renewal_days_left((string)$r['renews_on'], $today) <= 7));
                $add(['id' => 'renewals', 'priority' => $late ? 'urgent' : ($week ? 'today' : 'fyi'), 'icon' => '🔄',
                      'title' => count($rd) . ' renewal' . (count($rd) === 1 ? '' : 's') . ' coming up' . ($late ? ' (' . $late . ' late)' : ''),
                      'items' => array_map(fn($r) => (!empty($r['auto_renews']) ? 'Check it renewed: ' : '') . $r['title'] . ' · ' . bcrf_renewal_when((string)$r['renews_on'], $today), array_slice($rd, 0, 4)),
                      'detail' => (count($rd) > 4 ? '…and ' . (count($rd) - 4) . ' more. ' : '') . 'Tap Renewed on each one once it is done.',
                      'count' => count($rd) * 1000 + $late,
                      'actions' => [count($rd) === 1 ? ['Open renewal', '/admin/renewals.php?id=' . (int)$rd[0]['id'], true] : ['Open renewals', '/admin/renewals.php', true]]]);
            }
        }
    } catch (Throwable $e) { /* renewals not installed */ }

    if ($email) $alerts = array_values(array_filter($alerts, fn($a) => !in_array($a['id'], ['mfa', 'tasks'], true)));
    $prioOrder = ['urgent' => 0, 'today' => 1, 'fyi' => 2];
    usort($alerts, fn($a, $b) => $prioOrder[$a['priority']] <=> $prioOrder[$b['priority']]);
    return $alerts;
}

} // function_exists guard
