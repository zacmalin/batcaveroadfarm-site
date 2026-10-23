<?php
// admin/includes/permissions.php
// Defines hasTableAccess(), hasViewAccess(), and enforces all permission gates.

// ── MASTER VIEW REGISTRY ──────────────────────────────────────────────────────
// Single source of truth for every navigable view. Used by the permissions
// matrix UI and by hasViewAccess() gate checks.
// admin_only = true means the view is always locked to administrators only
// and never appears in the permissions matrix for other roles.
$admin_view_registry = [
    'dashboard'        => ['label' => 'Dashboard Overview',      'icon' => '📊', 'admin_only' => false],
    'receipts'         => ['label' => 'Sales Log',               'icon' => '🧾', 'admin_only' => false],
    'timecard'         => ['label' => 'Time Clock',              'icon' => '🕒', 'admin_only' => false],
    'media'            => ['label' => 'Image Library',           'icon' => '🖼️', 'admin_only' => false],
    'inventory_update' => ['label' => 'Quick Inventory Update',  'icon' => '📦', 'admin_only' => false],
    'newsletter'       => ['label' => 'Email Campaigns',         'icon' => '📧', 'admin_only' => false],
    'pos'              => ['label' => 'P.O.S. Register',          'icon' => '🛍️', 'admin_only' => false],
    'timesheet'           => ['label' => 'Staff Timesheet',          'icon' => '📋', 'admin_only' => false],
    'comment_moderation'  => ['label' => 'Comment Moderation',        'icon' => '💬', 'admin_only' => false],
    'audit_log'           => ['label' => 'Audit Log',                  'icon' => '📜', 'admin_only' => true],
    'sales_analytics'     => ['label' => 'Sales Analytics',            'icon' => '📈', 'admin_only' => false],
    'schema'           => ['label' => 'Schema Table Editor',     'icon' => '🗄️', 'admin_only' => true],
    'permissions'      => ['label' => 'Permissions Matrix',      'icon' => '🔒', 'admin_only' => true],
    'theme_scheduler'     => ['label' => 'Seasonal Themes',           'icon' => '🎨', 'admin_only' => true],
    'events'           => ['label' => 'Farm Events',          'icon' => '📅', 'admin_only' => false],
    'bookings'         => ['label' => 'Bookings',             'icon' => '📋', 'admin_only' => false],
    'site_info'        => ['label' => 'Site Info',            'icon' => '🏠', 'admin_only' => false],
    
];

// ── HAS TABLE ACCESS ──────────────────────────────────────────────────────────
if (!function_exists('hasTableAccess')) {
    function hasTableAccess(string $role, string $tableName, string $action = 'view'): bool {
        global $pdo;

        if ($role === 'administrator') return true;

        // Hard-coded role denials
        if ($role === 'Shopkeep' && in_array($tableName, ['users', 'blog_posts', 'newsletter'])) return false;
        if ($role === 'Media'    && in_array($tableName, ['users', 'fruits_vegetables', 'baked_goods', 'pantry', 'thrift_finds', 'petting_zoo_products', 'unit_conversions'])) return false;

        try {
            $column = ($action === 'edit') ? 'can_edit' : 'can_view';
            $stmt   = $pdo->prepare("SELECT {$column} FROM role_permissions WHERE role = ? AND table_name = ?");
            $stmt->execute([$role, $tableName]);
            return (int)$stmt->fetchColumn() === 1;
        } catch (Throwable $e) {
            error_log('[ERP] hasTableAccess DB error: ' . $e->getMessage());
            return false;
        }
    }
}

// ── HAS VIEW ACCESS ───────────────────────────────────────────────────────────
if (!function_exists('hasViewAccess')) {
    function hasViewAccess(string $role, string $viewName): bool {
        global $pdo, $admin_view_registry;

        // Administrators always have full access
        if ($role === 'administrator') return true;

        // Admin-only views are always blocked for non-admins
        if (isset($admin_view_registry[$viewName]) && $admin_view_registry[$viewName]['admin_only']) {
            return false;
        }

        // DB-driven check
        try {
            $stmt = $pdo->prepare("SELECT can_access FROM view_permissions WHERE role = ? AND view_name = ?");
            $stmt->execute([$role, $viewName]);
            $result = $stmt->fetchColumn();
            // If no row exists yet in DB, fail open — admin can lock down via the matrix
            if ($result === false) return true;
            return (int)$result === 1;
        } catch (Throwable $e) {
            error_log('[ERP] hasViewAccess DB error: ' . $e->getMessage());
            return true; // fail open on DB error too
        }
    }
}

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
