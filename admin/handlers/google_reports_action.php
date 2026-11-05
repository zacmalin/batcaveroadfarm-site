<?php
// admin/handlers/google_reports_action.php
// "Refresh now" on the Search & Analytics screen. Routed from admin/admin.php (CSRF and the
// view permission are checked there). Limited to once every 10 minutes.
require_once __DIR__ . '/../../includes/google_reports.php';

$last = null;
try {
    bcrf_greports_schema($pdo);
    $last = $pdo->query("SELECT MAX(GREATEST(COALESCE(fetched_at, '2000-01-01'), COALESCE(error_at, '2000-01-01'))) FROM google_report_cache")->fetchColumn();
} catch (Throwable $e) {}

if ($last && strtotime($last . ' UTC') > time() - 600) {
    $_SESSION['toast_msg']  = 'Refreshed less than 10 minutes ago. Google updates these numbers about once a day.';
    $_SESSION['toast_type'] = 'info';
} else {
    $res = bcrf_greports_refresh($pdo);
    $ok  = ($res['gsc'] === 'ok') + ($res['ga'] === 'ok');
    $_SESSION['toast_msg']  = $ok === 2 ? 'Search Console and Analytics refreshed.' : ($ok === 1 ? 'Partly refreshed. See the error on the page.' : 'Refresh failed. See the error on the page.');
    $_SESSION['toast_type'] = $ok === 2 ? 'success' : 'error';
}
header('Location: /admin/admin.php?view=search_analytics'); exit;
