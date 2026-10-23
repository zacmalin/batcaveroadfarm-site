<?php
require_once __DIR__ . '/../includes/audit.php';
// admin/handlers/inquiry_action.php — handles private event inquiry admin actions.
$action  = trim((string)($_POST['inquiry_action'] ?? ''));
$id      = (int)($_POST['id'] ?? 0);
$status  = trim((string)($_GET['status'] ?? 'new'));
$back    = '/admin/admin.php?view=event_inquiries&status=' . rawurlencode($status);

if ($action === 'status' && $id > 0) {
    $newStatus = trim((string)($_POST['new_status'] ?? ''));
    $allowed   = ['new','contacted','quoted','confirmed','declined','archived'];
    if (in_array($newStatus, $allowed, true)) {
        $pdo->prepare("UPDATE event_inquiries SET status=? WHERE id=?")->execute([$newStatus, $id]);
        erp_audit($pdo, 'update', 'event_inquiries', $id, erp_row_label($pdo, 'event_inquiries', $id) . ' - status ' . $newStatus);
        $_SESSION['toast_msg']  = 'Inquiry status updated to ' . $newStatus . '.';
        $_SESSION['toast_type'] = 'success';
    }
}

if ($action === 'note' && $id > 0) {
    $note = trim(strip_tags((string)($_POST['admin_notes'] ?? '')));
    $pdo->prepare("UPDATE event_inquiries SET admin_notes=? WHERE id=?")->execute([$note ?: null, $id]);
    erp_audit($pdo, 'update', 'event_inquiries', $id, erp_row_label($pdo, 'event_inquiries', $id) . ' - note ' . ($note ? 'saved' : 'cleared'));
    $_SESSION['toast_msg']  = 'Note saved.';
    $_SESSION['toast_type'] = 'success';
}

header('Location: ' . $back); exit;
