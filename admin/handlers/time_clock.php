<?php
require_once __DIR__ . '/../includes/audit.php';
// admin/handlers/time_clock.php
// Handles clock-in and clock-out POST submissions.

try {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['toast_msg']  = 'Security token expired. Please try again.';
        $_SESSION['toast_type'] = 'error';
        header('Location: /admin/admin.php?view=timecard');
        exit;
    }

    $action = $_POST['time_clock_action'];
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $now    = date('Y-m-d H:i:s');

    if ($action === 'clock_in') {
        $stmt = $pdo->prepare("INSERT INTO time_cards (user_id, clock_in, ip_address, notes) VALUES (?, ?, ?, ?)");
        $stmt->execute([$current_user_id, $now, $ip, trim($_POST['clock_notes'] ?? '')]);
        erp_audit($pdo, 'create', 'time_cards', (int)$pdo->lastInsertId(), 'Clocked in ' . date('n/j g:i A', strtotime($now)));
        $_SESSION['toast_msg'] = "Clocked IN — shift session logged securely.";
    } elseif ($action === 'clock_out') {
        $stmt = $pdo->prepare("UPDATE time_cards SET clock_out = ? WHERE user_id = ? AND clock_out IS NULL ORDER BY id DESC LIMIT 1");
        $stmt->execute([$now, $current_user_id]);
        if ($stmt->rowCount() > 0) erp_audit($pdo, 'update', 'time_cards', null, 'Clocked out ' . date('n/j g:i A', strtotime($now)));
        $_SESSION['toast_msg'] = "Clocked OUT — shift session completed successfully.";
    }

    $_SESSION['toast_type'] = 'success';
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;

} catch (Throwable $e) {
    error_log('[ERP] Time clock handler failed: ' . $e->getMessage());
    $_SESSION['toast_msg']  = "Time clock error — please try again.";
    $_SESSION['toast_type'] = 'error';
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}
