<?php
// ── Bookings + events dashboard data (self-contained, guarded) ──
$bk_pending   = 0;
$cal_bookings = [];
$cal_events   = [];
$cal_first = (isset($_GET['cal']) && preg_match('/^\d{4}-\d{2}$/', (string)$_GET['cal']))
    ? DateTime::createFromFormat('Y-m-d', $_GET['cal'] . '-01')
    : null;
if (!$cal_first) $cal_first = new DateTime('first day of this month');
$cal_first->setTime(0, 0, 0);
$cal_year     = (int)$cal_first->format('Y');
$cal_mon      = (int)$cal_first->format('n');
$cal_days     = (int)$cal_first->format('t');
$cal_startDow = (int)$cal_first->format('w');               // 0=Sun
$mStart       = $cal_first->format('Y-m-01');
$mEnd         = $cal_first->format('Y-m-t');

try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE status = 'pending' AND booking_date >= CURDATE()");
    $st->execute();
    $bk_pending = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT DISTINCT booking_date d FROM bookings WHERE status = 'confirmed' AND booking_date BETWEEN ? AND ?");
    $st->execute([$mStart, $mEnd]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) { $cal_bookings[(int)substr($d, 8, 2)] = true; }
} catch (Throwable $e) { /* booking tables may not exist yet */ }

try {
    $st = $pdo->prepare("SELECT DISTINCT DATE(start_datetime) d FROM events WHERE status = 'published' AND start_datetime BETWEEN ? AND ?");
    $st->execute([$mStart . ' 00:00:00', $mEnd . ' 23:59:59']);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) { $cal_events[(int)substr($d, 8, 2)] = true; }
} catch (Throwable $e) { /* events table */ }

$cal_prev  = (clone $cal_first)->modify('-1 month')->format('Y-m');
$cal_next  = (clone $cal_first)->modify('+1 month')->format('Y-m');
$cal_today = date('Y-m-d');
?>
<h1 class="text-3xl font-serif font-bold mb-6">Operational Summary</h1>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <div class="lg:col-span-2 space-y-6">

        <!-- ── CRITICAL INVENTORY ALERTS ── -->
        <div class="bg-white rounded border border-stone-200 p-6 shadow-xs">
            <h2 class="text-md font-bold text-red-800 mb-4 flex items-center gap-2">🚨 Critical Inventory Alerts</h2>
            <div class="divide-y divide-stone-100">
                <?php if (empty($low_stock_items)): ?>
                    <p class="text-sm text-stone-500 py-4">All products and finds safely stocked.</p>
                <?php else: foreach ($low_stock_items as $item): ?>
                    <div class="py-3 flex justify-between items-center text-xs">
                        <div>
                            <span class="text-xxs font-bold uppercase px-2 py-0.5 rounded bg-red-50 text-red-700 border border-red-200 mr-2"><?= htmlspecialchars($item['cat']) ?></span>
                            <span class="font-semibold text-stone-800"><?= htmlspecialchars($item['name']) ?></span>
                        </div>
                        <span class="font-mono text-red-600 font-bold bg-red-50/50 px-2 py-0.5 rounded border border-red-100"><?= (float)$item['stock_quantity'] ?> left</span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- ── PREDICTIVE INVENTORY FORECAST ── -->
        <div class="bg-white rounded border border-stone-200 p-6 shadow-xs">
            <h2 class="text-md font-bold text-emerald-800 mb-4 flex items-center gap-2">📈 Predictive Inventory Forecast</h2>
            <div class="divide-y divide-stone-100">
                <?php
                // Data is loaded in loader.php — just render here
                $has_forecast = false;
                foreach ($forecast_items as $fitem):
                    $velocity  = $forecast_trends[$fitem['id']] ?? 0;
                    if ($velocity < 0.01) continue; // Fix 3: floor to avoid near-zero division

                    $min_level = $fitem['min_stock_level'] ?? 0; // Fix 8: column may not exist yet
                    $days_left = ($fitem['stock_quantity'] - $min_level) / $velocity;

                    if ($days_left > 14) continue;
                    $has_forecast = true;

                    // Fix 9: colored left border — red critical (<7d), amber warning (7-14d)
                    $border_class = $days_left < 7 ? 'border-l-4 border-red-500'   : 'border-l-4 border-amber-400';
                    $text_class   = $days_left < 7 ? 'text-red-600 font-black'      : 'text-amber-600 font-bold';
                    $label        = $days_left < 7 ? 'Critical — reorder now'        : 'Reorder soon';
                ?>
                    <div class="py-3 flex justify-between items-center text-xs pl-3 <?= $border_class ?>">
                        <div>
                            <span class="font-semibold text-stone-800"><?= htmlspecialchars($fitem['name']) ?></span>
                            <span class="block text-xxs text-stone-400 mt-0.5">
                                <?= htmlspecialchars($fitem['category']) ?> &bull;
                                Velocity: <?= number_format($velocity, 1) ?> units/day &bull;
                                <?= (float)$fitem['stock_quantity'] ?> in stock
                            </span>
                        </div>
                        <div class="text-right shrink-0 ml-4">
                            <span class="block <?= $text_class ?>"><?= round($days_left) ?> days left</span>
                            <span class="text-xxs uppercase text-stone-400"><?= $label ?></span>
                        </div>
                    </div>
                <?php endforeach;
                if (!$has_forecast): ?>
                    <p class="text-sm text-stone-500 py-4">Sufficient stock levels for all high-velocity items.</p>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ── SIDE WIDGETS ── -->
    <div class="space-y-6">

        <!-- PENDING BOOKING REQUESTS -->
        <div class="bg-white rounded border <?= $bk_pending > 0 ? 'border-amber-300' : 'border-stone-200' ?> p-6 shadow-xs">
            <h2 class="text-xs font-bold text-stone-500 uppercase mb-2">Booking Requests</h2>
            <div class="text-3xl font-black <?= $bk_pending > 0 ? 'text-amber-700' : 'text-stone-400' ?>"><?= $bk_pending ?></div>
            <div class="text-xs text-stone-400 mb-1"><?= $bk_pending === 1 ? 'request' : 'requests' ?> awaiting confirmation</div>
            <a href="/admin/admin.php?view=bookings" class="text-xs font-bold text-amber-700 underline hover:text-amber-900 mt-1 block">Review now &rarr;</a>
        </div>

        <!-- SALES PULSE — today + yesterday comparison -->
        <div class="bg-white rounded border border-stone-200 p-6 shadow-xs">
            <h2 class="text-xs font-bold text-stone-500 uppercase mb-2">Today's Sales Pulse</h2>
            <div class="text-3xl font-black text-emerald-800">$<?= number_format($sales_today['rev'] ?? 0, 2) ?></div>
            <div class="text-xs text-stone-400 mb-3"><?= $sales_today['count'] ?? 0 ?> Transactions</div>
            <?php
            // Fix 7: yesterday comparison
            $today_rev     = (float)($sales_today['rev']     ?? 0);
            $yesterday_rev = (float)($sales_yesterday['rev'] ?? 0);
            if ($yesterday_rev > 0):
                $diff    = $today_rev - $yesterday_rev;
                $pct     = round(abs($diff) / $yesterday_rev * 100);
                $up      = $diff >= 0;
            ?>
                <div class="flex items-center gap-1 text-xxs font-bold font-mono <?= $up ? 'text-emerald-600' : 'text-red-500' ?>">
                    <span><?= $up ? '▲' : '▼' ?></span>
                    <span><?= $up ? '+' : '-' ?>$<?= number_format(abs($diff), 2) ?> (<?= $pct ?>%) vs yesterday</span>
                </div>
            <?php elseif ($yesterday_rev === 0.0): ?>
                <div class="text-xxs font-mono text-stone-400">No sales yesterday to compare</div>
            <?php endif; ?>
            <div class="mt-3 pt-3 border-t border-stone-100 text-xxs font-mono text-stone-400">
                7-day avg: <span class="font-bold text-stone-600">$<?= number_format($sales_7day_avg ?? 0, 2) ?>/day</span>
            </div>
        </div>

        <!-- PENDING COMMENTS -->
        <?php
        $pcc = $pending_comments_count ?? 0;
        if ($pcc > 0): ?>
        <div class="bg-white rounded border <?= $pcc > 0 ? 'border-amber-300' : 'border-stone-200' ?> p-6 shadow-xs">
            <h2 class="text-xs font-bold text-stone-500 uppercase mb-2">Comments to Review</h2>
            <div class="text-3xl font-black <?= $pcc > 0 ? 'text-amber-700' : 'text-stone-400' ?>"><?= $pcc ?></div>
            <a href="/admin/admin.php?view=comment_moderation"
                class="text-xs font-bold text-amber-700 underline hover:text-amber-900 mt-1 block">
                Review now &rarr;
            </a>
        </div>
        <?php endif; ?>

        <!-- ACTIVE SHIFTS -->
        <div class="bg-white rounded border border-stone-200 p-6 shadow-xs">
            <h2 class="text-xs font-bold text-stone-500 uppercase mb-2">Active Staff Shifts</h2>
            <?php if (empty($active_shifts)): ?>
                <!-- Fix 4: explicit empty state -->
                <p class="text-xs text-stone-400 italic">No active shifts right now.</p>
            <?php else: foreach ($active_shifts as $staff): ?>
                <div class="flex items-center gap-2 text-xs font-bold text-emerald-700 py-0.5">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 shrink-0"></span>
                    <?= htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']) ?>
                </div>
            <?php endforeach; endif; ?>
        </div>

    </div>
</div>

<!-- ── BOOKINGS & EVENTS CALENDAR ── -->
<div class="bg-white rounded border border-stone-200 p-6 shadow-xs mt-6">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-md font-bold text-stone-700"><?= htmlspecialchars($cal_first->format('F Y')) ?></h2>
        <div class="flex items-center gap-2">
            <a href="/admin/admin.php?view=dashboard&cal=<?= $cal_prev ?>" class="px-2 py-1 rounded bg-stone-100 text-stone-600 hover:bg-stone-200 text-xs" title="Previous month">&larr;</a>
            <a href="/admin/admin.php?view=dashboard" class="px-2 py-1 rounded bg-stone-100 text-stone-600 hover:bg-stone-200 text-xs">Today</a>
            <a href="/admin/admin.php?view=dashboard&cal=<?= $cal_next ?>" class="px-2 py-1 rounded bg-stone-100 text-stone-600 hover:bg-stone-200 text-xs" title="Next month">&rarr;</a>
        </div>
    </div>

    <div class="grid grid-cols-7 gap-1 text-center text-xxs font-bold text-stone-400 uppercase mb-1">
        <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
    </div>

    <div class="grid grid-cols-7 gap-1">
        <?php for ($i = 0; $i < $cal_startDow; $i++): ?><div></div><?php endfor; ?>
        <?php for ($d = 1; $d <= $cal_days; $d++):
            $dateStr = sprintf('%04d-%02d-%02d', $cal_year, $cal_mon, $d);
            $isToday = ($dateStr === $cal_today);
            $hasB    = !empty($cal_bookings[$d]);
            $hasE    = !empty($cal_events[$d]);
        ?>
            <a href="/admin/admin.php?view=bookings&day=<?= $dateStr ?>"
               class="aspect-square flex flex-col items-center justify-center rounded border text-xs transition <?= $isToday ? 'border-amber-400 bg-amber-50 font-bold text-amber-800' : 'border-stone-100 hover:bg-stone-50 text-stone-600' ?>">
                <span><?= $d ?></span>
                <span class="flex gap-0.5 mt-0.5 h-1.5">
                    <?php if ($hasB): ?><span class="w-1.5 h-1.5 rounded-full bg-green-500"></span><?php endif; ?>
                    <?php if ($hasE): ?><span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span><?php endif; ?>
                </span>
            </a>
        <?php endfor; ?>
    </div>

    <div class="flex items-center gap-4 mt-4 text-xxs text-stone-500">
        <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-green-500"></span> Bookings</span>
        <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-amber-500"></span> Farm events</span>
        <span class="ml-auto text-stone-400">Click a day to manage its bookings</span>
    </div>
</div>
