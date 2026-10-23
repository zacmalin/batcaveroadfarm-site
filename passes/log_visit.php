<?php
// passes/log_visit.php — logs a gate visit when staff taps "Log this visit" on verify.php.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../config.php';

$token   = trim((string)($_POST['token']    ?? ''));
$logCsrf = trim((string)($_POST['log_csrf'] ?? ''));

// Basic CSRF validation
if (empty($_SESSION['pass_log_csrf']) || $logCsrf === '' || !hash_equals($_SESSION['pass_log_csrf'], $logCsrf)) {
    header('Location: /passes/verify.php?token=' . rawurlencode($token) . '&msg=csrf'); exit;
}
if ($token === '') { header('Location: /passes/'); exit; }

try {
    $st = $pdo->prepare("SELECT id, status FROM passes WHERE token=? LIMIT 1");
    $st->execute([$token]);
    $pass = $st->fetch(PDO::FETCH_ASSOC);

    if ($pass && $pass['status'] === 'active') {
        $pdo->prepare("INSERT INTO pass_visits (pass_id, visited_at) VALUES (?, NOW())")
            ->execute([$pass['id']]);
    }
} catch (Throwable $e) {
    error_log('[BCRF pass log_visit] ' . $e->getMessage());
}

header('Location: /passes/verify.php?token=' . rawurlencode($token) . '&logged=1'); exit;
