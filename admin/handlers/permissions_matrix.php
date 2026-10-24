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

    // ── TABLE PERMISSIONS ─────────────────────────────────────────────────────
    $pdo->prepare("DELETE FROM role_permissions WHERE role = ?")->execute([$selected_role]);

    if (isset($_POST['perm_node'])) {
        $ins = $pdo->prepare("INSERT INTO role_permissions (role, table_name, can_view, can_edit) VALUES (?, ?, ?, ?)");
        foreach ($_POST['perm_node'] as $tbl => $actions) {
            $ins->execute([
                $selected_role,
                $tbl,
                isset($actions['view']) ? 1 : 0,
                isset($actions['edit']) ? 1 : 0,
            ]);
        }
    }

    // ── VIEW PERMISSIONS ──────────────────────────────────────────────────────
    // $admin_view_registry is already in scope — defined in includes/permissions.php
    // which is loaded at the top of admin.php before handlers run.
    $pdo->prepare("DELETE FROM view_permissions WHERE role = ?")->execute([$selected_role]);

    if (!empty($admin_view_registry)) {
        $ins_vp = $pdo->prepare("INSERT INTO view_permissions (role, view_name, can_access) VALUES (?, ?, ?)");
        foreach ($admin_view_registry as $view_name => $view_meta) {
            if ($view_meta['admin_only']) continue;
            $can_access = isset($_POST['view_permissions_block'][$view_name]) ? 1 : 0;
            $ins_vp->execute([$selected_role, $view_name, $can_access]);
        }
    }

    // Fix pack O: record who changed which role's access, and a short summary.
    $n_view = 0; $n_edit = 0;
    foreach (($_POST['perm_node'] ?? []) as $actions) {
        if (isset($actions['view'])) $n_view++;
        if (isset($actions['edit'])) $n_edit++;
    }
    $n_pages = count(array_filter((array)($_POST['view_permissions_block'] ?? [])));
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
