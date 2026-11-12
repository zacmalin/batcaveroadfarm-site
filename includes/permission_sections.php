<?php
// includes/permission_sections.php — what the Permissions page shows, by section (moved out of admin/views/permissions.php
// Nov 12, 2026, so the page and its save handler use one list). Each item: [type, key, icon, label];
// type 'view' (view_permissions), 'table' (role_permissions) or 'admin_only' (shown locked).
// Saving changes only the items listed here, so a view or table missing from this list keeps whatever it had.
if (!function_exists('bcrf_permission_sections')) {

function bcrf_permission_sections(): array {
    return [
        'storefront' => ['label' => 'Storefront', 'icon' => '🏪', 'items' => [
            ['view',       'timecard',         '⏰', 'Time Clock'],
            ['view',       'pos',              '💳', 'POS Register'],
            ['view',       'pos_discount_override', '🏷️', 'POS Discount Override (any %, free items)'],
            ['view',       'p2p_reconcile',    '🤝', 'P2P Reconcile (PayPal, Venmo, Cash App)'],
            ['view',       'inventory_update', '📦', 'Inventory'],
            ['table',      'inventory',        '🗂️', 'Inventory Editor'],
            ['table',      'inventory_categories', '🏷️', 'Inv. Categories'],
            ['view',       'receipts',         '🧾', 'Receipts'],
            ['view',       'customers',        '👥', 'Customers'],
            ['view',       'bookings',         '📋', 'Bookings'],
            ['view',       'event_inquiries',  '🎉', 'Private Events'],
            ['view',       'passes',           '🎟', 'Annual Passes'],
        ]],
        'operations' => ['label' => 'Operations', 'icon' => '✅', 'items' => [
            ['view', 'tasks',        '✅', 'My Tasks'],
            ['view', 'tasks_manage', '🗂️', 'Manage Tasks, Chores and Checklists'],
            ['view', 'calendar',     '📅', 'Calendar'],
        ]],
        'animals' => ['label' => 'Animals', 'icon' => '🐾', 'items' => [
            ['table', 'animals',         '📋', 'Roster (edit = change animals and public pages)'],
            ['table', 'breeds',          '🐄', 'Breeds'],
            ['table', 'classifications', '🔬', 'Classification'],
            ['view',  'animal_health',   '🩺', 'Health (medical records, medications, vaccinations)'],
            ['view',  'animal_expenses', '🧾', 'Expenses (costs)'],
            ['view',  'incidents',       '🚑', 'Incidents (see, change and close every report)'],
        ]],
        'kitchen' => ['label' => 'Kitchen', 'icon' => '🍳', 'items' => [
            ['table', 'recipes',           '📖', 'Recipes'],
            ['table', 'cuisines',          '🌍', 'Cuisines'],
            ['table', 'ingredients',       '🌿', 'Ingredients'],
            ['table', 'categories',        '🏷️', 'Categories'],
            ['table', 'measurement_units', '⚖️', 'Measurement Units'],
            ['table', 'unit_conversions',  '🔄', 'Unit Conversions'],
        ]],
        'media' => ['label' => 'Media', 'icon' => '📡', 'items' => [
            ['table', 'blog_posts',         '✍️', 'Blog'],
            ['table', 'blog_categories',    '🏷️', 'Blog Categories'],
            ['view',  'newsletter',         '📧', 'Newsletter'],
            ['view',  'social',             '📣', 'Social Queue'],
            ['view',  'events',             '📅', 'Events'],
            ['view',  'media',              '🖼️', 'Image Library'],
            ['view',  'comment_moderation', '💬', 'Comments'],
            ['table', 'mailing_list',       '👥', 'Subscribers'],
        ]],
        'admin' => ['label' => 'Admin', 'icon' => '⚙️', 'items' => [
            ['view',       'timesheet',   '📋', 'Timesheets'],
            ['admin_only', 'permissions', '🔐', 'Permission Matrix'],
            ['table',      'users',       '👤', 'Staff'],
            ['view',       'sales_analytics', '📈', 'Sales Analytics'],
            ['view',       'search_analytics', '🔎', 'Search & Analytics'],
            ['view',       'site_info',       '🏠', 'Site Info'],
        ]],
    ];
}

/** Save one role's ticks. $views / $tables are the posted view_permissions_block and perm_node arrays.
 *  Only items on the page change; everything else the role has is left alone. Returns [pages, view tables, edit tables]. */
function bcrf_permission_save(PDO $pdo, string $role, array $views, array $tables): array {
    if (!in_array($role, ['Shopkeep', 'Media', 'moderator', 'user'], true)) throw new RuntimeException('Pick a role to change.');
    $vp = $pdo->prepare("INSERT INTO view_permissions (role, view_name, can_access) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE can_access = VALUES(can_access)");
    $rp = $pdo->prepare("INSERT INTO role_permissions (role, table_name, can_view, can_edit) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_edit = VALUES(can_edit)");
    $n = [0, 0, 0];
    $pdo->beginTransaction();
    try {
        foreach (bcrf_permission_sections() as $sec) {
            foreach ($sec['items'] as [$type, $key]) {
                if ($type === 'view') {
                    $on = !empty($views[$key]) ? 1 : 0;
                    $vp->execute([$role, $key, $on]); $n[0] += $on;
                } elseif ($type === 'table') {
                    $v = !empty($tables[$key]['view']) ? 1 : 0; $e = !empty($tables[$key]['edit']) ? 1 : 0;
                    $rp->execute([$role, $key, $v, $e]); $n[1] += $v; $n[2] += $e;
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return $n;
}

}
