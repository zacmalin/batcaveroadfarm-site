<?php
// /account/register_ajax.php — JSON registration for the modal.
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/email_verify.php';
require_once '../includes/rate_limit.php';   // server-side signup limits (Nov 2026)
header('Content-Type: application/json');
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'method']); exit; }

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

if (!csrf_verify(is_string($in['csrf'] ?? null) ? $in['csrf'] : null)) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Your session expired — please refresh and try again.']); exit;
}
if (!empty($in['website'])) { echo json_encode(['ok' => false, 'error' => 'Something went wrong.']); exit; }
// Server-side counters (survive dropped cookies) share buckets with register.php.
if (throttle_hit('register', 5, 1800) | bcrf_rl_hit('reg_ip', bcrf_rl_ip(), 5, 3600) | bcrf_rl_hit('reg_ipday', bcrf_rl_ip(), 10, 86400)) { echo json_encode(['ok' => false, 'error' => 'Too many sign-up attempts. Please wait a little while.']); exit; }

$r = auth_attempt_register($pdo, $in);
if ($r['ok']) { require_once __DIR__ . '/../includes/ga.php'; auth_login_user($r['user']); bcrf_ga_flash('sign_up', 'account'); @evf_issue($pdo, (int)$r['user']['id'], strtolower(trim((string)($in['email'] ?? ''))), (string)($r['user']['first_name'] ?? '')); echo json_encode(['ok' => true]); }
else { echo json_encode(['ok' => false, 'error' => $r['error']]); }
