<?php
// includes/pass_staff.php
// Who may log an annual-pass visit at the gate (Nov 2026).
// A signed-in staff member (not a public 'user' account, not the Time-Clock-only 'Staff' role)
// whose role can use the POS or the Annual Passes screen in the Permissions Matrix.
// Administrators always can. Used by passes/verify.php and passes/log_visit.php.
// Needs admin/includes/permission_checks.php (hasViewAccess) and global $pdo.

if (!function_exists('bcrf_pass_can_log')) {
    function bcrf_pass_can_log(): bool {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['user_id'])) return false;
        $role = (string)($_SESSION['user_role'] ?? '');
        if ($role === 'administrator') return true;
        if (!in_array($role, ['moderator', 'Shopkeep', 'Media'], true)) return false;
        if (!function_exists('hasViewAccess')) require_once dirname(__DIR__) . '/admin/includes/permission_checks.php';
        return hasViewAccess($role, 'passes') || hasViewAccess($role, 'pos');
    }
}
