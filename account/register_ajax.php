<?php
// /account/register_ajax.php — JSON registration for the modal.
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/email_verify.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'method']); exit; }

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

if (!csrf_verify(is_string($in['csrf'] ?? null) ? $in['csrf'] : null)) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Your session expired — please refresh and try again.']); exit;
}
if (!empty($in['website'])) { echo json_encode(['ok' => false, 'error' => 'Something went wrong.']); exit; }
if (throttle_hit('register', 5, 1800)) { echo json_encode(['ok' => false, 'error' => 'Too many sign-up attempts. Please wait a little while.']); exit; }

$r = auth_attempt_register($pdo, $in);
if ($r['ok']) { auth_login_user($r['user']); @evf_issue($pdo, (int)$r['user']['id'], strtolower(trim((string)($in['email'] ?? ''))), (string)($r['user']['first_name'] ?? '')); echo json_encode(['ok' => true]); }
else { echo json_encode(['ok' => false, 'error' => $r['error']]); }
