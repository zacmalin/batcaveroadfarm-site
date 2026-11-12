<?php
// Included by admin/admin.php only (which checks login, CSRF and permissions first).
if (!isset($pdo, $current_role)) { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/audit.php';
// admin/handlers/inquiry_action.php — handles private event inquiry admin actions.
$action  = trim((string)($_POST['inquiry_action'] ?? ''));
$id      = (int)($_POST['id'] ?? 0);
$status  = trim((string)($_GET['status'] ?? 'new'));
$back    = '/admin/admin.php?view=event_inquiries&status=' . rawurlencode($status);

if ($action === 'status' && $id > 0) {
    $newStatus = trim((string)($_POST['new_status'] ?? ''));
    $allowed   = ['new','contacted','quoted','confirmed','declined','archived'];
    // Party date (Farm OS Phase 3, Nov 11): the agreed date can differ from the one asked for; checklists follow it.
    $newDate = trim((string)($_POST['event_date'] ?? ''));
    $dateOk = $newDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate) && checkdate((int)substr($newDate, 5, 2), (int)substr($newDate, 8, 2), (int)substr($newDate, 0, 4));
    if (in_array($newStatus, $allowed, true)) {
        $pdo->prepare("UPDATE event_inquiries SET status=?" . ($dateOk ? ", preferred_date=?" : '') . " WHERE id=?")->execute($dateOk ? [$newStatus, $newDate, $id] : [$newStatus, $id]);
        erp_audit($pdo, 'update', 'event_inquiries', $id, erp_row_label($pdo, 'event_inquiries', $id) . ' - status ' . $newStatus . ($dateOk ? ', date ' . $newDate : ''));
        $made = 0;
        try {   // make or cancel this party's checklist tasks (Tasks › Events); never stops the status change
            require_once __DIR__ . '/../../includes/event_tasks.php';
            $made = (int)bcrf_event_tasks_sync($pdo, 'event_inquiry', $id)['made'];
        } catch (Throwable $e) { error_log('[inquiries] checklist sync: ' . $e->getMessage()); }
        $_SESSION['toast_msg']  = 'Inquiry status updated to ' . $newStatus . '.' . ($made ? ' ' . $made . ' checklist task' . ($made === 1 ? '' : 's') . ' added.' : '');
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
