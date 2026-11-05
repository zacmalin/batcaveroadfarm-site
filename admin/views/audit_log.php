<?php
// admin/views/audit_log.php — Settings › Audit Log (administrators only).
// Farm OS Phase 0 (C-06, Nov 5, 2026): reads audit_events (UTC, before/after values), shown in the farm's
// time zone. Falls back to the old audit_log table until migration 0003 has run.
if ($current_role !== 'administrator') {
    echo "<p class='p-6 text-red-700 font-mono text-xs'>Access denied.</p>";
    return;
}

$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset   = ($page - 1) * $per_page;

$filter_table  = trim((string)($_GET['tbl']    ?? ''));
$filter_action = trim((string)($_GET['action'] ?? ''));
$filter_user   = trim((string)($_GET['user']   ?? ''));

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$logs = []; $total = 0; $source = 'audit_events';

// New table: audit_events
try {
    $where = []; $params = [];
    if ($filter_table  !== '') { $where[] = 'object_type = ?';    $params[] = $filter_table; }
    if ($filter_action !== '') { $where[] = 'action = ?';         $params[] = $filter_action; }
    if ($filter_user   !== '') { $where[] = 'actor_label LIKE ?'; $params[] = '%' . $filter_user . '%'; }
    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM audit_events $where_sql");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
    $stmt = $pdo->prepare("SELECT occurred_at, actor_type, actor_label, action, object_type, object_label, ip, before_json, after_json
                           FROM audit_events $where_sql ORDER BY occurred_at DESC, id DESC
                           LIMIT " . (int)$per_page . " OFFSET " . (int)$offset);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $when = new DateTime($r['occurred_at'], new DateTimeZone('UTC'));
        $when->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $r['when'] = $when->format('M j, g:i A');
        $logs[] = $r;
    }
} catch (Throwable $e) {
    // Before migration 0003: the old audit_log table
    $source = 'audit_log';
    try {
        $where = []; $params = [];
        if ($filter_table  !== '') { $where[] = 'table_name = ?';  $params[] = $filter_table; }
        if ($filter_action !== '') { $where[] = 'action = ?';      $params[] = $filter_action; }
        if ($filter_user   !== '') { $where[] = 'user_name LIKE ?'; $params[] = '%' . $filter_user . '%'; }
        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM audit_log $where_sql");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();
        $stmt = $pdo->prepare("SELECT * FROM audit_log $where_sql ORDER BY created_at DESC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $logs[] = ['when' => date('M j, g:i A', strtotime((string)$r['created_at'])), 'actor_type' => '', 'actor_label' => $r['user_name'],
                       'action' => $r['action'], 'object_type' => $r['table_name'], 'object_label' => $r['record_label'],
                       'ip' => $r['ip_address'] ?? '', 'before_json' => null, 'after_json' => null];
        }
    } catch (Throwable $e2) {
        error_log('[ERP] audit log query failed: ' . $e2->getMessage());
    }
}

$pages = max(1, (int)ceil($total / $per_page));
$actorIcon = ['agent' => '🤖 ', 'system' => '⚙️ ', 'anonymous' => '❔ ', 'customer' => '🧑 '];
?>

<div class="flex justify-between items-center mb-4">
    <div>
        <h1 class="text-2xl font-serif font-black text-stone-800">Audit Log</h1>
        <p class="text-xs text-stone-500 font-mono mt-0.5"><?= number_format($total) ?> entries · times in <?= $h(date_default_timezone_get()) ?><?= $source === 'audit_log' ? ' · old log (run migration 0003)' : '' ?></p>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="flex flex-wrap gap-2 mb-4 text-xs font-mono bg-white border rounded-xl p-3">
    <input type="hidden" name="view" value="audit_log">
    <input type="text" name="user" placeholder="Who" value="<?= $h($filter_user) ?>" class="border rounded-lg p-2 bg-stone-50 outline-none flex-1 min-w-[8rem]">
    <select name="action" class="border rounded-lg p-2 bg-stone-50 outline-none">
        <option value="">All actions</option>
        <?php foreach (['create','update','delete','login','failed','blocked'] as $a): ?>
        <option value="<?= $a ?>" <?= $filter_action === $a ? 'selected' : '' ?>><?= ucfirst($a) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="text" name="tbl" placeholder="What (e.g. bookings, site_info)" value="<?= $h($filter_table) ?>" class="border rounded-lg p-2 bg-stone-50 outline-none flex-1 min-w-[10rem]">
    <button type="submit" class="bg-stone-900 text-white px-4 min-h-10 md:min-h-8 rounded-lg hover:bg-stone-700 transition uppercase tracking-wider text-[10px] font-bold">Filter</button>
    <a href="?view=audit_log&amp;tbl=staff_login" class="inline-flex items-center border px-3 min-h-10 md:min-h-8 rounded-lg hover:bg-stone-100 uppercase tracking-wider text-[10px] font-bold text-stone-600">Staff logins</a>
    <a href="?view=audit_log&amp;tbl=site_info" class="inline-flex items-center border px-3 min-h-10 md:min-h-8 rounded-lg hover:bg-stone-100 uppercase tracking-wider text-[10px] font-bold text-stone-600">Site Info</a>
    <a href="?view=audit_log" class="inline-flex items-center border px-3 min-h-10 md:min-h-8 rounded-lg hover:bg-stone-100 uppercase tracking-wider text-[10px] font-bold text-stone-600">Clear</a>
</form>

<div class="bg-white border rounded-xl shadow-xs overflow-hidden">
    <table class="ui-cards w-full text-left border-collapse text-xs">
        <thead>
            <tr class="bg-stone-900 text-stone-100">
                <th class="p-3">When</th>
                <th class="p-3">Who</th>
                <th class="p-3">Action</th>
                <th class="p-3">What</th>
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
                    'login'  => 'text-sky-700 bg-sky-50',
                    'failed', 'blocked' => 'text-red-800 bg-red-100',
                    default  => 'text-amber-700 bg-amber-50',
                };
                $before = $log['before_json'] ? (json_decode($log['before_json'], true) ?: []) : [];
                $after  = $log['after_json']  ? (json_decode($log['after_json'], true)  ?: []) : [];
            ?>
            <tr class="hover:bg-stone-50 align-top">
                <td class="p-3 font-mono text-stone-500 whitespace-nowrap"><?= $h($log['when']) ?></td>
                <td class="p-3 font-bold text-stone-800" data-cell="title"><?= $actorIcon[$log['actor_type']] ?? '' ?><?= $h($log['actor_label']) ?></td>
                <td class="p-3">
                    <span class="px-2 py-0.5 rounded text-[9px] font-black uppercase tracking-wider <?= $action_color ?>"><?= $h($log['action']) ?></span>
                </td>
                <td class="p-3 font-mono text-stone-600"><?= $h($log['object_type']) ?></td>
                <td class="p-3 text-stone-600 md:max-w-xs" data-cell="full">
                    <div class="md:truncate"><?= $h($log['object_label']) ?></div>
                    <?php foreach ($after as $k => $v): ?>
                    <div class="mt-1 text-[11px] text-stone-500 break-words">
                        <span class="line-through text-red-700/70"><?= $h($before[$k] ?? '') ?: '(empty)' ?></span>
                        → <span class="text-emerald-800"><?= $h($v) ?: '(empty)' ?></span>
                    </div>
                    <?php endforeach; ?>
                </td>
                <td class="p-3 font-mono text-stone-400 text-[10px]"><?= $h($log['ip'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<?php if ($pages > 1):
    $win = array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], fn($p) => $p >= 1 && $p <= $pages));
    sort($win); ?>
<div class="flex flex-wrap justify-center gap-2 mt-6 text-xs font-mono">
    <?php $prev = 0; foreach ($win as $p):
        if ($prev && $p > $prev + 1): ?><span class="px-2 py-1.5 text-stone-400">…</span><?php endif;
        $q = http_build_query(array_merge($_GET, ['page' => $p, 'view' => 'audit_log'])); ?>
    <a href="?<?= $h($q) ?>" class="px-3 py-1.5 rounded border <?= $p === $page ? 'bg-stone-900 text-white border-stone-900' : 'hover:bg-stone-100 text-stone-600' ?>"><?= $p ?></a>
    <?php $prev = $p; endforeach; ?>
</div>
<?php endif; ?>
