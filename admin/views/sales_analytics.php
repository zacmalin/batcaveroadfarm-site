<?php
// views/sales_analytics.php — POS Sales Analytics Dashboard

// Audit #3 (Nov 2026): the old hard-coded ['administrator','manager'] list named a role that doesn't
// exist, so a Shopkeep granted Sales Analytics in the Permissions Matrix was still refused.
// admin.php's view gate already applies the matrix; this is a second, matrix-based check.
if (!hasViewAccess($current_role, 'sales_analytics')) {
    echo "<p class='p-6 text-red-700 font-mono text-xs'>Access denied.</p>"; return;
}

// ── PERIOD FILTER ─────────────────────────────────────────────────────────────
// Nov 14, 2026: this tab now reads the register's own tables through includes/reports.php (business dates, refunds
// taken off on their own date, tax kept out, gift cards counted when spent), the same numbers as Reports.
// It used to read the old pos_receipts copy, which missed partial refunds, counted tax as revenue and put the
// hour-of-day chart 2 hours early (the database clock is Mountain time).
require_once dirname(__DIR__, 2) . '/includes/reports.php';
$today  = function_exists('bcrf_business_date') ? bcrf_business_date() : date('Y-m-d');
$isDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false;
$period = in_array($_GET['period'] ?? '30', ['today', '7', '30', 'month', 'year', 'custom'], true) ? ($_GET['period'] ?? '30') : '30';
$date_from = match($period) {
    'today'  => $today,
    '7'      => date('Y-m-d', strtotime($today . ' -6 days')),
    'month'  => substr($today, 0, 8) . '01',
    'year'   => substr($today, 0, 4) . '-01-01',
    'custom' => $isDate($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-d', strtotime($today . ' -29 days')),
    default  => date('Y-m-d', strtotime($today . ' -29 days')),
};
$date_to = ($period === 'custom' && $isDate($_GET['to'] ?? null)) ? $_GET['to'] : $today;
if ($date_from > $date_to) [$date_from, $date_to] = [$date_to, $date_from];
if ((strtotime($date_to) - strtotime($date_from)) / 86400 > 366) $date_from = date('Y-m-d', strtotime($date_to . ' -366 days'));

// ── QUERIES ───────────────────────────────────────────────────────────────────
try {
if (!bcrf_sales_ready($pdo) || !bcrf_refunds_ready($pdo)) throw new RuntimeException('Run migrations 0009 to 0011 in Settings › Database first.');

$days   = bcrf_rpt_days($pdo, $date_from, $date_to, false);
$tot    = bcrf_rpt_total($days);
$money  = bcrf_rpt_money_in($pdo, $date_from, $date_to);
[$pf, $pt] = bcrf_rpt_prev_range($date_from, $date_to);
$prev   = bcrf_rpt_money_in($pdo, $pf, $pt);
$detail = bcrf_rpt_register_detail($pdo, $date_from, $date_to, 15);

$st = $pdo->prepare("SELECT COALESCE(MAX(total_cents), 0) mx, COALESCE(SUM(tax_exempt), 0) ex FROM sales WHERE status <> 'voided' AND business_date BETWEEN ? AND ?");
$st->execute([$date_from, $date_to]);
$sx = $st->fetch(PDO::FETCH_ASSOC);
$reg = $money['sources']['register'];
$stats = [
    'txn_count'       => (int)$tot['sales_count'],
    'revenue'         => $reg['net'] / 100,   // register, after discounts and refunds, before tax, no gift cards
    'avg_order'       => $tot['sales_count'] ? ($tot['gross_sales'] - $tot['discounts']) / $tot['sales_count'] / 100 : 0,
    'max_order'       => (int)$sx['mx'] / 100,
    'total_discounts' => $tot['discounts'] / 100,
    'total_refunds'   => $tot['refunds'] / 100,
    'exempt_count'    => (int)$sx['ex'],
    'tax'             => $money['tax'] / 100,
];

// Daily chart: register vs everything paid online or at the office
$daily_rows = [];
foreach ($money['by_day'] as $d => $x) {
    $daily_rows[$d] = ['day' => $d, 'cnt' => (int)($days[$d]['sales_count'] ?? 0), 'rev' => $x['register'] / 100,
                       'other' => ($x['tickets'] + $x['passes'] + $x['events']) / 100];
}

// Payment methods (register only), after refunds paid back
$method_rows = [];
foreach (bcrf_tenders() + ['other_tender' => 'Gift card / other'] as $t => $label) {
    $c = (int)($tot[$t] ?? 0);
    if ($c) $method_rows[] = ['payment_method' => $t === 'other_tender' ? 'GIFT CARD / OTHER' : strtoupper($t), 'rev' => $c / 100, 'cnt' => null];
}
usort($method_rows, fn($a, $b) => $b['rev'] <=> $a['rev']);

$item_rows = array_map(fn($i) => ['name' => $i['name'], 'category' => $i['category'], 'qty' => $i['qty'], 'rev' => $i['cents'] / 100], $detail['items']);
$cat_rows = [];
foreach (bcrf_rpt_breakdown($pdo, $date_from, $date_to, 'category') as $c) {
    if ($c['label'] === 'Gift Cards') continue;   // money owed until spent, not a sale
    $cat_rows[] = ['category' => $c['label'], 'qty' => $c['qty'], 'rev' => $c['net'] / 100];
}
$hourly_rows = [];
foreach ($detail['hours'] as $hr => $x) $hourly_rows[] = ['hr' => $hr, 'cnt' => $x['count'], 'rev' => $x['cents'] / 100];

} catch (Throwable $e) {
    echo "<div class='p-4 bg-red-50 border border-red-200 text-red-700 font-mono text-xs rounded'>" . htmlspecialchars($e instanceof RuntimeException ? $e->getMessage() : 'Query error: ' . $e->getMessage()) . "</div>";
    return;
}

// ── BOOKINGS ON THE CALENDAR (by event date; the money is in "All money in" by the day it was paid) ──
$bk_stats    = ['paid_count' => 0, 'revenue' => 0, 'avg_party' => 0, 'pending_count' => 0];
$bk_by_type  = [];
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

    $bst2 = $pdo->prepare("SELECT COUNT(*) AS pending_count FROM bookings WHERE status='pending' AND booking_date >= ?");
    $bst2->execute([$today]);
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
} catch (Throwable $e) { /* bookings table may be absent */ }

// ── CHART DATA ────────────────────────────────────────────────────────────────
$chart_labels = []; $chart_rev = []; $chart_cnt = []; $chart_bk_rev = [];
foreach ($daily_rows as $d => $x) {
    $chart_labels[] = date('M j', strtotime($d));
    $chart_rev[]    = round($x['rev'], 2);
    $chart_cnt[]    = $x['cnt'];
    $chart_bk_rev[] = round($x['other'], 2);
}
$hourly_by_hr = array_column($hourly_rows, null, 'hr');
$hour_labels  = [];
$hour_rev     = [];
for ($h = 6; $h <= 21; $h++) { // 6am–9pm, farm time
    $hour_labels[] = date('g A', mktime($h, 0, 0));
    $hour_rev[]    = isset($hourly_by_hr[$h]) ? round((float)$hourly_by_hr[$h]['rev'], 2) : 0;
}
$json_chart_labels = json_encode($chart_labels);
$json_chart_rev    = json_encode($chart_rev);
$json_chart_cnt    = json_encode($chart_cnt);
$json_hour_labels  = json_encode($hour_labels);
$json_hour_rev     = json_encode($hour_rev);
$json_chart_bk_rev = json_encode($chart_bk_rev);

$total_combined = $money['net'] / 100;
$prev_combined  = $prev['net'] / 100;
$prev_label     = date('M j', strtotime($pf)) . ($pf !== $pt ? '–' . date('M j', strtotime($pt)) : '') . (substr($pf, 0, 4) !== substr($date_to, 0, 4) ? ', ' . substr($pf, 0, 4) : '');

// ── CSV EXPORT (one row per register sale, from the register's own tables) ───
if (isset($_GET['export'])) {
    $tenders = bcrf_tenders();
    $st = $pdo->prepare("SELECT s.id, s.sale_number, s.business_date, s.completed_at, s.status, s.subtotal_cents, s.discount_cents, s.tax_cents, s.total_cents, s.tax_exempt,
                                (SELECT COALESCE(SUM(r.total_cents), 0) FROM refunds r WHERE r.sale_id = s.id) refunded,
                                (SELECT GROUP_CONCAT(DISTINCT p.tender) FROM payments p WHERE p.sale_id = s.id) tenders
                         FROM sales s WHERE s.business_date BETWEEN ? AND ? ORDER BY s.completed_at DESC");
    $st->execute([$date_from, $date_to]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $rows[] = [$s['business_date'], bcrf_local_time($s['completed_at'], 'H:i'), $s['sale_number'], $s['status'],
                   bcrf_rpt_csv_money((int)$s['subtotal_cents']), bcrf_rpt_csv_money((int)$s['discount_cents']), bcrf_rpt_csv_money((int)$s['tax_cents']),
                   bcrf_rpt_csv_money((int)$s['total_cents']), bcrf_rpt_csv_money((int)$s['refunded']),
                   implode(' + ', array_map(fn($t) => $tenders[$t] ?? $t, array_filter(explode(',', (string)$s['tenders'])))), (int)$s['tax_exempt']];
    }
    bcrf_rpt_send_csv($pdo, 'register-sales-' . $date_from . '-to-' . $date_to . '.csv',
        ['business_date', 'time', 'sale_number', 'status', 'subtotal', 'discount', 'tax', 'total', 'refunded', 'paid_by', 'tax_exempt'], $rows, "Register sales $date_from to $date_to");
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
        <div class="text-[10px] font-bold uppercase tracking-widest text-stone-400 font-mono">All money in <span class="normal-case tracking-normal">(before tax, after refunds)</span></div>
        <div class="text-2xl font-black text-emerald-400 font-mono mt-1"><?= $fmt($total_combined) ?></div>
        <div class="text-[10px] font-mono text-stone-400 mt-1">
          <?php if ($prev_combined > 0): $chg = ($total_combined - $prev_combined) / $prev_combined * 100; ?>
            <?= $chg >= 0 ? '▲' : '▼' ?> <?= number_format(abs($chg), 0) ?>% vs <?= htmlspecialchars($prev_label) ?> (<?= $fmt($prev_combined) ?>)
          <?php else: ?>No money in for <?= htmlspecialchars($prev_label) ?> to compare<?php endif; ?>
        </div>
        <div class="text-[10px] font-mono text-stone-500 mt-1"><?php foreach ($money['sources'] as $src): if (!$src['count'] && !$src['net']) continue; ?><?= htmlspecialchars($src['label']) ?> <?= $fmt($src['net'] / 100) ?> &bull; <?php endforeach; ?>Tax <?= $fmt($stats['tax']) ?></div>
        <?php if ($money['gc_sold']): ?><div class="text-[10px] font-mono text-stone-500 mt-1">Gift cards sold <?= $fmt($money['gc_sold'] / 100) ?>: owed until spent, not counted</div><?php endif; ?>
    </div>
    <?php
    $cards = [
        ['Register sales', number_format((int)$stats['txn_count']), 'text-stone-800', '🧾'],
        ['Avg sale <span class="normal-case tracking-normal">(before tax)</span>', $fmt($stats['avg_order']), 'text-stone-800', '📊'],
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
        ['Paid bookings dated in period', number_format((int)$bk_stats['paid_count']),  'text-emerald-700', '🎟'],
        ['Their booking value', $fmt($bk_stats['revenue']),                 'text-emerald-700', '💳'],
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
        <div class="text-stone-400 font-mono uppercase tracking-wider text-[9px]">Refunds (register)</div>
        <div class="font-black text-stone-800 mt-0.5"><?= $fmt($stats['total_refunds']) ?></div>
    </div>
</div>

<!-- ── CHARTS ── -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

    <!-- Daily revenue chart — 2/3 width -->
    <div class="lg:col-span-2 bg-white border rounded-lg p-5 shadow-xs">
        <h2 class="font-bold text-stone-800 text-xs uppercase tracking-widest font-mono mb-4">Money in by day <span class="normal-case tracking-normal text-stone-400">(before tax)</span></h2>
        <?php if (empty($chart_labels)): ?>
        <p class="text-stone-400 italic text-center py-12 text-xs">No transactions in this period.</p>
        <?php else: ?>
        <canvas id="dailyChart" height="120"></canvas>
        <?php endif; ?>
    </div>

    <!-- Payment methods — 1/3 width -->
    <div class="bg-white border rounded-lg p-5 shadow-xs">
        <h2 class="font-bold text-stone-800 text-xs uppercase tracking-widest font-mono mb-4">Register payments <span class="normal-case tracking-normal text-stone-400">(after refunds)</span></h2>
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
                <span class="text-xs font-mono text-stone-500"><?= $fmt($m['rev']) ?></span>
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
    <h2 class="font-bold text-stone-800 text-xs uppercase tracking-widest font-mono mb-4">Register sales by hour <span class="normal-case tracking-normal text-stone-400">(farm time)</span></h2>
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
        <p class="p-6 text-stone-400 italic text-xs">No register sales in this period.</p>
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
        <h2 class="font-bold uppercase tracking-widest text-[10px]">Paid bookings by type <span class="normal-case tracking-normal">(by event date)</span></h2>
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
                label: 'Register',
                data:  <?= $json_chart_rev ?>,
                backgroundColor: 'rgba(28,25,23,0.8)',
                borderRadius: 3,
                yAxisID: 'y'
            },
            {
                label: 'Online, passes, bookings & parties',
                data:  <?= $json_chart_bk_rev ?>,
                backgroundColor: 'rgba(5,150,105,0.75)',
                borderRadius: 3,
                yAxisID: 'y'
            },
            {
                label: 'Register sales (count)',
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
