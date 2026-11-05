<?php
// passes/log_visit.php — logs a gate visit when staff taps "Log this visit" on verify.php.
// Staff only (Nov 2026): the pass QR page is public, so a signed-in staff member who can use
// the POS or the Annual Passes screen (Permissions Matrix) must be the one logging the visit.
// Every logged visit goes in the Audit Log under that staff member's name.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../admin/includes/permission_checks.php';
require_once __DIR__ . '/../admin/includes/audit.php';
require_once __DIR__ . '/../includes/pass_staff.php';

$token   = trim((string)($_POST['token']    ?? ''));
$logCsrf = trim((string)($_POST['log_csrf'] ?? ''));
$back    = fn(string $q) => header('Location: /passes/verify.php?token=' . rawurlencode($token) . '&' . $q);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /passes/'); exit; }
if ($token === '') { header('Location: /passes/'); exit; }

// Staff check (same rule verify.php uses to show the button)
if (!bcrf_pass_can_log()) { $back('msg=signin'); exit; }

// CSRF
if (empty($_SESSION['pass_log_csrf']) || $logCsrf === '' || !hash_equals($_SESSION['pass_log_csrf'], $logCsrf)) {
    $back('msg=csrf'); exit;
}

try {
    $st = $pdo->prepare("SELECT p.id, p.status, p.holder_name, t.label FROM passes p
                         JOIN pass_types t ON t.id = p.pass_type_id WHERE p.token = ? LIMIT 1");
    $st->execute([$token]);
    $pass = $st->fetch(PDO::FETCH_ASSOC);

    if (!$pass || $pass['status'] !== 'active') { $back('msg=inactive'); exit; }

    // Double-tap guard: one visit per pass per 5 minutes
    $dup = $pdo->prepare("SELECT COUNT(*) FROM pass_visits WHERE pass_id = ? AND visited_at > (NOW() - INTERVAL 5 MINUTE)");
    $dup->execute([$pass['id']]);
    if ((int)$dup->fetchColumn() > 0) { $back('msg=already'); exit; }

    $pdo->prepare("INSERT INTO pass_visits (pass_id, visited_at) VALUES (?, NOW())")->execute([$pass['id']]);
    $visitId = (int)$pdo->lastInsertId();
    erp_audit($pdo, 'create', 'pass_visits', $visitId ?: null,
        'Visit logged - ' . $pass['holder_name'] . ' (' . $pass['label'] . ', pass #' . $pass['id'] . ')');
} catch (Throwable $e) {
    error_log('[BCRF pass log_visit] ' . $e->getMessage());
    $back('msg=error'); exit;
}

$back('logged=1'); exit;
