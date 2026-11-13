<?php
// includes/nav_badges.php
// "Needs attention" counts on the admin menu (Nov 13, 2026). Each count sits on the tab where the work is done, and the
// menu adds them up the chain (tab -> item -> section), e.g. Kitchen › Ingredients › Label details.
// A count is only worked out for people who can act on it: the same permission the tab needs, or a stricter one
// (editing ingredients or recipes, the 'incidents' view). Mirrors the Dashboard's Action Center alerts.
// Cached in the session for 60 seconds so the menu stays fast; a fix can take up to a minute to clear the badge.
// Used by admin/views/layout/sidebar.php. Returns [tab link exactly as in the sidebar => ['n' => count, 'title' => text]].

if (!function_exists('bcrf_nav_badges')) {

/**
 * $can is the sidebar's permission check: fn(string $type, string $value): bool ('view' | 'table').
 * $tableEdit: fn(string $table): bool, edit rights on a table.
 */
function bcrf_nav_badges(PDO $pdo, string $role, callable $can, callable $tableEdit): array {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $key = $role . ':' . $uid;
    $c = $_SESSION['bcrf_nav_badges'] ?? null;
    if (is_array($c) && ($c['key'] ?? '') === $key && (int)($c['at'] ?? 0) > time() - 60) return (array)$c['b'];
    $b = bcrf_nav_badges_compute($pdo, $role, $can, $tableEdit);
    $_SESSION['bcrf_nav_badges'] = ['key' => $key, 'at' => time(), 'b' => $b];
    return $b;
}

/** Forget the cached counts (call after a change that clears one, if the page wants the badge gone at once). */
function bcrf_nav_badges_clear(): void { unset($_SESSION['bcrf_nav_badges']); }

function bcrf_nav_badges_compute(PDO $pdo, string $role, callable $can, callable $tableEdit): array {
    $inc = dirname(__DIR__) . '/includes/';
    $isAdmin = $role === 'administrator';
    $out = [];
    $set = function (string $href, int $n, string $title) use (&$out) { if ($n > 0) $out[$href] = ['n' => $n, 'title' => $title]; };
    $try = function (string $what, callable $f) {
        try { $f(); } catch (Throwable $e) { error_log('[nav_badges] ' . $what . ': ' . $e->getMessage()); }
    };
    $today = function () {
        $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
        return (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    };

    // Storefront › Products & Inventory › Stock: items at or below their own minimum (same rule as Stock › Low only)
    if ($can('view', 'inventory_update')) $try('low stock', function () use ($pdo, $set) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM inventory WHERE is_active = 1 AND track_inventory = 1
                               AND min_stock_level IS NOT NULL AND min_stock_level > 0 AND stock_quantity <= min_stock_level")->fetchColumn();
        $set('/admin/stock.php', $n, 'Items low on stock');
    });

    // Storefront › Sales & Drawers › P2P matching: flagged problems + payments not checked for too long
    if ($can('view', 'p2p_reconcile')) $try('p2p', function () use ($pdo, $set, $inc) {
        require_once $inc . 'reconcile.php';
        if (!bcrf_sales_ready($pdo)) return;
        $pc = bcrf_p2p_counts($pdo);
        $set('/admin/reconcile.php', $pc['flagged'] + $pc['stale'], 'PayPal/Venmo/Cash App payments flagged or not checked in time');
    });

    // Visitors › Bookings: requests waiting for confirmation
    if ($can('view', 'bookings')) $try('bookings', function () use ($pdo, $set, $today) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE status = 'pending' AND booking_date >= ?");
        $st->execute([$today()]);
        $set('?view=bookings', (int)$st->fetchColumn(), 'Booking requests waiting for confirmation');
    });

    // Visitors › Private Events: new inquiries
    if ($can('view', 'event_inquiries')) $try('inquiries', function () use ($pdo, $set) {
        $set('?view=event_inquiries', (int)$pdo->query("SELECT COUNT(*) FROM event_inquiries WHERE status = 'new'")->fetchColumn(), 'New private event inquiries');
    });

    // Animals › Health: vaccinations due in 14 days or late, and medical follow-ups due
    if ($can('view', 'animal_health')) $try('health', function () use ($pdo, $set, $inc) {
        require_once $inc . 'animal_health.php';
        if (!bcrf_health_ready($pdo)) return;
        $d = bcrf_health_due($pdo);
        $set('/admin/animal_health.php', count($d['vaccinations']) + count($d['follow_ups']), 'Vaccinations and follow-ups due');
    });

    // Animals › Incidents: open reports (people who can see them all)
    $try('incidents', function () use ($pdo, $set, $inc) {
        require_once $inc . 'incidents.php';
        if (!bcrf_incidents_ready($pdo) || !bcrf_incidents_can()) return;
        $set('/admin/incidents.php', (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE status = 'open'")->fetchColumn(), 'Open incident reports');
    });

    // Kitchen: ingredients missing label details; recipes whose label can't be made; products without an approved label
    if ($can('table', 'ingredients') && $tableEdit('ingredients')) $try('kitchen ingredients', function () use ($pdo, $set, $inc) {
        require_once $inc . 'kitchen.php';
        $set('/admin/kitchen_ingredients.php', bcrf_kitchen_attention_count($pdo), 'Ingredients missing label details');
    });
    if ($can('table', 'recipes') && $tableEdit('recipes')) $try('kitchen labels', function () use ($pdo, $set, $inc) {
        require_once $inc . 'kitchen_labels.php';
        if (!bcrf_kitchen_ready($pdo) || !bcrf_kitchen_versions_ready($pdo) || !bcrf_kitchen_labels_ready($pdo)) return;
        $recipes = 0; $labels = 0;
        foreach (bcrf_kitchen_label_overview($pdo) as $p) {
            if ($p['status'] === 'blocked') $recipes++;   // recipe not ready, no version, or changed since its version
            elseif ($p['status'] !== 'approved') $labels++;   // a label to draft or approve
        }
        $set('/admin/kitchen_recipes.php', $recipes, 'Kitchen products whose recipe needs fixing or a new version');
        $set('/admin/kitchen_labels.php', $labels, 'Kitchen products without an approved label');
    });

    // Marketing › Social Queue: posts that failed in the last 14 days
    if ($can('view', 'social')) $try('social', function () use ($pdo, $set) {
        $set('?view=social', (int)$pdo->query("SELECT COUNT(*) FROM social_queue WHERE status IN ('failed','partial') AND processed_at >= NOW() - INTERVAL 14 DAY")->fetchColumn(), 'Posts that failed in the last 14 days');
    });

    // Team › Tasks › My tasks: late or due today
    if ($can('view', 'tasks')) $try('tasks', function () use ($pdo, $set, $inc) {
        require_once $inc . 'tasks.php';
        $tc = bcrf_tasks_counts($pdo);
        $set('/admin/tasks.php?tab=my', $tc['overdue'] + $tc['today'], 'Your tasks late or due today');
    });

    // Settings (Owners): System Health problems; your own two-step sign-in
    if ($isAdmin) {
        $try('system health', function () use ($pdo, $set, $inc) {
            require_once $inc . 'cron_health.php';
            $n = count(bcrf_cron_problems($pdo));
            require_once $inc . 'queue.php';
            if (bcrf_queue_ready($pdo)) $n += (int)$pdo->query("SELECT COUNT(*) FROM queue_jobs WHERE status = 'dead'")->fetchColumn();
            $set('/admin/system_health.php', $n, 'Scheduled or background jobs that need attention');
        });
        $try('two-step', function () use ($pdo, $set, $inc) {
            require_once $inc . 'staff_auth.php';
            if (!bcrf_mfa_ready($pdo)) return;
            $me = bcrf_mfa_row($pdo, (int)($_SESSION['user_id'] ?? 0));
            $set('/admin/two_step.php', ($me && empty($me['totp_enabled_at'])) ? 1 : 0, 'Turn on two-step sign-in');
        });
    }
    return $out;
}

}
