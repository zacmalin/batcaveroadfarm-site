<?php // admin/social_token_check.php
// "Check now" for the Facebook token banner (Nov 2026): re-checks the token with Facebook
// and returns to where you came from. Staff who can see the Social Queue or are administrators.
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['view', 'social']]);
require_once __DIR__ . '/../includes/social_token.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');   // farm time zone setting (Phase 0, C-03)

$s = bcrf_social_token_check();
[$level, $msg] = bcrf_social_token_summary($s);
$_SESSION['toast_msg']  = $msg;
$_SESSION['toast_type'] = $level === 'bad' ? 'error' : 'success';
$back = (($_GET['from'] ?? '') === 'dashboard') ? 'dashboard' : 'social';
header('Location: /admin/admin.php?view=' . $back);
exit;
