<?php
// admin/jobs_file.php
// Download a job applicant's résumé: /admin/jobs_file.php?id={application_files.id}
// Administrators only. Files live outside the website folder, so this is the only way in.
require_once '../config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../includes/jobs/upload.php';

$uid = (int)($_SESSION['user_id'] ?? 0);
$ok = false;
if ($uid && ($_SESSION['user_role'] ?? '') === 'administrator') {
    // Re-check the role in the database in case it changed since sign-in.
    $st = $pdo->prepare("SELECT role, account_status FROM users WHERE id = ?");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    $ok = $u && $u['role'] === 'administrator' && ($u['account_status'] ?? 'active') !== 'disabled';
}
if (!$ok) { http_response_code(403); exit('Access denied.'); }

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT f.*, a.anonymized_at FROM application_files f JOIN applications a ON a.id = f.application_id WHERE f.id = ?");
$st->execute([$id]);
$f = $st->fetch(PDO::FETCH_ASSOC);
$path = $f ? jobs_upload_path((string)$f['stored_name']) : '';
if (!$f || $path === '') { http_response_code(404); exit('File not found.'); }

$name = preg_replace('/[^A-Za-z0-9 ._()-]/', '_', (string)$f['original_name']) ?: 'resume.pdf';
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode((string)$f['original_name']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
