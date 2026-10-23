<?php
if ($current_role !== 'administrator') {
    echo "<p class='p-6 text-red-700 font-mono text-xs'>Access denied.</p>";
    return;
}

// ── SECTIONED PERMISSION MAP ──────────────────────────────────────────────────
// Mirrors the sidebar structure. type: 'view'|'table'|'admin_only'
$permission_sections = [
    'storefront' => ['label' => 'Storefront', 'icon' => '🏪', 'items' => [
        ['view',       'timecard',         '⏰', 'Time Clock'],
        ['view',       'pos',              '💳', 'POS Register'],
        ['view',       'inventory_update', '📦', 'Inventory'],
        ['table',      'inventory',        '🗂️', 'Inventory Editor'],
        ['table',      'inventory_categories', '🏷️', 'Inv. Categories'],
        ['view',       'receipts',         '🧾', 'Receipts'],
        ['view',       'bookings',         '📋', 'Bookings'],
        ['view',       'event_inquiries',  '🎉', 'Private Events'],
        ['view',       'passes',           '🎟', 'Annual Passes'],
    ]],
    'animals' => ['label' => 'Animals', 'icon' => '🐾', 'items' => [
        ['table', 'animals',         '📋', 'Roster'],
        ['table', 'breeds',          '🐄', 'Breeds'],
        ['table', 'classifications', '🔬', 'Classification'],
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
        ['view',       'site_info',       '🏠', 'Site Info'],
    ]],
];

$inspect_role   = $_GET['inspect_role'] ?? '';
$matrix_fetched = [];
$view_perms     = [];

if (!empty($inspect_role)) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM role_permissions WHERE role = ?");
        $stmt->execute([$inspect_role]);
        $matrix_fetched = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[ERP] Permissions view: table perms query failed: ' . $e->getMessage());
    }

    try {
        $stmt = $pdo->prepare("SELECT view_name, can_access FROM view_permissions WHERE role = ?");
        $stmt->execute([$inspect_role]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $view_perms[$row['view_name']] = (int)$row['can_access'];
        }
    } catch (Throwable $e) {
        error_log('[ERP] Permissions view: view perms query failed: ' . $e->getMessage());
        // Table may not exist yet — schema_safety will create it on next page load
    }
}
?>

<h1 class="text-2xl font-serif font-black mb-6">Access Permissions Matrix</h1>

<div class="max-w-4xl mx-auto space-y-6">

    <form method="POST" action="/admin/admin.php?view=permissions" class="space-y-6 font-sans text-xs">
        <input type="hidden" name="csrf_token"             value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) ?>">
        <input type="hidden" name="save_permissions_matrix" value="1">

        <!-- Role selector -->
        <div class="bg-white border rounded p-5 shadow-xs">
            <label class="block font-bold text-stone-700 text-sm mb-2">Select Role</label>
            <select name="target_matrix_role"
                onchange="window.location.href='/admin/admin.php?view=permissions&inspect_role='+encodeURIComponent(this.value)"
                class="w-full max-w-xs p-2.5 border rounded bg-stone-50 font-bold outline-none text-xs text-stone-800 cursor-pointer">
                <option value="">-- Choose Role --</option>
                <?php foreach (['Shopkeep','Media','moderator','user'] as $r): ?>
                    <option value="<?= htmlspecialchars($r, ENT_QUOTES) ?>"
                        <?= $inspect_role === $r ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if (!empty($inspect_role)): ?>

        <!-- ── SECTIONED PERMISSION MATRIX ── -->
        <?php foreach ($permission_sections as $sec_key => $sec): ?>
        <div class="bg-white border rounded shadow-xs overflow-hidden">
            <div class="bg-stone-900 text-stone-100 px-5 py-3 flex items-center gap-2">
                <span class="text-base"><?= $sec['icon'] ?></span>
                <h2 class="font-bold uppercase tracking-widest text-[10px]"><?= htmlspecialchars($sec['label']) ?></h2>
            </div>
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-stone-50 border-b text-stone-400 uppercase tracking-widest text-[9px] font-bold">
                        <th class="px-4 py-2">Module / Table</th>
                        <th class="px-4 py-2 text-center w-24">Access</th>
                        <th class="px-4 py-2 text-center w-24">Edit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                <?php foreach ($sec['items'] as [$itype, $ikey, $iicon, $ilabel]):
                    $is_admin_only = ($itype === 'admin_only');
                    $is_view  = ($itype === 'view');
                    $is_table = ($itype === 'table');
                    if ($is_view || $is_admin_only) {
                        $checked = ($view_perms[$ikey] ?? ($ikey === 'dashboard' ? 1 : 0)) === 1;
                    } else {
                        $v_checked = isset($matrix_fetched[$ikey]) && (int)$matrix_fetched[$ikey]['can_view'] === 1;
                        $e_checked = isset($matrix_fetched[$ikey]) && (int)$matrix_fetched[$ikey]['can_edit'] === 1;
                    }
                ?>
                <tr class="<?= $is_admin_only ? 'opacity-50 bg-stone-50' : 'hover:bg-stone-50' ?>">
                    <td class="px-4 py-2.5 font-bold text-stone-700">
                        <span class="mr-1"><?= $iicon ?></span>
                        <?= htmlspecialchars($ilabel) ?>
                        <?php if ($is_admin_only): ?>
                            <span class="ml-1 text-[9px] font-mono text-stone-400 uppercase tracking-wider normal-case">admin only</span>
                        <?php elseif ($is_view): ?>
                            <span class="ml-1 text-[9px] font-mono text-stone-400">view</span>
                        <?php else: ?>
                            <span class="ml-1 text-[9px] font-mono text-stone-400">table</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-2.5 text-center">
                        <?php if ($is_admin_only): ?>
                            <span class="text-stone-300">🔒</span>
                        <?php elseif ($is_view): ?>
                            <input type="checkbox"
                                name="view_permissions_block[<?= htmlspecialchars($ikey, ENT_QUOTES) ?>]"
                                value="1" class="w-4 h-4" <?= $checked ? 'checked' : '' ?>>
                        <?php else: ?>
                            <input type="checkbox"
                                name="perm_node[<?= htmlspecialchars($ikey, ENT_QUOTES) ?>][view]"
                                value="1" class="w-4 h-4" <?= $v_checked ? 'checked' : '' ?>>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-2.5 text-center">
                        <?php if ($is_admin_only || $is_view): ?>
                            <span class="text-stone-200">—</span>
                        <?php else: ?>
                            <input type="checkbox"
                                name="perm_node[<?= htmlspecialchars($ikey, ENT_QUOTES) ?>][edit]"
                                value="1" class="w-4 h-4" <?= $e_checked ? 'checked' : '' ?>>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>

        <div class="flex justify-end">
            <button type="submit"
                class="bg-stone-950 text-white font-mono font-bold uppercase tracking-wider py-2.5 px-8 rounded hover:bg-stone-800 transition shadow-xs">
                Save Permissions →
            </button>
        </div>

        <?php endif; ?>
    </form>
</div>
