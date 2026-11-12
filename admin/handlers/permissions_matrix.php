<?php
// admin/handlers/permissions_matrix.php
require_once __DIR__ . '/../includes/audit.php';   // fix pack O: audit log
// Saves role permission checkboxes — both view and table permissions.

try {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['toast_msg']  = 'Security token expired. Please try again.';
        $_SESSION['toast_type'] = 'error';
        header('Location: /admin/admin.php?view=permissions'); exit;
    }

    $selected_role = $_POST['target_matrix_role'] ?? '';
    if (empty($selected_role)) throw new RuntimeException("No role specified.");

    // Nov 12, 2026: only the views and tables shown on the page change (includes/permission_sections.php). It used to
    // delete every row for the role first, which silently took away access the page doesn't show (tasks, calendar,
    // animal health, expenses, incidents).
    require_once dirname(__DIR__, 2) . '/includes/permission_sections.php';
    [$n_pages, $n_view, $n_edit] = bcrf_permission_save($pdo, $selected_role, (array)($_POST['view_permissions_block'] ?? []), (array)($_POST['perm_node'] ?? []));

    erp_audit($pdo, 'update', 'role_permissions', null,
        "Permissions for {$selected_role}: {$n_pages} pages, view {$n_view} / edit {$n_edit} tables");

    $_SESSION['toast_msg']  = "Permissions updated for: {$selected_role}";
    $_SESSION['toast_type'] = 'success';

} catch (Throwable $e) {
    error_log('[ERP] Permissions matrix save failed: ' . $e->getMessage());
    $_SESSION['toast_msg']  = "Save error: " . $e->getMessage();
    $_SESSION['toast_type'] = 'error';
}

header("Location: /admin/admin.php?view=permissions&inspect_role=" . urlencode($_POST['target_matrix_role'] ?? ''));
exit;
