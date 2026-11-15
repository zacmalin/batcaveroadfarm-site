<?php
// admin/nav_prefs.php — save your phone bottom-bar shortcuts on your account (Nov 15, 2026; includes/staff_prefs.php, migration 0068).
//   POST JSON {"shortcuts": ["dashboard", "storefront.pos", ...]}   saves up to 4 (an empty list = back to your role's defaults)
//   Header X-CSRF-Token: the session token (admin/assets/admin-nav.js sends it from navData).
// Answers JSON {ok:true, shortcuts:[...]} or {ok:false, error:"..."}. Not a page (tools/admin-shell-exceptions.txt).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
// Staff (Time Clock only) are bounced by check_admin() on any other file, so they get the same sign-in checks here directly.
if (($_SESSION['user_role'] ?? '') === 'Staff') {
    if (is_file(__DIR__ . '/../includes/staff_auth.php')) {
        require_once __DIR__ . '/../includes/staff_auth.php';
        if (function_exists('bcrf_staff_mfa_gate')) bcrf_staff_mfa_gate();
    }
} else {
    check_admin();
}
require_once __DIR__ . '/../includes/staff_prefs.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function (int $code, array $body): void { http_response_code($code); echo json_encode($body); exit; };

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(405, ['ok' => false, 'error' => 'POST only']);
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) $out(401, ['ok' => false, 'error' => 'Signed out']);
$token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $token)) {
    $out(403, ['ok' => false, 'error' => 'The page was open too long. Reload and try again.']);
}
if (!bcrf_staff_prefs_ready($pdo)) $out(503, ['ok' => false, 'error' => 'Not set up yet (migration 0068).']);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in) || !array_key_exists('shortcuts', $in)) $out(400, ['ok' => false, 'error' => 'Nothing to save']);
$list = bcrf_nav_shortcuts_clean($in['shortcuts']);
try {
    bcrf_staff_pref_set($pdo, $uid, 'nav_shortcuts', $list ? $list : null);
} catch (Throwable $e) {
    error_log('[nav_prefs] ' . $e->getMessage());
    $out(500, ['ok' => false, 'error' => 'Could not save']);
}
$out(200, ['ok' => true, 'shortcuts' => $list]);
