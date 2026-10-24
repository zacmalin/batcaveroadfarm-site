<?php
// admin/handlers/jobs_records.php
// Hiring > Records buttons (Jobs pack P8). POST key jobs_records_action:
//   purge     clear personal details on applications past the keep period (100 per tap)
//   spam      delete spam older than 30 days
//   alerts    delete job-alert signups stopped 90+ days ago
//   orphans   delete leftover résumé files that belong to no application
// Administrators only (POST gate in admin.php and again here). Each one needs
// the "I understand" box ticked, because none of this can be undone.

require_once __DIR__ . '/../../includes/jobs/retention.php';
require_once __DIR__ . '/../includes/audit.php';

$__jr_go = function (string $msg, string $type, string $qs = 'view=jobs_records') {
    $_SESSION['toast_msg']  = $msg;
    $_SESSION['toast_type'] = $type;
    header('Location: /admin/admin.php?' . $qs);
    exit;
};

if (($current_role ?? '') !== 'administrator') {
    $__jr_go('Administrator access required.', 'error', 'view=dashboard');
}
if (!isset($_POST['csrf_token']) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$_POST['csrf_token'])) {
    $__jr_go('Security token expired. Please try again.', 'error');
}

$months = jobs_retention_months($_POST['months'] ?? 12);
$pool   = !empty($_POST['pool']);
$qs     = 'view=jobs_records&months=' . $months . ($pool ? '&pool=1' : '');
if (empty($_POST['sure'])) $__jr_go('Tick "I understand this can\'t be undone" first.', 'error', $qs);
@set_time_limit(120);

switch ((string)($_POST['jobs_records_action'] ?? '')) {
    case 'purge': {
        [$done, $files, $left] = jobs_retention_purge($pdo, $months, $pool);
        if (!$done) $__jr_go('Nothing to clear.', 'success', $qs);
        $msg = 'Cleared ' . $done . ' application' . ($done === 1 ? '' : 's') . ' and deleted ' . $files . ' résumé' . ($files === 1 ? '' : 's') . '.';
        $__jr_go($msg . ($left ? ' ' . $left . ' more — tap Clear again.' : ' All done.'), 'success', $qs);
    }
    case 'spam': {
        [$n, $left] = jobs_spam_purge($pdo);
        $__jr_go($n ? 'Deleted ' . $n . ' spam application' . ($n === 1 ? '' : 's') . '.' . ($left ? ' ' . $left . ' more — tap again.' : '') : 'No old spam to delete.', 'success', $qs);
    }
    case 'alerts': {
        $n = jobs_alerts_purge($pdo);
        $__jr_go($n ? 'Deleted ' . $n . ' stopped job-alert signup' . ($n === 1 ? '' : 's') . '.' : 'Nothing to delete.', 'success', $qs);
    }
    case 'orphans': {
        $n = jobs_orphans_purge($pdo);
        $__jr_go($n ? 'Deleted ' . $n . ' leftover file' . ($n === 1 ? '' : 's') . '.' : 'No leftover files.', 'success', $qs);
    }
    default:
        $__jr_go('Unknown action.', 'error', $qs);
}
