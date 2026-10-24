<?php
// admin/handlers/jobs_hire.php
// Saves for the hire confirm screen and Hiring > Staff (Jobs pack P6).
// POST key jobs_hire_action:
//   hire          confirm screen: create/upgrade the account, stint, Hired, email
//   send_link     new set-password link (re-sends the welcome email)
//   cert          youth employment certificate number / received
//   end           end one job (stint), optionally turn the login off
//   login_on / login_off
// Administrators only (POST gate in admin.php and again here).

require_once __DIR__ . '/../../includes/jobs/hire.php';
require_once __DIR__ . '/../includes/audit.php';

$__jh_go = function (string $msg, string $type, string $qs) {
    $_SESSION['toast_msg']  = $msg;
    $_SESSION['toast_type'] = $type;
    header('Location: /admin/admin.php?' . $qs);
    exit;
};

if (($current_role ?? '') !== 'administrator') {
    $__jh_go('Administrator access required.', 'error', 'view=dashboard');
}
if (!isset($_POST['csrf_token']) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$_POST['csrf_token'])) {
    $__jh_go('Security token expired. Please try again.', 'error', 'view=jobs_staff');
}

$act    = (string)($_POST['jobs_hire_action'] ?? '');
$userId = (int)($_POST['user_id'] ?? 0);
$staffQs = 'view=jobs_staff&user=' . $userId;

switch ($act) {

    case 'hire': {
        $appId = (int)($_POST['app_id'] ?? 0);
        $app   = $appId > 0 ? jobs_app_find($pdo, $appId) : null;
        if (!$app) $__jh_go('That application was not found.', 'error', 'view=jobs');
        $back = 'view=jobs_hire&app=' . $appId;
        [$d, $err] = jobs_hire_validate($_POST, $app['age_bracket'] !== '18_plus');
        if ($err) {
            $_SESSION['jobs_form_old']['hire'] = $_POST;
            $__jh_go(implode(' ', $err), 'error', $back);
        }
        [$ok, $msg, $newUser] = jobs_hire_save($pdo, $appId, $d);
        if (!$ok) {
            $_SESSION['jobs_form_old']['hire'] = $_POST;
            $__jh_go($msg, 'error', $app['status'] === 'hired' ? 'view=jobs&app=' . $appId : $back);
        }
        $__jh_go($msg, 'success', 'view=jobs_staff&user=' . $newUser);
    }

    case 'send_link': {
        $user = jobs_user_find($pdo, $userId);
        if (!$user) $__jh_go('That person was not found.', 'error', 'view=jobs_staff');
        if ($user['account_status'] === 'disabled') $__jh_go('Their login is off. Turn it on first, then send the link.', 'error', $staffQs);
        $open = jobs_open_stints($pdo, $userId);
        if (!$open) $__jh_go('They have no current job here, so there\'s no welcome to send.', 'error', $staffQs);
        [$ok, $msg] = jobs_send_welcome($pdo, $user, $open[0]);
        if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'users', $userId, trim($user['first_name'] . ' ' . $user['last_name']) . ': set-password link sent');
        $__jh_go($ok ? 'New set-password link sent. Older links stop working.' : $msg, $ok ? 'success' : 'error', $staffQs);
    }

    case 'cert': {
        [$ok, $msg] = jobs_youth_cert_save($pdo, (int)($_POST['stint_id'] ?? 0), (string)($_POST['youth_cert_number'] ?? ''), !empty($_POST['youth_cert_received']));
        $__jh_go($msg, $ok ? 'success' : 'error', $staffQs);
    }

    case 'end': {
        [$ok, $msg] = jobs_employment_end($pdo, (int)($_POST['stint_id'] ?? 0), (string)($_POST['end_date'] ?? ''),
                                          (string)($_POST['reason'] ?? ''), !empty($_POST['login_off']));
        $__jh_go($msg, $ok ? 'success' : 'error', $staffQs);
    }

    case 'login_on':
    case 'login_off': {
        [$ok, $msg] = jobs_staff_set_login($pdo, $userId, $act === 'login_on');
        $__jh_go($msg, $ok ? 'success' : 'error', $staffQs);
    }

    default:
        $__jh_go('Unknown action.', 'error', 'view=jobs_staff');
}
