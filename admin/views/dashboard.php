<?php
// admin/views/dashboard.php
// Dashboard v1 — 02 Navigation & UX (Nov 5, 2026). Order: Action Center → Today → Quick actions → Key metrics → Calendar.
// Cron and Facebook warnings now live in the Action Center as alerts (banners retired from this screen).
// Alert priorities: urgent / today / fyi. Only FYI can be dismissed (on this device, until its count changes).
// Snooze, reassign and server-side alert state arrive with the Phase 0 Action Center tables.

$role  = $current_role ?? 'user';
$h     = fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$money = fn($n): string => '$' . number_format((float)$n, 2);
$canV  = function (string $v) use ($role): bool {
    if ($role === 'administrator') return true;
    if ($role === 'Staff') return $v === 'timecard';
    return function_exists('hasViewAccess') && hasViewAccess($role, $v);
};
$canT  = function (string $t) use ($role): bool {
    if ($role === 'administrator') return true;
    if ($role === 'Staff') return false;
    return function_exists('hasTableAccess') && hasTableAccess($role, $t);
};
$isAdmin = $role === 'administrator';
$A       = '/admin/admin.php';
$today   = date('Y-m-d');                       // Eastern (set in admin.php); MySQL's clock is not.
$soonDay = date('Y-m-d', strtotime('+1 day'));

if (!function_exists('site_open_status')) {
    try { require_once dirname(__DIR__, 2) . '/includes/site_info.php'; } catch (Throwable $e) {}
}

// ═══ ACTION CENTER ═══════════════════════════════════════════════════════════
// Built in includes/action_center.php (Nov 15, 2026), so the Owners' morning email lists the same problems.
require_once dirname(__DIR__, 2) . '/includes/action_center.php';
$alerts = bcrf_action_alerts($pdo, $role, ['low_stock_items' => $low_stock_items ?? [], 'forecast_items' => $forecast_items ?? [],
                                           'forecast_trends' => $forecast_trends ?? [], 'pending_comments_count' => $pending_comments_count ?? 0, 'today' => $today]);

$prioOrder = ['urgent' => 0, 'today' => 1, 'fyi' => 2];
usort($alerts, fn($a, $b) => $prioOrder[$a['priority']] <=> $prioOrder[$b['priority']]);
$prioCount = array_count_values(array_column($alerts, 'priority'));
$prioStyle = [
    'urgent' => ['label' => 'Urgent', 'bar' => 'border-l-red-600',   'chip' => 'bg-red-100 text-red-800'],
    'today'  => ['label' => 'Today',  'bar' => 'border-l-amber-500', 'chip' => 'bg-amber-100 text-amber-800'],
    'fyi'    => ['label' => 'FYI',    'bar' => 'border-l-stone-300', 'chip' => 'bg-stone-100 text-stone-600'],
];

// ═══ TODAY ═══════════════════════════════════════════════════════════════════
$openSt = function_exists('site_open_status') ? site_open_status('general') : null;
$hoursToday = '';
if (function_exists('site_hours')) {
    $wk = site_hours('general')[(int)date('w')] ?? ['closed' => true, 'blocks' => []];
    $hoursToday = $wk['closed'] ? 'Closed today'
        : implode(' & ', array_map(fn($b) => date('g:i A', strtotime($b['open'])) . ' – ' . date('g:i A', strtotime($b['close'])), $wk['blocks']));
}
$todayBookings = [];
if ($canV('bookings')) {
    $sql = "SELECT b.start_time, b.customer_name, b.party_size, b.status, b.is_exclusive, t.label
            FROM bookings b JOIN booking_types t ON t.id = b.type_id
            WHERE b.booking_date = ? AND b.status IN ('confirmed','pending') %s ORDER BY b.start_time";
    try {
        $st = $pdo->prepare(sprintf($sql, 'AND COALESCE(b.date_flexible, 0) = 0'));   // any-day tickets aren't scheduled visits
        $st->execute([$today]);
        $todayBookings = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        try { $st = $pdo->prepare(sprintf($sql, '')); $st->execute([$today]); $todayBookings = $st->fetchAll(PDO::FETCH_ASSOC) ?: []; }
        catch (Throwable $e2) { $todayBookings = []; }
    }
}
$todayEvents = [];
try {
    $st = $pdo->prepare("SELECT * FROM events WHERE status = 'published' AND start_datetime BETWEEN ? AND ? ORDER BY start_datetime");
    $st->execute([$today . ' 00:00:00', $today . ' 23:59:59']);
    $todayEvents = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
// Gate occupancy (02 › Today; Nov 13, 2026; includes/admission.php) — anyone who can use the gate.
$occ = null;
if ($canV('pos') || $canV('passes')) {
    try {
        require_once dirname(__DIR__, 2) . '/includes/admission.php';
        if (bcrf_admission_ready($pdo)) $occ = bcrf_occupancy($pdo);
    } catch (Throwable $e) { $occ = null; }
}
$todayGuests = array_sum(array_map(fn($b) => $b['status'] === 'confirmed' ? (int)$b['party_size'] : 0, $todayBookings));

// ═══ QUICK ACTIONS ═══════════════════════════════════════════════════════════
$quick = [];
if ($canV('pos'))              $quick[] = ['💳', 'New sale',         '/admin/register.php'];
if ($canV('pos') || $canV('passes')) $quick[] = ['🎟️', 'Check in guest', '/admin/gate.php'];
if ($canV('inventory_update')) $quick[] = ['📦', 'Count inventory',  "$A?view=inventory_update"];
if ($canV('bookings'))         $quick[] = ['📋', "Today's bookings", "$A?view=bookings&day=$today"];
if ($canV('timecard'))         $quick[] = ['⏰', 'Clock in / out',   "$A?view=timecard"];
if ($canV('tasks'))            $quick[] = ['✅', 'My tasks',         '/admin/tasks.php'];

// ═══ CALENDAR (Nov 15, 2026) ════════════════════════════════════════════════
// Every Team › Calendar layer this person may see (includes/calendar.php: parties, group bookings, public events, tasks,
// deliveries, interviews, closed days, renewals & due dates), one coloured dot per layer. Tapping a day opens that week
// on Team › Calendar (or the day's bookings for someone without the Calendar page).
$cal_first = (isset($_GET['cal']) && preg_match('/^\d{4}-\d{2}$/', (string)$_GET['cal']))
    ? DateTime::createFromFormat('Y-m-d', $_GET['cal'] . '-01') : null;
if (!$cal_first) $cal_first = new DateTime('first day of this month');
$cal_first->setTime(0, 0, 0);
$cal_year = (int)$cal_first->format('Y'); $cal_mon = (int)$cal_first->format('n');
$cal_days = (int)$cal_first->format('t'); $cal_startDow = (int)$cal_first->format('w');
$mStart = $cal_first->format('Y-m-01'); $mEnd = $cal_first->format('Y-m-t');
$cal_layers = []; $cal_dots = [];
try {
    require_once dirname(__DIR__, 2) . '/includes/tasks.php';
    require_once dirname(__DIR__, 2) . '/includes/calendar.php';
    $cal_layers = $role === 'Staff' ? [] : bcrf_cal_layers_for($role);
    if ($cal_layers) {
        $cal_viewer = ['user_id' => (int)($_SESSION['user_id'] ?? 0), 'role' => $role, 'manager' => bcrf_tasks_is_manager()];
        foreach (bcrf_cal_items($pdo, $mStart, $mEnd, $cal_layers, $cal_viewer) as $it) {
            $cd = (int)substr($it['date'], 8, 2);
            $cal_dots[$cd][$it['layer']] = ($cal_dots[$cd][$it['layer']] ?? 0) + 1;
        }
    }
} catch (Throwable $e) { error_log('[dashboard] calendar: ' . $e->getMessage()); }
$cal_prev = (clone $cal_first)->modify('-1 month')->format('Y-m');
$cal_next = (clone $cal_first)->modify('+1 month')->format('Y-m');
$showCal  = (bool)$cal_layers;
$cal_full = $canV('calendar');
$showSales = $canV('pos') || $canV('sales_analytics') || $canV('receipts');

$card = 'bg-white rounded-xl border border-stone-200 shadow-xs';
?>
<div class="max-w-6xl">

  <div class="flex flex-wrap items-end justify-between gap-2 mb-4">
    <div>
      <h1 class="text-2xl font-bold text-stone-900">Dashboard</h1>
      <div class="text-xs text-stone-500"><?= $h(date('l, F j')) ?></div>
    </div>
    <?php if ($openSt): ?>
    <span class="px-3 py-1 rounded-full text-xs font-bold <?= $openSt['open'] ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-200 text-stone-600' ?>">
      <?= $openSt['open'] ? '🟢' : '⚪' ?> <?= $h($openSt['label']) ?><?= $openSt['detail'] ? ' · ' . $h($openSt['detail']) : '' ?>
    </span>
    <?php endif; ?>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 lg:grid-rows-[auto_1fr] gap-4 lg:gap-6">

    <!-- ═══ ACTION CENTER ═══ -->
    <section class="lg:col-span-2 lg:col-start-1 lg:row-start-1 space-y-3" aria-labelledby="acHead">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 id="acHead" class="text-sm font-black uppercase tracking-widest text-stone-500">Action Center</h2>
        <div class="flex flex-wrap items-center gap-1.5 text-[10px] font-bold">
          <?php foreach ($prioStyle as $pk => $ps): if (empty($prioCount[$pk])) continue; ?>
            <span class="px-2 py-0.5 rounded-full <?= $ps['chip'] ?>"><?= (int)$prioCount[$pk] ?> <?= $ps['label'] ?></span>
          <?php endforeach; ?>
        </div>
      </div>

      <div id="acList" class="space-y-3">
        <div id="acAllClear" class="<?= $card ?> p-6 text-center"<?= $alerts ? ' hidden' : '' ?>>
          <div class="text-2xl mb-1">✅</div>
          <div class="text-sm font-bold text-stone-700">All clear</div>
          <div class="text-xs text-stone-500">Anything that needs you (bookings, inquiries, low stock, system problems) shows up here.</div>
        </div>

        <?php foreach ($alerts as $al): $ps = $prioStyle[$al['priority']]; $dis = $al['priority'] === 'fyi'; ?>
        <article class="<?= $card ?> border-l-4 <?= $ps['bar'] ?> p-4"
                 data-alert="<?= $h($al['id']) ?>" data-sig="<?= $h((string)$al['count']) ?>"<?= $dis ? ' data-dismissible' : '' ?>>
          <div class="flex items-start gap-3">
            <div class="text-xl leading-none mt-0.5 shrink-0"><?= $al['icon'] ?></div>
            <div class="flex-1 min-w-0">
              <div class="flex flex-wrap items-center gap-2 mb-0.5">
                <span class="px-1.5 py-0.5 rounded text-[9px] font-black uppercase tracking-wider <?= $ps['chip'] ?>"><?= $ps['label'] ?></span>
                <h3 class="text-sm font-bold text-stone-900"><?= $h($al['title']) ?></h3>
              </div>
              <?php if ($al['detail'] !== ''): ?><p class="text-xs text-stone-500"><?= $h($al['detail']) ?></p><?php endif; ?>
              <?php if ($al['items']): ?>
              <ul class="mt-1 text-xs text-stone-600 list-disc pl-4 space-y-0.5">
                <?php foreach ($al['items'] as $it): ?><li class="break-words"><?= $h($it) ?></li><?php endforeach; ?>
              </ul>
              <?php endif; ?>
              <div class="flex flex-wrap gap-2 mt-3">
                <?php foreach ($al['actions'] as [$lab, $href, $primary]): ?>
                <a href="<?= $h($href) ?>" class="inline-flex items-center min-h-10 px-4 rounded-lg text-xs font-bold <?= $primary ? 'bg-amber-700 text-white hover:bg-amber-800' : 'bg-stone-100 text-stone-700 hover:bg-stone-200' ?>"><?= $h($lab) ?></a>
                <?php endforeach; ?>
                <?php if ($dis): ?>
                <button type="button" data-dismiss class="inline-flex items-center min-h-10 px-3 rounded-lg text-xs font-bold text-stone-400 hover:text-stone-700 hover:bg-stone-100">Dismiss</button>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
      <button type="button" id="acRestore" hidden class="text-xs font-bold text-stone-500 underline"></button>
    </section>

    <!-- ═══ TODAY + QUICK ACTIONS + METRICS ═══ -->
    <div class="lg:col-start-3 lg:row-start-1 lg:row-span-2 space-y-4">

      <?php if ($quick): ?>
      <section aria-label="Quick actions" class="grid grid-cols-2 gap-2">
        <?php foreach ($quick as [$qi, $ql, $qh]): ?>
        <a href="<?= $h($qh) ?>" class="<?= $card ?> flex flex-col items-center justify-center gap-1 min-h-20 p-3 text-center hover:border-stone-400 transition">
          <span class="text-2xl leading-none"><?= $qi ?></span>
          <span class="text-xs font-bold text-stone-800"><?= $h($ql) ?></span>
        </a>
        <?php endforeach; ?>
      </section>
      <?php endif; ?>

      <section class="<?= $card ?> p-4" aria-labelledby="todayHead">
        <h2 id="todayHead" class="text-sm font-black uppercase tracking-widest text-stone-500 mb-2">Today</h2>
        <?php if ($hoursToday !== ''): ?>
          <div class="text-xs text-stone-700 mb-3"><span class="font-bold">Hours:</span> <?= $h($hoursToday) ?></div>
        <?php endif; ?>

        <?php if ($occ): $oc = $occ['level'] === 'full' ? 'bg-red-100 text-red-700' : ($occ['level'] === 'busy' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800'); ?>
        <a href="/admin/gate.php" class="mb-3 flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-xs <?= $oc ?>">
          <span><span class="font-black text-base"><?= (int)$occ['on_site'] ?></span> of <?= (int)$occ['limit'] ?> on site<?= $occ['level'] === 'full' ? ' · full' : '' ?></span>
          <span><?= (int)$occ['visitors_today'] ?> visitor<?= (int)$occ['visitors_today'] === 1 ? '' : 's' ?> today · Gate ›</span>
        </a>
        <?php endif; ?>

        <?php if ($canV('bookings')): ?>
        <div class="mb-3">
          <div class="flex items-center justify-between text-xs font-bold text-stone-700 mb-1">
            <span>Bookings</span>
            <?php if ($todayBookings): ?><span class="text-stone-400 font-normal"><?= (int)$todayGuests ?> confirmed guests</span><?php endif; ?>
          </div>
          <?php if (!$todayBookings): ?>
            <p class="text-xs text-stone-400">No bookings today.</p>
          <?php else: ?>
          <ul class="divide-y divide-stone-100">
            <?php foreach (array_slice($todayBookings, 0, 6) as $b): ?>
            <li class="py-1.5 flex items-center gap-2 text-xs">
              <span class="text-stone-500 w-14 shrink-0"><?= $h(date('g:ia', strtotime($b['start_time']))) ?></span>
              <span class="flex-1 min-w-0 truncate"><span class="font-semibold text-stone-800"><?= $h($b['customer_name']) ?></span> <span class="text-stone-400">· <?= (int)$b['party_size'] ?> · <?= $h($b['label']) ?></span></span>
              <?php if ($b['status'] === 'pending'): ?><span class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-amber-100 text-amber-800">Pending</span><?php endif; ?>
              <?php if ((int)$b['is_exclusive'] === 1): ?><span class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-red-100 text-red-700">Private</span><?php endif; ?>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php if (count($todayBookings) > 6): ?><p class="text-[11px] text-stone-400 mt-1">+<?= count($todayBookings) - 6 ?> more</p><?php endif; ?>
          <?php endif; ?>
          <a href="<?= $h("$A?view=bookings&day=$today") ?>" class="inline-block mt-1 text-xs font-bold text-amber-700 underline">Open today's schedule</a>
        </div>
        <?php endif; ?>

        <?php if ($todayEvents): ?>
        <div class="mb-3">
          <div class="text-xs font-bold text-stone-700 mb-1">Farm events</div>
          <ul class="space-y-1">
            <?php foreach ($todayEvents as $ev): ?>
            <li class="text-xs"><span class="text-stone-500"><?= $h(date('g:ia', strtotime($ev['start_datetime']))) ?></span> <span class="font-semibold text-stone-800"><?= $h($ev['title'] ?? $ev['name'] ?? 'Event') ?></span></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <div>
          <div class="text-xs font-bold text-stone-700 mb-1">On shift now</div>
          <?php if (empty($active_shifts)): ?>
            <p class="text-xs text-stone-400">Nobody is clocked in.</p>
          <?php else: foreach ($active_shifts as $s): ?>
            <div class="flex items-center gap-2 text-xs font-semibold text-emerald-700 py-0.5">
              <span class="w-2 h-2 rounded-full bg-emerald-500 shrink-0"></span><?= $h($s['first_name'] . ' ' . $s['last_name']) ?>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </section>

      <?php if ($showSales):
        $tr = (float)($sales_today['rev'] ?? 0); $yr = (float)($sales_yesterday['rev'] ?? 0); ?>
      <section class="<?= $card ?> p-4" aria-labelledby="salesHead">
        <h2 id="salesHead" class="text-sm font-black uppercase tracking-widest text-stone-500 mb-2">Today's sales</h2>
        <div class="text-3xl font-black text-emerald-800"><?= $money($tr) ?></div>
        <div class="text-xs text-stone-500"><?= (int)($sales_today['count'] ?? 0) ?> sale<?= (int)($sales_today['count'] ?? 0) === 1 ? '' : 's' ?> &amp; payments, before tax (register, online, passes, parties)</div>
        <?php if ($yr > 0): $diff = $tr - $yr; $up = $diff >= 0; ?>
          <div class="mt-2 text-[11px] font-bold <?= $up ? 'text-emerald-600' : 'text-red-500' ?>">
            <?= $up ? '▲ +' : '▼ -' ?><?= $money(abs($diff)) ?> (<?= (int)round(abs($diff) / $yr * 100) ?>%) vs yesterday
          </div>
        <?php else: ?>
          <div class="mt-2 text-[11px] text-stone-400">No money in yesterday to compare</div>
        <?php endif; ?>
        <div class="mt-2 pt-2 border-t border-stone-100 text-[11px] text-stone-500">7-day avg: <span class="font-bold text-stone-700"><?= $money($sales_7day_avg ?? 0) ?>/day</span></div>
      </section>
      <?php endif; ?>
    </div>

  <?php if ($showCal): ?>
  <!-- ═══ CALENDAR ═══ (left column under the Action Center on wide screens; last on phones) -->
  <section class="<?= $card ?> p-4 md:p-6 lg:col-span-2 lg:col-start-1 lg:row-start-2 self-start" aria-labelledby="calHead">
    <div class="flex items-center justify-between mb-4">
      <h2 id="calHead" class="text-sm font-bold text-stone-700"><?= $h($cal_first->format('F Y')) ?></h2>
      <div class="flex items-center gap-2">
        <a href="<?= $A ?>?view=dashboard&cal=<?= $cal_prev ?>" class="px-3 py-2 rounded bg-stone-100 text-stone-600 hover:bg-stone-200 text-xs" aria-label="Previous month">&larr;</a>
        <a href="<?= $A ?>?view=dashboard" class="px-3 py-2 rounded bg-stone-100 text-stone-600 hover:bg-stone-200 text-xs">Today</a>
        <a href="<?= $A ?>?view=dashboard&cal=<?= $cal_next ?>" class="px-3 py-2 rounded bg-stone-100 text-stone-600 hover:bg-stone-200 text-xs" aria-label="Next month">&rarr;</a>
      </div>
    </div>
    <div class="grid grid-cols-7 gap-1 text-center text-[10px] font-bold text-stone-400 uppercase mb-1">
      <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
    </div>
    <div class="grid grid-cols-7 gap-1">
      <?php for ($i = 0; $i < $cal_startDow; $i++): ?><div></div><?php endfor; ?>
      <?php for ($d = 1; $d <= $cal_days; $d++):
          $ds = sprintf('%04d-%02d-%02d', $cal_year, $cal_mon, $d); $isT = $ds === $today;
          $here = $cal_dots[$d] ?? [];
          $dayHref = $cal_full ? '/admin/calendar.php?view=list&date=' . $ds : ($canV('bookings') ? "$A?view=bookings&day=$ds" : '');
          $dayLabel = date('M j', strtotime($ds)) . ($here ? ': ' . implode(', ', array_map(fn($l, $n) => $n . ' ' . BCRF_CAL_LAYERS[$l]['label'], array_keys($here), $here)) : ''); ?>
        <<?= $dayHref !== '' ? 'a href="' . $h($dayHref) . '"' : 'div' ?> aria-label="<?= $h($dayLabel) ?>" title="<?= $h($dayLabel) ?>"
           class="aspect-square flex flex-col items-center justify-center rounded border text-xs transition <?= $isT ? 'border-amber-400 bg-amber-50 font-bold text-amber-800' : 'border-stone-100 hover:bg-stone-50 text-stone-600' ?>">
          <span><?= $d ?></span>
          <span class="flex flex-wrap justify-center gap-0.5 mt-0.5 min-h-1.5 max-w-full px-0.5">
            <?php foreach (array_keys(BCRF_CAL_LAYERS) as $l): if (empty($here[$l])) continue; ?><span class="w-1.5 h-1.5 rounded-full <?= BCRF_CAL_LAYERS[$l]['dot'] ?>"></span><?php endforeach; ?>
          </span>
        </<?= $dayHref !== '' ? 'a' : 'div' ?>>
      <?php endfor; ?>
    </div>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-4 text-[10px] text-stone-500">
      <?php foreach ($cal_layers as $l): ?><span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full <?= BCRF_CAL_LAYERS[$l]['dot'] ?>"></span> <?= $h(BCRF_CAL_LAYERS[$l]['label']) ?></span><?php endforeach; ?>
      <?php if ($cal_full): ?><a href="/admin/calendar.php" class="ml-auto text-amber-800 underline">Open the full calendar</a><?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
  </div>
</div>
<script src="/admin/assets/admin-dashboard.js?v=20261105a"></script>
