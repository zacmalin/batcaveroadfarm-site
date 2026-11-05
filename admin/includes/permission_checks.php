<?php
// admin/includes/permission_checks.php
// Permission data + functions only (no redirects), so direct-hit handlers can load it safely.
// admin/includes/permissions.php loads this and then applies the console page gates.
// Uses global $pdo.

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
    'search_analytics'    => ['label' => 'Search & Analytics',         'icon' => '🔎', 'admin_only' => false],
    'schema'           => ['label' => 'Schema Table Editor',     'icon' => '🗄️', 'admin_only' => true],
    'permissions'      => ['label' => 'Permissions Matrix',      'icon' => '🔒', 'admin_only' => true],
    'theme_scheduler'     => ['label' => 'Seasonal Themes',           'icon' => '🎨', 'admin_only' => true],
    'events'           => ['label' => 'Farm Events',          'icon' => '📅', 'admin_only' => false],
    'bookings'         => ['label' => 'Bookings',             'icon' => '📋', 'admin_only' => false],
    'site_info'        => ['label' => 'Site Info',            'icon' => '🏠', 'admin_only' => false],
    // Added Nov 2026 (audit #7): these screens were routed but missing here, so the matrix
    // couldn't grant them. Non-admins need a tick in the Permissions Matrix to use them.
    'passes'           => ['label' => 'Annual Passes',        'icon' => '🎟️', 'admin_only' => false],
    'event_inquiries'  => ['label' => 'Event Inquiries',      'icon' => '🎉', 'admin_only' => false],
    'social'           => ['label' => 'Social Posting',       'icon' => '📣', 'admin_only' => false],
    'jobs'             => ['label' => 'Hiring: Applicants',   'icon' => '📥', 'admin_only' => true],
    'jobs_emails'      => ['label' => 'Hiring: Emails',       'icon' => '📧', 'admin_only' => true],
    'jobs_staff'       => ['label' => 'Hiring: Staff',        'icon' => '🪪', 'admin_only' => true],
    'jobs_hire'        => ['label' => 'Hiring: Hire screen',  'icon' => '✅', 'admin_only' => true],
    'jobs_alerts'      => ['label' => 'Hiring: Job alerts',   'icon' => '🔔', 'admin_only' => true],
    'jobs_records'     => ['label' => 'Hiring: Records',      'icon' => '🗄️', 'admin_only' => true],
    'jobs_posts'       => ['label' => 'Hiring: Jobs',         'icon' => '🧑‍🌾', 'admin_only' => true],
    'jobs_questions'   => ['label' => 'Hiring: Question Bank', 'icon' => '❓', 'admin_only' => true],
    'jobs_categories'  => ['label' => 'Hiring: Categories',   'icon' => '🏷️', 'admin_only' => true],
    
];

$GLOBALS['admin_view_registry'] = $admin_view_registry;   // stay global even if this file is ever loaded from inside a function

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
            // Audit #7 (Nov 2026): fail CLOSED. A view with no saved row is not granted until an
            // administrator ticks it in the Permissions Matrix (the matrix already shows it unticked).
            // Exceptions: the dashboard (console home / redirect target) and Staff's Time Clock
            // (check_admin() sends Staff there; denying it would loop).
            if ($result === false) return $viewName === 'dashboard' || ($role === 'Staff' && $viewName === 'timecard');
            return (int)$result === 1;
        } catch (Throwable $e) {
            error_log('[ERP] hasViewAccess DB error: ' . $e->getMessage());
            return $viewName === 'dashboard' || ($role === 'Staff' && $viewName === 'timecard');   // fail closed
        }
    }
}


// ── REQUIRE PERMISSION (audit #8, Nov 2026) ───────────────────────────────────
// For handlers that are called directly (fetch/links) instead of through admin.php,
// so they get the same Permissions Matrix rules as the console. Pass one or more
// alternatives; any one passing is enough. Administrators always pass.
//   require_permission([['view', 'newsletter']]);
//   require_permission([['view', 'media'], ['view', 'site_info']], true);   // JSON reply
//   require_permission([['table', 'inventory', 'edit']]);
//   require_permission([['admin']]);
if (!function_exists('require_permission')) {
    function require_permission(array $anyOf, bool $json = false): void {
        $role = (string)($_SESSION['user_role'] ?? '');
        if ($role === 'administrator') return;
        if ($role !== '' && $role !== 'user') {
            foreach ($anyOf as $c) {
                $type = $c[0] ?? '';
                if ($type === 'view'  && hasViewAccess($role, (string)$c[1])) return;
                if ($type === 'table' && hasTableAccess($role, (string)$c[1], (string)($c[2] ?? 'view'))) return;
            }
        }
        error_log('[ERP] require_permission denied ' . ($role ?: 'guest') . ' on ' . basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(403);
        if ($json) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'message' => 'Your role does not have access to this.']); }
        else { echo 'Access denied.'; }
        exit;
    }
}

