<?php
// admin/includes/permissions.php
// Defines hasTableAccess(), hasViewAccess(), and enforces all permission gates.

require_once __DIR__ . '/permission_checks.php';   // registry, hasTableAccess(), hasViewAccess(), require_permission()

// ── VIEW-LEVEL GATES ──────────────────────────────────────────────────────────
// Administrators bypass all gates. Everyone else is checked.

if ($current_role !== 'administrator') {

    // DB-driven view gate — redirect blocked views to dashboard.
    // Dashboard itself is exempt: it's the console home and the redirect
    // target, so gating it would create an infinite redirect loop.
    if ($target_view !== 'dashboard' && isset($admin_view_registry[$target_view]) && !hasViewAccess($current_role, $target_view)) {
        $_SESSION['toast_msg']  = 'Your role does not have access to that module.';
        $_SESSION['toast_type'] = 'error';
        header('Location: /admin/admin.php?view=dashboard');
        exit;
    }

    // Admin-only views — hard gate regardless of DB
    if (in_array($target_view, ['permissions', 'schema'])) {
        $_SESSION['toast_msg']  = 'Administrator access required.';
        $_SESSION['toast_type'] = 'error';
        header('Location: /admin/admin.php?view=dashboard');
        exit;
    }

    // POS gate
    if ($target_view === 'pos' && $current_role === 'Media') {
        die("<div class='p-6 text-red-700 font-mono text-xs'>Security Exception: Role [ Media ] is blocked from POS access.</div>");
    }
}

// ── TABLE-LEVEL GATE (schema view) ────────────────────────────────────────────
if ($target_view === 'schema' && !empty($target_table)) {
    if (!hasTableAccess($current_role, $target_table, 'view')) {
        die("<div class='p-6 text-red-700 font-mono text-xs'>Security Exception: Access Denied to Table [ " . htmlspecialchars($target_table) . " ].</div>");
    }
}
