<?php
// views/sales_analytics.php — POS Sales Analytics Dashboard

if (!in_array($current_role, ['administrator', 'manager'])) {
    echo "<p class='p-6 text-red-700 font-mono text-xs'>Access denied.</p>"; return;
}

// ── PERIOD FILTER ─────────────────────────────────────────────────────────────
$period   = $_GET['period'] ?? '30';
$date_from = match($period) {
    'today'  => date('Y-m-d'),
    '7'      => date('Y-m-d', strtotime('-6 days')),
    '30'     => date('Y-m-d', strtotime('-29 days')),
    'month'  => date('Y-m-01'),
    'year'   => date('Y-01-01'),
    'custom' => $_GET['from'] ?? date('Y-m-d', strtotime('-29 days')),
    default  => date('Y-m-d', strtotime('-29 days')),
};
$date_to = ($period === 'custom' && !empty($_GET['to'])) ? $_GET['to'] : date('Y-m-d');

$from_ts = $date_from . ' 00:00:00';
$to_ts   = $date_to   . ' 23:59:59';

// ── QUERIES ───────────────────────────────────────────────────────────────────
try {

// Summary stats
$summary = $pdo->prepare("
    SELECT
        COUNT(*)                                    AS txn_count,
        COALESCE(SUM(total),     0)                 AS revenue,
        COALESCE(AVG(total),     0)                 AS avg_order,
        COALESCE(MAX(total),     0)                 AS max_order,
        COALESCE(SUM(discount),  0)                 AS total_discounts,
        COALESCE(SUM(tax),       0)                 AS total_tax,
        SUM(CASE WHEN tax_exempt  = 1 THEN 1 ELSE 0 END) AS exempt_count,
        SUM(CASE WHEN was_printed = 1 THEN 1 ELSE 0 END) AS printed_count
    FROM pos_receipts
    WHERE created_at BETWEEN ? AND ?
");
$summary->execute([$from_ts, $to_ts]);
$stats = $summary->fetch(PDO::FETCH_ASSOC);

// Daily revenue — for the chart
$daily = $pdo->prepare("
    SELECT DATE(created_at) AS day,
           COUNT(*)         AS cnt,
           SUM(total)       AS rev
    FROM   pos_receipts
    WHERE  created_at BETWEEN ? AND ?
    GROUP  BY DATE(created_at)
    ORDER  BY day ASC
");
$daily->execute([$from_ts, $to_ts]);
$daily_rows = $daily->fetchAll(PDO::FETCH_ASSOC);

// Payment method breakdown
$methods = $pdo->prepare("
    SELECT payment_method,
           COUNT(*)   AS cnt,
           SUM(total) AS rev
    FROM   pos_receipts
    WHERE  created_at BETWEEN ? AND ?
    GROUP  BY payment_method
    ORDER  BY rev DESC
");
$methods->execute([$from_ts, $to_ts]);
$method_rows = $methods->fetchAll(PDO::FETCH_ASSOC);

// Top items by revenue
$top_items = $pdo->prepare("
    SELECT i.name,
           SUM(sh.quantity_sold)               AS qty,
           ROUND(SUM(sh.quantity_sold * i.price), 2) AS rev,
           i.category
    FROM   sales_history sh
    JOIN   inventory i ON sh.inventory_id = i.id
    WHERE  sh.sold_at BETWEEN ? AND ?
    GROUP  BY i.id, i.name, i.category
    ORDER  BY rev DESC
    LIMIT  15
");
$top_items->execute([$from_ts, $to_ts]);
$item_rows = $top_items->fetchAll(PDO::FETCH_ASSOC);

// Revenue by category
$by_cat = $pdo->prepare("
    SELECT i.category,
           SUM(sh.quantity_sold)               AS qty,
           ROUND(SUM(sh.quantity_sold * i.price), 2) AS rev
    FROM   sales_history sh
    JOIN   inventory i ON sh.inventory_id = i.id
    WHERE  sh.sold_at BETWEEN ? AND ?
    GROUP  BY i.category
    ORDER  BY rev DESC
");
$by_cat->execute([$from_ts, $to_ts]);
$cat_rows = $by_cat->fetchAll(PDO::FETCH_ASSOC);

// Hourly pattern
$hourly = $pdo->prepare("
    SELECT HOUR(created_at) AS hr,
           COUNT(*)         AS cnt,
           SUM(total)       AS rev
    FROM   pos_receipts
    WHERE  created_at BETWEEN ? AND ?
    GROUP  BY HOUR(created_at)
    ORDER  BY hr ASC
");
$hourly->execute([$from_ts, $to_ts]);
$hourly_rows = $hourly->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    echo "<div class='p-4 bg-red-50 border border-red-200 text-red-700 font-mono text-xs rounded'>Query error: " . htmlspecialchars($e->getMessage()) . "</div>";
    return;
}

// ── BOOKING REVENUE QUERIES ───────────────────────────────────────────────────
$bk_stats    = ['paid_count' => 0, 'revenue' => 0, 'avg_party' => 0, 'pending_count' => 0];
$bk_by_type  = [];
$bk_daily    = [];
try {
    $bst = $pdo->prepare("
        SELECT COUNT(*)                          AS paid_count,
               COALESCE(SUM(amount_total), 0)   AS revenue,
               COALESCE(AVG(party_size),   0)   AS avg_party
        FROM bookings
        WHERE payment_status = 'paid'
          AND booking_date BETWEEN ? AND ?
    ");
    $bst->execute([$date_from, $date_to]);
    $bk_stats = array_merge($bk_stats, $bst->fetch(PDO::FETCH_ASSOC) ?: []);

    $bst2 = $pdo->prepare("SELECT COUNT(*) AS pending_count FROM bookings WHERE status='pending' AND booking_date >= CURDATE()");
    $bst2->execute();
    $bk_stats['pending_count'] = (int)($bst2->fetchColumn() ?: 0);

    $bbt = $pdo->prepare("
        SELECT t.label,
               COUNT(*)                        AS cnt,
               COALESCE(SUM(b.amount_total),0) AS rev,
               COALESCE(AVG(b.party_size),  0) AS avg_party
        FROM bookings b
        JOIN booking_types t ON t.id = b.type_id
        WHERE b.payment_status = 'paid'
          AND b.booking_date BETWEEN ? AND ?
        GROUP BY t.id, t.label
        ORDER BY rev DESC
    ");
    $bbt->execute([$date_from, $date_to]);
    $bk_by_type = $bbt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $bdt = $pdo->prepare("
        SELECT booking_date AS day, COUNT(*) AS cnt, SUM(amount_total) AS rev
        FROM bookings
        WHERE payment_status = 'paid' AND booking_date BETWEEN ? AND ?
        GROUP BY booking_date ORDER BY booking_date ASC
    ");
    $bdt->execute([$date_from, $date_to]);
    $bk_daily = array_column($bdt->fetchAll(PDO::FETCH_ASSOC) ?: [], null, 'day');
} catch (Throwable $e) { /* bookings table may be absent */ }

// ── CHART DATA ────────────────────────────────────────────────────────────────
// Fill in missing days with 0
$chart_labels = [];
$chart_rev    = [];
$chart_cnt    = [];
$daily_by_day = array_column($daily_rows, null, 'day');
$d = new DateTime($date_from);
$end = new DateTime($date_to);
while ($d <= $end) {
    $key = $d->format('Y-m-d');
    $chart_labels[] = $d->format('M j');
    $chart_rev[]    = isset($daily_by_day[$key]) ? round((float)$daily_by_day[$key]['rev'], 2) : 0;
    $chart_cnt[]    = isset($daily_by_day[$key]) ? (int)$daily_by_day[$key]['cnt'] : 0;
    $d->modify('+1 day');
}

// Hourly: fill all 24 hours
$hourly_by_hr = array_column($hourly_rows, null, 'hr');
$hour_labels  = [];
$hour_rev     = [];
for ($h = 6; $h <= 21; $h++) { // 6am–9pm typical farm stand hours
    $hour_labels[] = date('g A', mktime($h, 0, 0));
    $hour_rev[]    = isset($hourly_by_hr[$h]) ? round((float)$hourly_by_hr[$h]['rev'], 2) : 0;
}

$json_chart_labels  = json_encode($chart_labels);
$json_chart_rev     = json_encode($chart_rev);
$json_chart_cnt     = json_encode($chart_cnt);
$json_hour_labels   = json_encode($hour_labels);
$json_hour_rev      = json_encode($hour_rev);

// Booking daily revenue — aligned to same date range
$chart_bk_rev = [];
$d = new DateTime($date_from); $end = new DateTime($date_to);
while ($d <= $end) {
    $key = $d->format('Y-m-d');
    $chart_bk_rev[] = isset($bk_daily[$key]) ? round((float)$bk_daily[$key]['rev'], 2) : 0;
    $d->modify('+1 day');
}
$json_chart_bk_rev = json_encode($chart_bk_rev);

$total_combined = (float)($stats['revenue'] ?? 0) + (float)($bk_stats['revenue'] ?? 0);

// ── CSV EXPORT ────────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sales-' . $date_from . '-to-' . $date_to . '.csv"');
    $exp = $pdo->prepare("SELECT receipt_number, subtotal, discount, tax, total, payment_method, tax_exempt, was_printed, created_at FROM pos_receipts WHERE created_at BETWEEN ? AND ? ORDER BY created_at DESC");
    $exp->execute([$from_ts, $to_ts]);
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Receipt', 'Subtotal', 'Discount', 'Tax', 'Total', 'Method', 'Tax Exempt', 'Printed', 'Date']);
    while ($row = $exp->fetch(PDO::FETCH_ASSOC)) fputcsv($out, $row);
    fclose($out); exit;
}

$fmt = fn($n) => '$' . number_format((float)$n, 2);
?>

<!-- ── HEADER ── -->
<div class="flex flex-wrap justify-between items-start gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-serif font-black text-stone-800">Sales Analytics</h1>
        <p class="text-xs text-stone-400 font-mono mt-0.5">
            <?= date('M j, Y', strtotime($date_from)) ?> — <?= date('M j, Y', strtotime($date_to)) ?>
        </p>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
        <!-- Period pills -->
        <?php foreach (['today'=>'Today','7'=>'7 Days','30'=>'30 Days','month'=>'This Month','year'=>'This Year'] as $k => $l): ?>
        <a href="?view=sales_analytics&period=<?= $k ?>"
            class="px-3 py-1.5 rounded text-[10px] font-bold uppercase tracking-wider font-mono border transition
                   <?= $period === $k ? 'bg-stone-900 text-white border-stone-900' : 'bg-white text-stone-600 border-stone-300 hover:bg-stone-100' ?>">
            <?= $l ?>
        </a>
        <?php endforeach; ?>

        <!-- Custom range -->
        <form method="GET" class="flex items-center gap-1">
            <input type="hidden" name="view" value="sales_analytics">
            <input type="hidden" name="period" value="custom">
            <input type="date" name="from" value="<?= htmlspecialchars($date_from) ?>"
                class="border rounded px-2 py-1 text-[10px] font-mono bg-white outline-none">
            <span class="text-stone-400 text-xs">—</span>
            <input type="date" name="to" value="<?= htmlspecialchars($date_to) ?>"
                class="border rounded px-2 py-1 text-[10px] font-mono bg-white outline-none">
            <button type="submit" class="bg-stone-800 text-white px-3 py-1.5 rounded text-[10px] font-bold uppercase tracking-wider">Go</button>
        </form>

        <!-- Export -->
        <a href="?view=sales_analytics&period=<?= $period ?>&from=<?= $date_from ?>&to=<?= $date_to ?>&export=1"
            class="bg-emerald-700 hover:bg-emerald-800 text-white px-3 py-1.5 rounded text-[10px] font-bold uppercase tracking-wider font-mono transition">
            ↓ CSV
        </a>
    </div>
</div>

<!-- ── STAT CARDS ── -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-3">
    <div class="bg-stone-900 text-stone-100 border rounded-lg p-4 shadow-xs lg:col-span-2">
        <div class="text-lg mb-0.5">💰</div>
        <div class="text-[10px] font-bold uppercase tracking-widest text-stone-400 font-mono">Total Revenue (POS + Bookings)</div>
        <div class="text-2xl font-black text-emerald-400 font-mono mt-1"><?= $fmt($total_combined) ?></div>
        <div class="text-[10px] font-mono text-stone-500 mt-1">POS <?= $fmt($stats['revenue']) ?> &bull; Bookings <?= $fmt($bk_stats['revenue']) ?></div>
    </div>
    <?php
    $cards = [
        ['POS Transactions', number_format((int)$stats['txn_count']), 'text-stone-800', '🧾'],
        ['Avg POS Order',    $fmt($stats['avg_order']),               'text-stone-800', '📊'],
    ];
    foreach ($cards as [$label, $value, $cls, $icon]):
    ?>
    <div class="bg-white border rounded-lg p-4 shadow-xs">
        <div class="text-lg mb-0.5"><?= $icon ?></div>
        <div class="text-[10px] font-bold uppercase tracking-widest text-stone-400 font-mono"><?= $label ?></div>
        <div class="text-xl font-black <?= $cls ?> font-mono mt-1"><?= $value ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── BOOKING STAT CARDS ── -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <?php
    $bk_cards = [
        ['Paid Bookings',     number_format((int)$bk_stats['paid_count']),  'text-emerald-700', '🎟'],
        ['Booking Revenue',   $fmt($bk_stats['revenue']),                   'text-emerald-700', '💳'],
        ['Avg Party Size',    number_format((float)$bk_stats['avg_party'], 1) . ' guests', 'text-stone-700', '👥'],
        ['Pending Requests',  (int)$bk_stats['pending_count'],              (int)$bk_stats['pending_count'] > 0 ? 'text-amber-700' : 'text-stone-400', '⏳'],
    ];
    foreach ($bk_cards as [$label, $value, $cls, $icon]):
    ?>
    <div class="bg-white border rounded-lg p-4 shadow-xs">
        <div class="text-lg mb-0.5"><?= $icon ?></div>
        <div class="text-[10px] font-bold uppercase tracking-widest text-stone-400 font-mono"><?= $label ?></div>
        <div class="text-xl font-black <?= $cls ?> font-mono mt-1"><?= $value ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── SECONDARY CARDS ── -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6 text-xs">
    <div class="bg-white border rounded p-3">
        <div class="text-stone-400 font-mono uppercase tracking-wider text-[9px]">Largest Sale</div>
        <div class="font-black text-stone-800 mt-0.5"><?= $fmt($stats['max_order']) ?></div>
    </div>
    <div class="bg-white border rounded p-3">
        <div class="text-stone-400 font-mono uppercase tracking-wider text-[9px]">Total Discounts</div>
        <div class="font-black text-stone-800 mt-0.5"><?= $fmt($stats['total_discounts']) ?></div>
    </div>
    <div class="bg-white border rounded p-3">
        <div class="text-stone-400 font-mono uppercase tracking-wider text-[9px]">Tax-Exempt Sales</div>
        <div class="font-black text-stone-800 mt-0.5"><?= (int)$stats['exempt_count'] ?> transactions</div>
    </div>
    <div class="bg-white border rounded p-3">
        <div class="text-stone-400 font-mono uppercase tracking-wider text-[9px]">Receipts Printed</div>
        <div class="font-black text-stone-800 mt-0.5"><?= (int)$stats['printed_count'] ?> / <?= (int)$stats['txn_count'] ?></div>
    </div>
</div>

<!-- ── CHARTS ── -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

    <!-- Daily revenue chart — 2/3 width -->
    <div class="lg:col-span-2 bg-white border rounded-lg p-5 shadow-xs">
        <h2 class="font-bold text-stone-800 text-xs uppercase tracking-widest font-mono mb-4">Daily Revenue</h2>
        <?php if (empty($chart_labels)): ?>
        <p class="text-stone-400 italic text-center py-12 text-xs">No transactions in this period.</p>
        <?php else: ?>
        <canvas id="dailyChart" height="120"></canvas>
        <?php endif; ?>
    </div>

    <!-- Payment methods — 1/3 width -->
    <div class="bg-white border rounded-lg p-5 shadow-xs">
        <h2 class="font-bold text-stone-800 text-xs uppercase tracking-widest font-mono mb-4">Payment Methods</h2>
        <?php if (empty($method_rows)): ?>
        <p class="text-stone-400 italic text-center py-8 text-xs">No data.</p>
        <?php else: ?>
        <?php
        $max_rev = max(array_column($method_rows, 'rev')) ?: 1;
        $method_colors = ['CASH'=>'bg-stone-700','PAYPAL'=>'bg-blue-700','VENMO'=>'bg-sky-500','CASHAPP'=>'bg-emerald-600','CHECK'=>'bg-amber-700'];
        foreach ($method_rows as $m):
            $pct = round(($m['rev'] / $max_rev) * 100);
        ?>
        <div class="mb-3">
            <div class="flex justify-between items-baseline mb-1">
                <span class="text-xs font-bold text-stone-700"><?= htmlspecialchars($m['payment_method']) ?></span>
                <span class="text-xs font-mono text-stone-500"><?= $fmt($m['rev']) ?> &bull; <?= $m['cnt'] ?> sales</span>
            </div>
            <div class="h-2 bg-stone-100 rounded overflow-hidden">
                <div class="h-full rounded <?= $method_colors[$m['payment_method']] ?? 'bg-stone-600' ?>"
                     style="width:<?= $pct ?>%"></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ── HOURLY CHART ── -->
<div class="bg-white border rounded-lg p-5 shadow-xs mb-6">
    <h2 class="font-bold text-stone-800 text-xs uppercase tracking-widest font-mono mb-4">Revenue by Hour of Day</h2>
    <?php if (array_sum($hour_rev) > 0): ?>
    <canvas id="hourlyChart" height="60"></canvas>
    <?php else: ?>
    <p class="text-stone-400 italic text-center py-6 text-xs">No data for this period.</p>
    <?php endif; ?>
</div>

<!-- ── TABLES ── -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

    <!-- Top items -->
    <div class="bg-white border rounded-lg shadow-xs overflow-hidden">
        <div class="bg-stone-900 text-stone-100 px-5 py-3">
            <h2 class="font-bold uppercase tracking-widest text-[10px]">Top Items by Revenue</h2>
        </div>
        <?php if (empty($item_rows)): ?>
        <p class="p-6 text-stone-400 italic text-xs">No item sales data. Sales History is populated when POS transactions are saved.</p>
        <?php else: ?>
        <table class="w-full text-xs border-collapse">
            <thead>
                <tr class="bg-stone-50 text-stone-400 text-[9px] uppercase tracking-widest font-bold border-b">
                    <th class="px-4 py-2 text-left">Item</th>
                    <th class="px-4 py-2 text-left">Category</th>
                    <th class="px-4 py-2 text-right">Qty Sold</th>
                    <th class="px-4 py-2 text-right">Revenue</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
            <?php foreach ($item_rows as $i => $row): ?>
                <tr class="hover:bg-stone-50">
                    <td class="px-4 py-2 font-bold text-stone-800">
                        <span class="text-[9px] text-stone-400 mr-1">#<?= $i+1 ?></span>
                        <?= htmlspecialchars($row['name']) ?>
                    </td>
                    <td class="px-4 py-2 text-stone-500"><?= htmlspecialchars($row['category'] ?? '—') ?></td>
                    <td class="px-4 py-2 text-right font-mono text-stone-700"><?= number_format((float)$row['qty'], 1) ?></td>
                    <td class="px-4 py-2 text-right font-mono font-bold text-emerald-700"><?= $fmt($row['rev']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Revenue by category -->
    <div class="bg-white border rounded-lg shadow-xs overflow-hidden">
        <div class="bg-stone-900 text-stone-100 px-5 py-3">
            <h2 class="font-bold uppercase tracking-widest text-[10px]">Revenue by Category</h2>
        </div>
        <?php if (empty($cat_rows)): ?>
        <p class="p-6 text-stone-400 italic text-xs">No category data.</p>
        <?php else: ?>
        <?php
        $cat_total = array_sum(array_column($cat_rows, 'rev')) ?: 1;
        ?>
        <div class="p-5 space-y-3">
        <?php foreach ($cat_rows as $row):
            $pct = round(($row['rev'] / $cat_total) * 100);
        ?>
            <div>
                <div class="flex justify-between text-xs mb-1">
                    <span class="font-bold text-stone-700"><?= htmlspecialchars($row['category'] ?? 'Uncategorized') ?></span>
                    <span class="font-mono text-stone-500"><?= $fmt($row['rev']) ?> <span class="text-stone-400">(<?= $pct ?>%)</span></span>
                </div>
                <div class="h-2 bg-stone-100 rounded overflow-hidden">
                    <div class="h-full bg-stone-700 rounded" style="width:<?= $pct ?>%"></div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── BOOKING REVENUE BY TYPE ── -->
<?php if (!empty($bk_by_type)): ?>
<div class="bg-white border rounded-lg shadow-xs overflow-hidden mb-6">
    <div class="bg-emerald-900 text-stone-100 px-5 py-3 flex justify-between items-center">
        <h2 class="font-bold uppercase tracking-widest text-[10px]">Booking Revenue by Type</h2>
        <span class="text-[10px] font-mono text-emerald-300"><?= date('M j', strtotime($date_from)) ?> — <?= date('M j, Y', strtotime($date_to)) ?></span>
    </div>
    <table class="w-full text-xs border-collapse">
        <thead>
            <tr class="bg-stone-50 text-stone-400 text-[9px] uppercase tracking-widest font-bold border-b">
                <th class="px-4 py-2 text-left">Booking Type</th>
                <th class="px-4 py-2 text-right">Bookings</th>
                <th class="px-4 py-2 text-right">Avg Party</th>
                <th class="px-4 py-2 text-right">Revenue</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
        <?php
        $bk_type_total = array_sum(array_column($bk_by_type, 'rev')) ?: 1;
        foreach ($bk_by_type as $row):
            $pct = round(($row['rev'] / $bk_type_total) * 100);
        ?>
            <tr class="hover:bg-stone-50">
                <td class="px-4 py-3">
                    <div class="font-bold text-stone-800"><?= htmlspecialchars($row['label']) ?></div>
                    <div class="h-1.5 bg-stone-100 rounded overflow-hidden mt-1.5 w-32">
                        <div class="h-full bg-emerald-600 rounded" style="width:<?= $pct ?>%"></div>
                    </div>
                </td>
                <td class="px-4 py-3 text-right font-mono text-stone-700"><?= (int)$row['cnt'] ?></td>
                <td class="px-4 py-3 text-right font-mono text-stone-500"><?= number_format((float)$row['avg_party'], 1) ?></td>
                <td class="px-4 py-3 text-right font-mono font-black text-emerald-700"><?= $fmt($row['rev']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="border-t-2 border-stone-200 bg-stone-50">
            <tr>
                <td class="px-4 py-2 font-bold text-stone-700 text-[10px] uppercase">Total</td>
                <td class="px-4 py-2 text-right font-mono font-bold text-stone-700"><?= array_sum(array_column($bk_by_type, 'cnt')) ?></td>
                <td></td>
                <td class="px-4 py-2 text-right font-mono font-black text-emerald-700"><?= $fmt(array_sum(array_column($bk_by_type, 'rev'))) ?></td>
            </tr>
        </tfoot>
    </table>
</div>
<?php endif; ?>

<!-- Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = 'ui-monospace, monospace';
Chart.defaults.color = '#78716c';

<?php if (!empty($chart_labels)): ?>
// Daily revenue chart
new Chart(document.getElementById('dailyChart'), {
    type: 'bar',
    data: {
        labels: <?= $json_chart_labels ?>,
        datasets: [
            {
                label: 'POS Revenue',
                data:  <?= $json_chart_rev ?>,
                backgroundColor: 'rgba(28,25,23,0.8)',
                borderRadius: 3,
                yAxisID: 'y'
            },
            {
                label: 'Booking Revenue',
                data:  <?= $json_chart_bk_rev ?>,
                backgroundColor: 'rgba(5,150,105,0.75)',
                borderRadius: 3,
                yAxisID: 'y'
            },
            {
                label: 'POS Transactions',
                data:  <?= $json_chart_cnt ?>,
                type:  'line',
                borderColor: '#d97706',
                backgroundColor: 'transparent',
                borderWidth: 2,
                pointRadius: 3,
                pointBackgroundColor: '#d97706',
                tension: 0.3,
                yAxisID: 'y2'
            }
        ]
    },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } },
        scales: {
            y:  { position: 'left',  ticks: { callback: v => '$' + v.toFixed(0), font: { size: 10 } }, grid: { color: '#f5f5f4' } },
            y2: { position: 'right', ticks: { font: { size: 10 } }, grid: { display: false } },
            x:  { ticks: { font: { size: 10 }, maxRotation: 45 }, grid: { display: false } }
        }
    }
});
<?php endif; ?>

<?php if (array_sum($hour_rev) > 0): ?>
// Hourly chart
new Chart(document.getElementById('hourlyChart'), {
    type: 'bar',
    data: {
        labels: <?= $json_hour_labels ?>,
        datasets: [{
            label: 'Revenue',
            data:  <?= $json_hour_rev ?>,
            backgroundColor: '#292524',
            borderRadius: 3
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { ticks: { callback: v => '$' + v.toFixed(0), font: { size: 10 } }, grid: { color: '#f5f5f4' } },
            x: { ticks: { font: { size: 10 } }, grid: { display: false } }
        }
    }
});
<?php endif; ?>
</script>
