<?php
// /account/login_ajax.php — JSON login for the modal.
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/rate_limit.php';   // fix pack K: server-side login limits
header('Content-Type: application/json');
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'method']); exit; }

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

if (!csrf_verify(is_string($in['csrf'] ?? null) ? $in['csrf'] : null)) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Your session expired — please refresh and try again.']); exit;
}
if (!empty($in['website'])) { echo json_encode(['ok' => false, 'error' => 'Something went wrong.']); exit; }
if (throttle_hit('login', 8, 600) || bcrf_login_locked((string)($in['email'] ?? ''))) { echo json_encode(['ok' => false, 'error' => 'Too many attempts. Please wait a few minutes.']); exit; }

$r = auth_attempt_login($pdo, (string)($in['email'] ?? ''), (string)($in['password'] ?? ''));
if ($r['ok']) { bcrf_login_success((string)($in['email'] ?? '')); auth_login_user($r['user']); echo json_encode(['ok' => true]); }
else {
    if (($r['error'] ?? '') === 'Email or password is incorrect.') bcrf_login_failed((string)($in['email'] ?? ''));
    echo json_encode(['ok' => false, 'error' => $r['error']]);
}
