<?php
// admin/handlers/jobs_alerts.php
// Job alerts + Talent Pool notices (Jobs pack P7).
// POST key jobs_alerts_action:
//   notify      "Let people know" on a job: sends the next batch (40) of
//               job alerts and/or Talent Pool emails for its open round
//   sub_remove  Hiring > Alerts: stop alerts for one address
// Administrators only (POST gate in admin.php and again here).

require_once __DIR__ . '/../../includes/jobs/alerts.php';
require_once __DIR__ . '/../includes/audit.php';

$__ja_go = function (string $msg, string $type, string $qs) {
    $_SESSION['toast_msg']  = $msg;
    $_SESSION['toast_type'] = $type;
    header('Location: /admin/admin.php?' . $qs);
    exit;
};

if (($current_role ?? '') !== 'administrator') {
    $__ja_go('Administrator access required.', 'error', 'view=dashboard');
}
if (!isset($_POST['csrf_token']) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$_POST['csrf_token'])) {
    $__ja_go('Security token expired. Please try again.', 'error', 'view=jobs_alerts');
}

switch ((string)($_POST['jobs_alerts_action'] ?? '')) {

    case 'notify': {
        $jobId = (int)($_POST['job_id'] ?? 0);
        $back  = 'view=jobs_posts&edit=' . $jobId . '#notify';
        $which = array_values(array_intersect(['alerts', 'pool'], (array)($_POST['which'] ?? [])));
        if ($jobId <= 0) $__ja_go('That job was not found.', 'error', 'view=jobs_posts');
        if (!$which) $__ja_go('Tick Job alerts, Talent Pool, or both.', 'error', $back);
        @set_time_limit(120);
        $r = jobs_notify_send($pdo, $jobId, $which);
        if ($r['sent'] === 0 && $r['failed'] === 0) {
            $__ja_go($r['error'] !== '' ? $r['error'] : 'Everyone has already been told about this round.', $r['error'] !== '' ? 'error' : 'success', $back);
        }
        $left = 0;
        if (in_array('alerts', $which, true)) $left += $r['left_alerts'];
        if (in_array('pool', $which, true))   $left += $r['left_pool'];
        $msg = $r['sent'] . ' email' . ($r['sent'] === 1 ? '' : 's') . ' sent';
        if ($r['failed']) $msg .= ', ' . $r['failed'] . ' failed (see Emails > Sent log)';
        $msg .= $left ? '. ' . $left . ' still to go — tap Send now again.' : '. Everyone has been told.';
        if ($r['error'] !== '') $msg .= ' ' . $r['error'];
        $__ja_go($msg, $r['failed'] || $r['error'] !== '' ? 'error' : 'success', $back);
    }

    case 'sub_remove': {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $qs    = 'view=jobs_alerts' . (isset($_POST['back']) ? '&' . preg_replace('/[^a-z0-9_=&%.\-+@]/i', '', (string)$_POST['back']) : '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $__ja_go('That address was not found.', 'error', 'view=jobs_alerts');
        if (jobs_alert_unsubscribe($pdo, $email)) {
            [, $uname] = jobs_actor();
            erp_audit($pdo, 'update', 'job_alert_subscribers', 0, 'Job alerts stopped by ' . $uname . ': ' . $email);
            $__ja_go('Job alerts stopped for ' . $email . '.', 'success', $qs);
        }
        $__ja_go($email . ' was already stopped.', 'success', $qs);
    }

    default:
        $__ja_go('Unknown action.', 'error', 'view=jobs_alerts');
}
