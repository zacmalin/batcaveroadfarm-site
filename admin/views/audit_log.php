<?php
// views/audit_log.php
if ($current_role !== 'administrator') {
    echo "<p class='p-6 text-red-700 font-mono text-xs'>Access denied.</p>";
    return;
}

$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset   = ($page - 1) * $per_page;

$filter_table  = $_GET['tbl']    ?? '';
$filter_action = $_GET['action'] ?? '';
$filter_user   = $_GET['user']   ?? '';

$where  = [];
$params = [];
if ($filter_table)  { $where[] = 'table_name = ?';  $params[] = $filter_table; }
if ($filter_action) { $where[] = 'action = ?';       $params[] = $filter_action; }
if ($filter_user)   { $where[] = 'user_name LIKE ?'; $params[] = '%' . $filter_user . '%'; }

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$logs  = [];
$total = 0;
try {
    // Count matching rows (prepared, so filters work)
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM audit_log $where_sql");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM audit_log $where_sql ORDER BY created_at DESC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[ERP] audit_log query failed: ' . $e->getMessage());
}

$pages = max(1, (int)ceil($total / $per_page));
?>

<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-2xl font-serif font-black text-stone-800">Audit Log</h1>
        <p class="text-xs text-stone-400 font-mono mt-0.5"><?= number_format($total) ?> entries</p>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="flex flex-wrap gap-3 mb-6 text-xs font-mono bg-white border rounded p-4">
    <input type="hidden" name="view" value="audit_log">
    <input type="text" name="user" placeholder="User name" value="<?= htmlspecialchars($filter_user) ?>"
        class="border rounded p-2 bg-stone-50 outline-none w-40">
    <select name="action" class="border rounded p-2 bg-stone-50 outline-none">
        <option value="">All actions</option>
        <?php foreach (['create','update','delete'] as $a): ?>
        <option value="<?= $a ?>" <?= $filter_action === $a ? 'selected' : '' ?>><?= ucfirst($a) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="text" name="tbl" placeholder="Table name" value="<?= htmlspecialchars($filter_table) ?>"
        class="border rounded p-2 bg-stone-50 outline-none w-44">
    <button type="submit" class="bg-stone-900 text-white px-4 py-2 rounded hover:bg-stone-700 transition uppercase tracking-wider text-[10px] font-bold">Filter</button>
    <a href="?view=audit_log" class="border px-4 py-2 rounded hover:bg-stone-100 transition uppercase tracking-wider text-[10px] font-bold text-stone-600">Clear</a>
</form>

<div class="bg-white border rounded shadow-xs overflow-hidden">
    <table class="w-full text-left border-collapse text-xs">
        <thead>
            <tr class="bg-stone-900 text-stone-100">
                <th class="p-3">When</th>
                <th class="p-3">User</th>
                <th class="p-3">Action</th>
                <th class="p-3">Table</th>
                <th class="p-3">Record</th>
                <th class="p-3">IP</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
            <?php if (empty($logs)): ?>
            <tr><td colspan="6" class="p-6 text-center text-stone-400 italic">No audit entries found.</td></tr>
            <?php endif; ?>
            <?php foreach ($logs as $log):
                $action_color = match($log['action']) {
                    'create' => 'text-emerald-700 bg-emerald-50',
                    'delete' => 'text-red-700 bg-red-50',
                    default  => 'text-amber-700 bg-amber-50',
                };
            ?>
            <tr class="hover:bg-stone-50">
                <td class="p-3 font-mono text-stone-500 whitespace-nowrap">
                    <?= date('M d, g:i A', strtotime($log['created_at'])) ?>
                </td>
                <td class="p-3 font-bold text-stone-800"><?= htmlspecialchars($log['user_name']) ?></td>
                <td class="p-3">
                    <span class="px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-wider <?= $action_color ?>">
                        <?= $log['action'] ?>
                    </span>
                </td>
                <td class="p-3 font-mono text-stone-600"><?= htmlspecialchars($log['table_name']) ?></td>
                <td class="p-3 text-stone-600 max-w-xs truncate"><?= htmlspecialchars($log['record_label']) ?></td>
                <td class="p-3 font-mono text-stone-400 text-[10px]"><?= htmlspecialchars($log['ip_address'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div class="flex justify-center gap-2 mt-6 text-xs font-mono">
    <?php for ($p = 1; $p <= $pages; $p++):
        $q = http_build_query(array_merge($_GET, ['page' => $p, 'view' => 'audit_log']));
    ?>
    <a href="?<?= $q ?>"
       class="px-3 py-1.5 rounded border <?= $p === $page ? 'bg-stone-900 text-white border-stone-900' : 'hover:bg-stone-100 text-stone-600' ?>">
       <?= $p ?>
    </a>
    <?php endfor; ?>
</div>
<?php endif; ?>
