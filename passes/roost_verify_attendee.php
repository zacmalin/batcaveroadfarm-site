<?php
// Staff-only POST endpoint. Does not award points or expose customer information.
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/roost-attendance.php';
function roost_reply(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    roost_reply(405, ['ok'=>false,'error'=>'Method not allowed']);
}
if (!bcrf_pass_can_log()) roost_reply(403, ['ok'=>false,'error'=>'Staff access required']);
$csrf = (string)($_POST['csrf_token'] ?? '');
if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['roost_attendance_csrf']) ||
    $csrf === '' || !hash_equals((string)$_SESSION['roost_attendance_csrf'], $csrf)) {
    roost_reply(403, ['ok'=>false,'error'=>'Invalid session token']);
}
$checkIn = filter_var($_POST['check_in_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$customer = filter_var($_POST['customer_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$checkIn || !$customer) roost_reply(422, ['ok'=>false,'error'=>'Valid check-in and customer IDs required']);
try {
    $attendanceId = bcrf_roost_verify_attendee($pdo, $checkIn, $customer);
    roost_reply(201, ['ok'=>true,'attendance_id'=>$attendanceId,'points_awarded'=>0]);
} catch (BcrfRewardError $e) {
    roost_reply(422, ['ok'=>false,'error'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('[Roost attendance verification] '.$e->getMessage());
    roost_reply(500, ['ok'=>false,'error'=>'Verification failed']);
}
