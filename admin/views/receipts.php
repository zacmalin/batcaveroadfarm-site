<?php if ($current_role !== 'administrator'): ?>
    <p class="p-6 text-red-700 font-mono text-xs">Access denied.</p>
<?php return; endif; ?>

<?php
// ── QUERY PARAMS ──────────────────────────────────────────────────────────────
// Fix 4: ENT_QUOTES on all reflected GET values
$search_q  = trim($_GET['search_q']  ?? '');
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 50;
$offset    = ($page - 1) * $per_page;

// ── BUILD QUERY ───────────────────────────────────────────────────────────────
$where  = [];
$params = [];

if (!empty($search_q)) {
    // Fix 3 + 9: JSON_SEARCH for item names, plus receipt# and payment method
    // JSON_SEARCH returns a path if the value exists anywhere in the JSON array
    $where[]  = "(receipt_number LIKE ? OR payment_method LIKE ? OR JSON_SEARCH(cart_json, 'all', ?, NULL, '$[*].name') IS NOT NULL)";
    $params[] = "%{$search_q}%";
    $params[] = "%{$search_q}%";
    $params[] = "%{$search_q}%";
}
if (!empty($date_from)) {
    $where[]  = "DATE(created_at) >= ?";
    $params[] = $date_from;
}
if (!empty($date_to)) {
    $where[]  = "DATE(created_at) <= ?";
    $params[] = $date_to;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── FETCH FILTERED TOTALS (Fix 6: summary bar) ───────────────────────────────
$summary = ['filtered_rev' => 0, 'filtered_count' => 0, 'total_count' => 0];
try {
    $sum_stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) AS filtered_rev, COUNT(*) AS filtered_count FROM pos_receipts {$where_sql}");
    $sum_stmt->execute($params);
    $summary = array_merge($summary, $sum_stmt->fetch(PDO::FETCH_ASSOC));

    // Total record count for pagination
    $cnt_stmt = $pdo->query("SELECT COUNT(*) FROM pos_receipts");
    $summary['total_count'] = (int)$cnt_stmt->fetchColumn();
} catch (Throwable $e) {
    error_log('[ERP] Receipts summary query failed: ' . $e->getMessage());
}

$total_pages = max(1, (int)ceil($summary['filtered_count'] / $per_page));
$page        = min($page, $total_pages); // clamp after we know total pages

// ── FETCH PAGE OF RECEIPTS ────────────────────────────────────────────────────
$logged_receipts = [];
try {
    $params_paged   = array_merge($params, [$per_page, $offset]);
    $receipt_stmt   = $pdo->prepare("SELECT * FROM pos_receipts {$where_sql} ORDER BY id DESC LIMIT ? OFFSET ?");
    // PDO needs explicit int binding for LIMIT/OFFSET
    $receipt_stmt->bindValue(count($params) + 1, $per_page, PDO::PARAM_INT);
    $receipt_stmt->bindValue(count($params) + 2, $offset,   PDO::PARAM_INT);
    foreach ($params as $i => $val) {
        $receipt_stmt->bindValue($i + 1, $val);
    }
    $receipt_stmt->execute();
    $logged_receipts = $receipt_stmt->fetchAll();
} catch (Throwable $e) {
    error_log('[ERP] Receipts query failed: ' . $e->getMessage());
}

// ── PAGINATION URL HELPER ─────────────────────────────────────────────────────
function receipts_page_url(int $p): string {
    $q = array_filter([
        'view'      => 'receipts',
        'search_q'  => $_GET['search_q']  ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to'   => $_GET['date_to']   ?? '',
        'page'      => $p > 1 ? $p : null,
    ]);
    return '/admin/admin.php?' . http_build_query($q);
}

$is_filtered = !empty($search_q) || !empty($date_from) || !empty($date_to);
?>

<h1 class="text-2xl font-serif font-black mb-6">Master Transaction Log Registry</h1>

<div class="bg-white border rounded p-5 shadow-xs space-y-4">

    <!-- ── FILTER BAR ── -->
    <form method="GET" action="/admin/admin.php" class="grid grid-cols-1 md:grid-cols-5 gap-3 font-sans text-xs items-end">
        <input type="hidden" name="view" value="receipts">

        <!-- Search -->
        <div class="md:col-span-2">
            <label class="block font-bold text-stone-500 uppercase tracking-wider mb-1 text-[10px]">Search</label>
            <input type="text" name="search_q"
                value="<?= htmlspecialchars($search_q, ENT_QUOTES) ?>"
                placeholder="Ticket #, Item Name, or Method..."
                class="w-full p-2 border bg-stone-50 rounded font-medium outline-none">
        </div>

        <!-- Fix 5: Date range -->
        <div>
            <label class="block font-bold text-stone-500 uppercase tracking-wider mb-1 text-[10px]">From</label>
            <input type="date" name="date_from"
                value="<?= htmlspecialchars($date_from, ENT_QUOTES) ?>"
                class="w-full p-2 border bg-stone-50 rounded font-mono outline-none">
        </div>
        <div>
            <label class="block font-bold text-stone-500 uppercase tracking-wider mb-1 text-[10px]">To</label>
            <input type="date" name="date_to"
                value="<?= htmlspecialchars($date_to, ENT_QUOTES) ?>"
                class="w-full p-2 border bg-stone-50 rounded font-mono outline-none">
        </div>

        <div class="flex gap-2">
            <button type="submit" class="flex-1 bg-stone-900 text-white font-mono font-bold uppercase tracking-wider py-2 rounded text-center text-xxs">Search</button>
            <?php if ($is_filtered): ?>
                <a href="/admin/admin.php?view=receipts" class="flex-1 border py-2 bg-stone-100 rounded font-bold text-stone-600 text-center uppercase tracking-wider font-mono text-xxs">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Fix 6: Summary bar -->
    <div class="grid grid-cols-3 gap-3 bg-stone-50 border rounded p-3 font-mono text-xs">
        <div>
            <span class="block text-[10px] uppercase tracking-wider text-stone-400 font-bold">
                <?= $is_filtered ? 'Filtered Revenue' : 'Total Revenue' ?>
            </span>
            <span class="text-lg font-black text-emerald-800">$<?= number_format($summary['filtered_rev'], 2) ?></span>
        </div>
        <div>
            <span class="block text-[10px] uppercase tracking-wider text-stone-400 font-bold">
                <?= $is_filtered ? 'Matched Transactions' : 'Total Transactions' ?>
            </span>
            <span class="text-lg font-black text-stone-900"><?= number_format($summary['filtered_count']) ?></span>
        </div>
        <div>
            <span class="block text-[10px] uppercase tracking-wider text-stone-400 font-bold">Showing</span>
            <span class="text-lg font-black text-stone-900">
                <?= number_format(min($per_page, count($logged_receipts))) ?>
                <span class="text-xs font-medium text-stone-400">of <?= number_format($summary['filtered_count']) ?></span>
            </span>
        </div>
    </div>

    <!-- ── RECEIPTS TABLE ── -->
    <div class="overflow-x-auto border rounded">
        <table class="w-full text-left border-collapse text-xs font-sans font-medium">
            <thead>
                <tr class="bg-stone-900 text-stone-100 uppercase tracking-widest text-[9px] font-bold">
                    <th class="p-3">Receipt #</th>
                    <th class="p-3">Timestamp</th>
                    <th class="p-3">Items</th>
                    <th class="p-3">Markdown</th>
                    <th class="p-3">Total</th>
                    <th class="p-3">Tender</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                <?php if (empty($logged_receipts)): ?>
                    <tr>
                        <td colspan="6" class="p-6 text-center italic text-stone-400 bg-stone-50/40">
                            <?= $is_filtered ? 'No receipts match your filters.' : 'No receipts logged yet.' ?>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($logged_receipts as $rc):
                    // Fix 1+2: Build plain-text breakdown, escape only at output
                    $items_arr  = json_decode($rc['cart_json'], true) ?: [];
                    $breakdown_plain = [];
                    foreach ($items_arr as $it) {
                        $breakdown_plain[] = (float)$it['qty'] . 'x ' . ($it['name'] ?? '?');
                    }
                    $breakdown_text = implode(', ', $breakdown_plain);
                ?>
                <tr class="hover:bg-stone-50/50">
                    <td class="p-3 font-mono font-bold text-stone-900"><?= htmlspecialchars($rc['receipt_number'], ENT_QUOTES) ?></td>
                    <td class="p-3 font-mono text-stone-500 text-[10px] whitespace-nowrap"><?= date('M d, Y H:i:s', strtotime($rc['created_at'])) ?></td>
                    <td class="p-3 text-stone-700 font-sans text-xxs leading-tight max-w-xs">
                        <!-- Fix 1+2: plain text in array, escape both the visible text and title attribute -->
                        <div class="truncate select-all"
                            title="<?= htmlspecialchars($breakdown_text, ENT_QUOTES) ?>">
                            <?= htmlspecialchars($breakdown_text) ?>
                        </div>
                    </td>
                    <!-- Fix 7: hide discount cell when zero -->
                    <td class="p-3 font-mono text-amber-700">
                        <?= (float)$rc['discount'] > 0 ? '-$' . number_format($rc['discount'], 2) : '<span class="text-stone-300">—</span>' ?>
                    </td>
                    <td class="p-3 font-mono font-black text-stone-900">$<?= number_format($rc['total'], 2) ?></td>
                    <td class="p-3">
                        <?php
                        $method_colors = [
                            'CASH'    => 'bg-stone-100 text-stone-700 border-stone-300',
                            'PAYPAL'  => 'bg-blue-50 text-blue-700 border-blue-200',
                            'VENMO'   => 'bg-sky-50 text-sky-700 border-sky-200',
                            'CASHAPP' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                        ];
                        $method = strtoupper($rc['payment_method']);
                        $cls    = $method_colors[$method] ?? 'bg-stone-100 text-stone-700 border-stone-300';
                        ?>
                        <span class="px-2 py-0.5 rounded text-[10px] uppercase font-mono font-bold border <?= $cls ?>">
                            <?= htmlspecialchars($method, ENT_QUOTES) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Fix 8: Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="flex items-center justify-between pt-2 font-mono text-xs">
            <span class="text-stone-400">
                Page <?= $page ?> of <?= $total_pages ?>
                &nbsp;&bull;&nbsp; <?= number_format($summary['filtered_count']) ?> records
            </span>
            <div class="flex gap-1">
                <?php if ($page > 1): ?>
                    <a href="<?= receipts_page_url(1) ?>"          class="px-2 py-1 border rounded bg-stone-50 hover:bg-stone-200 font-bold">&laquo;</a>
                    <a href="<?= receipts_page_url($page - 1) ?>"  class="px-2 py-1 border rounded bg-stone-50 hover:bg-stone-200 font-bold">&lsaquo; Prev</a>
                <?php endif; ?>

                <?php
                // Show a sliding window of up to 5 page numbers
                $window_start = max(1, min($page - 2, $total_pages - 4));
                $window_end   = min($total_pages, $window_start + 4);
                for ($p = $window_start; $p <= $window_end; $p++):
                ?>
                    <a href="<?= receipts_page_url($p) ?>"
                        class="px-2 py-1 border rounded font-bold <?= $p === $page ? 'bg-stone-900 text-white border-stone-900' : 'bg-stone-50 hover:bg-stone-200' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="<?= receipts_page_url($page + 1) ?>"    class="px-2 py-1 border rounded bg-stone-50 hover:bg-stone-200 font-bold">Next &rsaquo;</a>
                    <a href="<?= receipts_page_url($total_pages) ?>" class="px-2 py-1 border rounded bg-stone-50 hover:bg-stone-200 font-bold">&raquo;</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

</div>
