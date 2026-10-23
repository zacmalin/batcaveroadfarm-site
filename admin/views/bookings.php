<?php
// admin/views/bookings.php — booking management: day occupancy, pending queue, schedule, blackouts.
require_once __DIR__ . '/../../includes/booking.php';

$csrf   = $_SESSION['csrf_token'] ?? '';
$day    = (isset($_GET['day']) && strtotime((string)$_GET['day'])) ? date('Y-m-d', strtotime((string)$_GET['day'])) : date('Y-m-d');
$warnId = (int)($_GET['warn'] ?? 0);
$cap    = function_exists('booking_grounds_cap') ? booking_grounds_cap() : 25;

$ready = function_exists('booking_tables_ready') ? booking_tables_ready($pdo) : false;

$pending = $daySched = $blackouts = $confirmed = [];
if ($ready) {
    try {
        $pending = $pdo->query(
            "SELECT b.*, t.label FROM bookings b JOIN booking_types t ON t.id = b.type_id
             WHERE b.status = 'pending' AND b.booking_date >= CURDATE()
             ORDER BY b.booking_date, b.start_time"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $q = $pdo->prepare(
            "SELECT b.*, t.label FROM bookings b JOIN booking_types t ON t.id = b.type_id
             WHERE b.booking_date = ?
             ORDER BY FIELD(b.status,'confirmed','pending','completed','no_show','cancelled'), b.start_time"
        );
        $q->execute([$day]);
        $daySched = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($daySched as $r) { if ($r['status'] === 'confirmed') $confirmed[] = $r; }

        $blackouts = $pdo->query(
            "SELECT * FROM booking_blackouts WHERE d_date >= CURDATE() ORDER BY d_date"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $ready = false; }
}

// open-hours window for the selected weekday -> hourly buckets
$openH = 10; $closeH = 16;
if (function_exists('site_hours')) {
    $wk  = site_hours('general');
    $dow = (int)date('w', strtotime($day));
    $dh  = $wk[$dow] ?? ['closed' => true, 'blocks' => []];
    if (empty($dh['closed']) && !empty($dh['blocks'])) {
        $oH = 23; $cH = 0;
        foreach ($dh['blocks'] as $b) {
            $oH = min($oH, (int)substr($b['open'], 0, 2));
            $cm = (int)substr($b['close'], 0, 2) + ((int)substr($b['close'], 3, 2) > 0 ? 1 : 0);
            $cH = max($cH, $cm);
        }
        if ($oH < $cH) { $openH = $oH; $closeH = $cH; }
    }
}

$hourLabel = function (int $h): string { $ap = $h < 12 ? 'a' : 'p'; $x = $h % 12; if ($x === 0) $x = 12; return $x . $ap; };
$overlaps  = fn($as, $ae, $bs, $be) => ($as < $be) && ($ae > $bs);

$statusBadge = function (string $s): string {
    $map = [
        'pending'   => 'bg-amber-100 text-amber-800',
        'confirmed' => 'bg-green-100 text-green-800',
        'completed' => 'bg-stone-200 text-stone-600',
        'no_show'   => 'bg-red-100 text-red-700',
        'cancelled' => 'bg-stone-100 text-stone-400 line-through',
    ];
    $cls = $map[$s] ?? 'bg-stone-100 text-stone-600';
    return '<span class="px-2 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wide ' . $cls . '">' . str_replace('_', '-', $s) . '</span>';
};


// Ticket breakdown display helper
$tixBreakdown = function (array $row): string {
    if (empty($row['ticket_breakdown'])) return '';
    $tb = json_decode($row['ticket_breakdown'], true);
    if (!is_array($tb) || empty($tb)) return '';
    $parts = [];
    foreach ($tb as $name => $data) {
        $parts[] = htmlspecialchars($name) . ' Ã' . (int)$data['qty'];
    }
    return '<div class="text-[10px] font-mono text-stone-400 mt-1 tracking-tight">ðï¸ ' . implode(' &bull; ', $parts) . '</div>';
};

$payBadge = function (array $row): string {
    $ps  = $row['payment_status'] ?? 'waived';
    $map = [
        'paid'     => ['bg-emerald-100 text-emerald-800', '💳 paid'],
        'pending'  => ['bg-amber-100 text-amber-700',    '⏳ unpaid'],
        'refunded' => ['bg-red-100 text-red-700',        '↩ refunded'],
        'waived'   => ['bg-stone-100 text-stone-400',    'cash/walk-in'],
    ];
    [$cls, $label] = $map[$ps] ?? ['bg-stone-100 text-stone-400', $ps];
    $amt = !empty($row['amount_total']) && (float)$row['amount_total'] > 0
        ? ' · $' . number_format((float)$row['amount_total'], 2) : '';
    return '<span class="px-2 py-0.5 rounded text-[10px] font-medium ' . $cls . '">' . $label . $amt . '</span>';
};

// emits a one-button POST form
$act = function (string $action, int $id, string $label, string $cls, ?string $confirm = null) use ($csrf, $day) {
    $dc = $confirm ? ' data-confirm="' . htmlspecialchars($confirm, ENT_QUOTES) . '"' : '';
    echo '<form method="POST" action="/admin/admin.php?view=bookings" class="inline-block">'
       . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf, ENT_QUOTES) . '">'
       . '<input type="hidden" name="bookings_action" value="' . htmlspecialchars($action, ENT_QUOTES) . '">'
       . '<input type="hidden" name="id" value="' . $id . '">'
       . '<input type="hidden" name="day" value="' . htmlspecialchars($day, ENT_QUOTES) . '">'
       . '<button type="submit" class="' . $cls . '"' . $dc . '>' . htmlspecialchars($label) . '</button></form>';
};

$prev = date('Y-m-d', strtotime($day . ' -1 day'));
$next = date('Y-m-d', strtotime($day . ' +1 day'));
$btn  = 'px-3 py-1.5 rounded text-xs font-semibold';
?>

<div class="max-w-6xl">
  <h1 class="text-2xl font-bold text-stone-800 mb-1">📋 Bookings</h1>
  <p class="text-sm text-stone-500 mb-6">Confirm requests, watch the grounds cap of <?= (int)$cap ?> concurrent guests, and block out dates. Walk-ins under 6 don't book; the public form covers groups of 6+, tours, and private events.</p>

  <?php if (!$ready): ?>
    <div class="bg-amber-50 border border-amber-300 text-amber-800 rounded-lg p-4 text-sm">
      Booking tables aren't set up yet. Run <code class="font-mono bg-amber-100 px-1 rounded">sql/booking_module.sql</code> in phpMyAdmin, then reload.
    </div>
  <?php else: ?>

<?php
// Payment summary for current day
$dayPaidRev    = array_sum(array_map(fn($r) => ($r['payment_status'] ?? '') === 'paid'     ? (float)($r['amount_total'] ?? 0) : 0, $daySched));
$dayPendingPay = count(array_filter($daySched,  fn($r) => ($r['payment_status'] ?? '') === 'pending'));
$allPaidRev    = array_sum(array_map(fn($r) => ($r['payment_status'] ?? '') === 'paid'     ? (float)($r['amount_total'] ?? 0) : 0, $pending));
$allPendingPay = count(array_filter($pending,    fn($r) => ($r['payment_status'] ?? '') === 'pending'));
?>

<!-- Revenue summary -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
  <div class="bg-white border border-stone-200 rounded-lg p-3 shadow-xs">
    <div class="text-[9px] font-bold uppercase tracking-widest text-stone-400 font-mono">Paid today</div>
    <div class="text-xl font-black text-emerald-700 font-mono mt-1">$<?= number_format($dayPaidRev, 2) ?></div>
  </div>
  <div class="bg-white border border-stone-200 rounded-lg p-3 shadow-xs">
    <div class="text-[9px] font-bold uppercase tracking-widest text-stone-400 font-mono">Awaiting payment</div>
    <div class="text-xl font-black <?= ($dayPendingPay + $allPendingPay) > 0 ? 'text-amber-600' : 'text-stone-400' ?> font-mono mt-1"><?= $dayPendingPay + $allPendingPay ?></div>
  </div>
  <div class="bg-white border border-stone-200 rounded-lg p-3 shadow-xs">
    <div class="text-[9px] font-bold uppercase tracking-widest text-stone-400 font-mono">Pending requests</div>
    <div class="text-xl font-black text-stone-700 font-mono mt-1"><?= count($pending) ?></div>
  </div>
  <div class="bg-white border border-stone-200 rounded-lg p-3 shadow-xs">
    <div class="text-[9px] font-bold uppercase tracking-widest text-stone-400 font-mono">Today's bookings</div>
    <div class="text-xl font-black text-stone-700 font-mono mt-1"><?= count($daySched) ?></div>
  </div>
</div>

  <!-- Pending queue -->
  <div class="bg-white border border-stone-200 rounded-lg p-5 mb-8 shadow-sm">
    <h2 class="font-semibold text-stone-700 mb-4">⏳ Pending requests <span class="text-stone-400 font-normal">(<?= count($pending) ?>)</span></h2>
    <?php if (!$pending): ?>
      <p class="text-sm text-stone-400">No pending requests. 🎉</p>
    <?php else: foreach ($pending as $p): $isWarn = ($warnId === (int)$p['id']); ?>
      <div class="border <?= $isWarn ? 'border-red-400 bg-red-50' : 'border-stone-200' ?> rounded-lg p-3 mb-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="text-sm text-stone-700">
            <div class="font-semibold">
              <?= htmlspecialchars($p['customer_name']) ?>
              <?php if ((int)$p['is_exclusive'] === 1): ?><span class="ml-1 text-[10px] font-bold text-red-600 uppercase">Private</span><?php endif; ?>
            </div>
            <div class="text-stone-500"><?= htmlspecialchars($p['label']) ?> · <?= (int)$p['party_size'] ?> ppl</div>
            <div class="text-stone-500"><?= htmlspecialchars(booking_fmt_date($p['booking_date'])) ?> · <?= htmlspecialchars(booking_fmt_time($p['start_time'])) ?>–<?= htmlspecialchars(booking_fmt_time($p['end_time'])) ?></div>
            <div class="text-stone-400 text-xs mt-1"><?= htmlspecialchars($p['phone'] ?: '—') ?> · <?= htmlspecialchars($p['email'] ?: '—') ?></div>
            <?php if (!empty($p['notes'])): ?><div class="text-stone-600 text-xs mt-1 italic">“<?= htmlspecialchars($p['notes']) ?>”</div><?php endif; ?>
            <div class="mt-1.5"><?= $payBadge($p) ?><?= $tixBreakdown($p) ?></div>
          </div>
          <div class="flex flex-wrap gap-2 items-center">
            <?php if ($isWarn): ?>
              <span class="text-xs text-red-700 font-semibold w-full">⚠ Capacity or scheduling conflict — review the day, then:</span>
              <?php $act('confirm_force', (int)$p['id'], 'Confirm anyway', $btn . ' bg-red-600 text-white hover:bg-red-700', 'Override the capacity check and confirm this booking?'); ?>
            <?php else: ?>
              <?php $act('confirm', (int)$p['id'], 'Confirm', $btn . ' bg-green-600 text-white hover:bg-green-700'); ?>
            <?php endif; ?>
            <?php if (($p['payment_status'] ?? '') === 'pending'): ?>
              <?php $act('waive', (int)$p['id'], 'Mark paid (cash)', $btn . ' bg-teal-50 text-teal-700 hover:bg-teal-100', 'Mark this booking as paid in cash/in-person?'); ?>
            <?php endif; ?>
            <a href="/admin/admin.php?view=bookings&day=<?= urlencode($p['booking_date']) ?>" class="<?= $btn ?> bg-stone-100 text-stone-600 hover:bg-stone-200">View day</a>
            <?php $act('cancel', (int)$p['id'], 'Decline', $btn . ' bg-stone-100 text-stone-500 hover:bg-stone-200', 'Decline and cancel this request?'); ?>
          </div>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Day navigator + occupancy -->
  <div class="bg-white border border-stone-200 rounded-lg p-5 mb-8 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <h2 class="font-semibold text-stone-700"><?= htmlspecialchars(date('l, F j, Y', strtotime($day))) ?></h2>
      <div class="flex items-center gap-2">
        <a href="/admin/admin.php?view=bookings&day=<?= $prev ?>" class="<?= $btn ?> bg-stone-100 text-stone-600 hover:bg-stone-200">← Prev</a>
        <a href="/admin/admin.php?view=bookings&day=<?= date('Y-m-d') ?>" class="<?= $btn ?> bg-stone-100 text-stone-600 hover:bg-stone-200">Today</a>
        <a href="/admin/admin.php?view=bookings&day=<?= $next ?>" class="<?= $btn ?> bg-stone-100 text-stone-600 hover:bg-stone-200">Next →</a>
        <form method="GET" action="/admin/admin.php" class="inline-flex items-center gap-1">
          <input type="hidden" name="view" value="bookings">
          <input type="date" name="day" value="<?= htmlspecialchars($day, ENT_QUOTES) ?>" class="p-1.5 border border-stone-300 rounded bg-stone-50 text-xs">
          <button type="submit" class="<?= $btn ?> bg-amber-600 text-white hover:bg-amber-700">Go</button>
        </form>
      </div>
    </div>

    <!-- hourly occupancy bar -->
    <div class="flex gap-1 items-end">
      <?php for ($h = $openH; $h < $closeH; $h++):
          $hs = sprintf('%02d:00:00', $h); $he = sprintf('%02d:00:00', $h + 1);
          $sum = 0; $excl = false;
          foreach ($confirmed as $c) {
              if ($overlaps($hs, $he, $c['start_time'], $c['end_time'])) {
                  if ((int)$c['is_exclusive'] === 1) $excl = true; else $sum += (int)$c['party_size'];
              }
          }
          $ratio = $cap > 0 ? $sum / $cap : 0;
          $barH  = max(4, (int)round($ratio * 64));
          if ($excl)            { $color = 'bg-red-600';   $txt = 'PVT'; }
          elseif ($ratio >= 0.9){ $color = 'bg-red-500';   $txt = $sum; }
          elseif ($ratio >= 0.6){ $color = 'bg-amber-500'; $txt = $sum; }
          elseif ($sum > 0)     { $color = 'bg-green-500'; $txt = $sum; }
          else                  { $color = 'bg-stone-200'; $txt = '0'; }
      ?>
        <div class="flex-1 flex flex-col items-center">
          <div class="text-[10px] text-stone-500 mb-0.5"><?= htmlspecialchars((string)$txt) ?></div>
          <div class="w-full <?= $color ?> rounded-t" style="height:<?= $barH ?>px" title="<?= $hourLabel($h) ?>: <?= $excl ? 'private event' : $sum . '/' . $cap ?>"></div>
          <div class="text-[10px] text-stone-400 mt-1"><?= $hourLabel($h) ?></div>
        </div>
      <?php endfor; ?>
    </div>
    <p class="text-[11px] text-stone-400 mt-2">Peak concurrent guests per hour vs. cap of <?= (int)$cap ?>. Red = ≥90% or a private event blocks the grounds.</p>
  </div>

  <!-- Day schedule -->
  <div class="bg-white border border-stone-200 rounded-lg p-5 mb-8 shadow-sm">
    <h2 class="font-semibold text-stone-700 mb-4">Schedule</h2>
    <?php if (!$daySched): ?>
      <p class="text-sm text-stone-400">Nothing booked for this day.</p>
    <?php else: foreach ($daySched as $r): ?>
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-100 py-2 text-sm">
        <div class="text-stone-700">
          <span class="font-mono text-stone-500"><?= htmlspecialchars(booking_fmt_time($r['start_time'])) ?></span>
          <span class="font-semibold ml-2"><?= htmlspecialchars($r['customer_name']) ?></span>
          <span class="text-stone-400">· <?= htmlspecialchars($r['label']) ?> · <?= (int)$r['party_size'] ?> ppl</span>
          <?= $statusBadge($r['status']) ?>
          <?= $payBadge($r) ?><?= $tixBreakdown($r) ?>
          <?php if (!empty($r['admin_note'])): ?><span class="text-stone-400 italic text-xs">— <?= htmlspecialchars($r['admin_note']) ?></span><?php endif; ?>
        </div>
        <div class="flex flex-wrap gap-2">
          <?php
            if ($r['status'] === 'pending')   $act('confirm', (int)$r['id'], 'Confirm', $btn . ' bg-green-600 text-white hover:bg-green-700');
            if ($r['status'] === 'confirmed') {
                $act('complete', (int)$r['id'], 'Completed', $btn . ' bg-stone-100 text-stone-600 hover:bg-stone-200');
                $act('no_show',  (int)$r['id'], 'No-show',  $btn . ' bg-red-50 text-red-600 hover:bg-red-100');
            }
            if (in_array($r['status'], ['pending', 'confirmed'], true))
                $act('cancel', (int)$r['id'], 'Cancel', $btn . ' bg-stone-100 text-stone-500 hover:bg-stone-200', 'Cancel this booking?');
            if (($r['payment_status'] ?? '') === 'pending')
                $act('waive', (int)$r['id'], 'Mark paid (cash)', $btn . ' bg-teal-50 text-teal-700 hover:bg-teal-100', 'Mark as paid in cash/in-person?');
            if (($r['payment_status'] ?? '') === 'paid' && ($r['payment_provider'] ?? '') === 'stripe' && !empty($r['payment_reference']))
                $act('refund', (int)$r['id'], 'Refund', $btn . ' bg-red-50 text-red-600 hover:bg-red-100', 'Issue a full Stripe refund of $' . number_format((float)($r['amount_total'] ?? 0), 2) . '? This cannot be undone.');
          ?>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Blackouts -->
  <div class="bg-white border border-stone-200 rounded-lg p-5 mb-8 shadow-sm">
    <h2 class="font-semibold text-stone-700 mb-4">🚫 Blackout dates</h2>
    <form method="POST" action="/admin/admin.php?view=bookings" class="flex flex-wrap items-end gap-3 mb-4">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
      <input type="hidden" name="bookings_action" value="blackout_add">
      <input type="hidden" name="day" value="<?= htmlspecialchars($day, ENT_QUOTES) ?>">
      <div>
        <label class="block text-xs font-semibold text-stone-600 mb-1">Date</label>
        <input type="date" name="d_date" required class="p-2 border border-stone-300 rounded bg-stone-50">
      </div>
      <div class="flex-1 min-w-[180px]">
        <label class="block text-xs font-semibold text-stone-600 mb-1">Reason (optional)</label>
        <input type="text" name="reason" maxlength="120" placeholder="Holiday, private buyout, weather…" class="w-full p-2 border border-stone-300 rounded bg-stone-50">
      </div>
      <button type="submit" class="<?= $btn ?> bg-amber-600 text-white hover:bg-amber-700">Add blackout</button>
    </form>
    <?php if (!$blackouts): ?>
      <p class="text-sm text-stone-400">No upcoming blackout dates.</p>
    <?php else: foreach ($blackouts as $bo): ?>
      <div class="flex items-center justify-between border-b border-stone-100 py-2 text-sm">
        <div class="text-stone-700"><span class="font-mono"><?= htmlspecialchars(date('D, M j Y', strtotime($bo['d_date']))) ?></span>
          <?php if (!empty($bo['reason'])): ?><span class="text-stone-400">— <?= htmlspecialchars($bo['reason']) ?></span><?php endif; ?>
        </div>
        <?php $act('blackout_remove', (int)$bo['id'], 'Remove', $btn . ' bg-stone-100 text-stone-500 hover:bg-stone-200'); ?>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- Booking Types -->
  <?php
    $allTypes = [];
    try { $allTypes = $pdo->query("SELECT * FROM booking_types ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
    $inp   = 'w-full p-2 border border-stone-200 rounded bg-stone-50 text-sm';
    $label = 'block text-xs font-semibold text-stone-500 mb-1';
  ?>
  <div class="bg-white border border-stone-200 rounded-lg p-5 mb-8 shadow-sm">
    <h2 class="font-semibold text-stone-700 mb-1">🎟 Booking types</h2>
    <p class="text-xs text-stone-400 mb-5">Configure what visitors can book, pricing, party limits, and online payment.</p>

    <?php foreach ($allTypes as $bt):
      $tid     = (int)$bt['id'];
      $model   = $bt['pricing_model'] ?? 'flat';
      $price   = (float)($bt['unit_price'] ?? 0);
      $reqPay  = !empty($bt['requires_payment']);
      $priceLabel = $price > 0
        ? ('$' . number_format($price, 2) . ($model === 'per_person' ? '/person' : ' flat'))
        : 'Free / quote';
    ?>
    <div class="border border-stone-200 rounded-lg mb-3 overflow-hidden">
      <!-- Summary row -->
      <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 bg-stone-50 cursor-pointer"
           onclick="var p=this.nextElementSibling;p.classList.toggle('hidden')">
        <div>
          <span class="font-semibold text-stone-800"><?= htmlspecialchars($bt['label']) ?></span>
          <span class="ml-2 font-mono text-stone-400 text-xs"><?= htmlspecialchars($bt['type_key']) ?></span>
          <?php if (!$bt['is_active']): ?><span class="ml-2 text-[10px] bg-stone-200 text-stone-500 px-2 py-0.5 rounded font-semibold uppercase">Inactive</span><?php endif; ?>
          <?php if ($reqPay): ?><span class="ml-2 text-[10px] bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded font-semibold">💳 requires payment</span><?php endif; ?>
        </div>
        <div class="flex items-center gap-4 text-xs text-stone-500">
          <span><?= htmlspecialchars($priceLabel) ?></span>
          <span><?= (int)$bt['min_party'] ?>–<?= (int)$bt['max_party'] ?> ppl</span>
          <span><?= (int)$bt['duration_min'] ?> min</span>
          <span class="text-stone-300">▼ edit</span>
        </div>
      </div>

      <!-- Inline edit form (hidden by default) -->
      <div class="hidden">
        <form method="POST" action="/admin/admin.php?view=bookings" class="p-4 grid grid-cols-2 gap-4 md:grid-cols-4">
          <input type="hidden" name="csrf_token"       value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
          <input type="hidden" name="bookings_action"  value="booking_type_save">
          <input type="hidden" name="bt_id"            value="<?= $tid ?>">
          <input type="hidden" name="day"              value="<?= htmlspecialchars($day, ENT_QUOTES) ?>">

          <div class="col-span-2">
            <label class="<?= $label ?>">Label (shown to visitors)</label>
            <input type="text" name="bt[label]" value="<?= htmlspecialchars($bt['label'], ENT_QUOTES) ?>" required class="<?= $inp ?>">
          </div>
          <div>
            <label class="<?= $label ?>">Type key (URL slug, no spaces)</label>
            <input type="text" name="bt[type_key]" value="<?= htmlspecialchars($bt['type_key'], ENT_QUOTES) ?>" required pattern="[a-z0-9_\-]+" class="<?= $inp ?> font-mono">
          </div>
          <div>
            <label class="<?= $label ?>">Sort order</label>
            <input type="number" name="bt[sort_order]" value="<?= (int)$bt['sort_order'] ?>" min="0" class="<?= $inp ?>">
          </div>

          <!-- Pricing -->
          <div>
            <label class="<?= $label ?>">Pricing model</label>
            <select name="bt[pricing_model]" class="<?= $inp ?>">
              <option value="flat"       <?= $model === 'flat'       ? 'selected' : '' ?>>Flat rate (whole booking)</option>
              <option value="per_person" <?= $model === 'per_person' ? 'selected' : '' ?>>Per person × headcount</option>
            </select>
          </div>
          <div>
            <label class="<?= $label ?>">Price ($) — 0 = free / quote</label>
            <input type="number" name="bt[unit_price]" value="<?= number_format($price, 2) ?>" min="0" step="0.01" class="<?= $inp ?>">
            <?php if ($model === 'per_person'): ?>
            <p class="text-[10px] text-stone-400 mt-1 leading-snug">Per-person bookings use <strong>Admissions inventory tiers</strong> (Adult / Child / Under 3). Set prices via admin → Schema → inventory → Admissions category. This price field is ignored for per-person types.</p>
            <?php endif; ?>
          </div>

          <!-- Party -->
          <div>
            <label class="<?= $label ?>">Min party size</label>
            <input type="number" name="bt[min_party]" value="<?= (int)$bt['min_party'] ?>" min="1" class="<?= $inp ?>">
          </div>
          <div>
            <label class="<?= $label ?>">Max party size</label>
            <input type="number" name="bt[max_party]" value="<?= (int)$bt['max_party'] ?>" min="1" class="<?= $inp ?>">
          </div>

          <!-- Time -->
          <div>
            <label class="<?= $label ?>">Lead time (hours advance notice)</label>
            <input type="number" name="bt[lead_time_hours]" value="<?= (int)$bt['lead_time_hours'] ?>" min="0" class="<?= $inp ?>">
          </div>
          <div>
            <label class="<?= $label ?>">Duration (minutes)</label>
            <input type="number" name="bt[duration_min]" value="<?= (int)$bt['duration_min'] ?>" min="15" step="15" class="<?= $inp ?>">
          </div>

          <!-- Flags -->
          <div class="col-span-2 md:col-span-4 flex flex-wrap gap-6 text-sm text-stone-700">
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" name="bt[requires_payment]" value="1" <?= $reqPay ? 'checked' : '' ?> class="w-4 h-4 accent-amber-600">
              <span><strong>Requires online payment</strong> — sends to checkout</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" name="bt[is_exclusive]" value="1" <?= !empty($bt['is_exclusive']) ? 'checked' : '' ?> class="w-4 h-4 accent-amber-600">
              <span>Exclusive / private (blocks all other bookings that slot)</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" name="bt[self_serve]" value="1" <?= !empty($bt['self_serve']) ? 'checked' : '' ?> class="w-4 h-4 accent-amber-600">
              <span>Self-serve (instant request, no quote workflow)</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" name="bt[is_active]" value="1" <?= !empty($bt['is_active']) ? 'checked' : '' ?> class="w-4 h-4 accent-amber-600">
              <span>Active (visible on booking form)</span>
            </label>
          </div>

          <div class="col-span-2 md:col-span-4 flex gap-3">
            <button type="submit" class="<?= $btn ?> bg-amber-600 text-white hover:bg-amber-700">Save type</button>
            <button type="button" onclick="this.closest('.hidden').classList.add('hidden')"
                    class="<?= $btn ?> bg-stone-100 text-stone-600 hover:bg-stone-200">Cancel</button>
          </div>
        </form>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Add new type -->
    <details class="border border-dashed border-stone-300 rounded-lg mt-4">
      <summary class="px-4 py-3 cursor-pointer text-sm font-semibold text-stone-600 select-none">+ Add a new booking type</summary>
      <form method="POST" action="/admin/admin.php?view=bookings" class="p-4 grid grid-cols-2 gap-4 md:grid-cols-4">
        <input type="hidden" name="csrf_token"      value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="bookings_action" value="booking_type_new">
        <input type="hidden" name="day"             value="<?= htmlspecialchars($day, ENT_QUOTES) ?>">

        <div class="col-span-2">
          <label class="<?= $label ?>">Label (shown to visitors) *</label>
          <input type="text" name="bt[label]" required placeholder="e.g. Petting Zoo Admission" class="<?= $inp ?>">
        </div>
        <div>
          <label class="<?= $label ?>">Type key (unique slug) *</label>
          <input type="text" name="bt[type_key]" required pattern="[a-z0-9_\-]+" placeholder="e.g. petting_zoo" class="<?= $inp ?> font-mono">
        </div>
        <div>
          <label class="<?= $label ?>">Sort order</label>
          <input type="number" name="bt[sort_order]" value="10" min="0" class="<?= $inp ?>">
        </div>

        <div>
          <label class="<?= $label ?>">Pricing model</label>
          <select name="bt[pricing_model]" class="<?= $inp ?>">
            <option value="flat">Flat rate</option>
            <option value="per_person">Per person</option>
          </select>
        </div>
        <div>
          <label class="<?= $label ?>">Price ($)</label>
          <input type="number" name="bt[unit_price]" value="0.00" min="0" step="0.01" class="<?= $inp ?>">
        </div>
        <div>
          <label class="<?= $label ?>">Min party</label>
          <input type="number" name="bt[min_party]" value="1" min="1" class="<?= $inp ?>">
        </div>
        <div>
          <label class="<?= $label ?>">Max party</label>
          <input type="number" name="bt[max_party]" value="25" min="1" class="<?= $inp ?>">
        </div>
        <div>
          <label class="<?= $label ?>">Lead time (hrs)</label>
          <input type="number" name="bt[lead_time_hours]" value="24" min="0" class="<?= $inp ?>">
        </div>
        <div>
          <label class="<?= $label ?>">Duration (min)</label>
          <input type="number" name="bt[duration_min]" value="60" min="15" step="15" class="<?= $inp ?>">
        </div>

        <div class="col-span-2 md:col-span-4 flex flex-wrap gap-6 text-sm text-stone-700">
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" name="bt[requires_payment]" value="1" class="w-4 h-4 accent-amber-600">
            <span>Requires online payment</span>
          </label>
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" name="bt[is_exclusive]" value="1" class="w-4 h-4 accent-amber-600">
            <span>Exclusive / private</span>
          </label>
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" name="bt[self_serve]" value="1" checked class="w-4 h-4 accent-amber-600">
            <span>Self-serve</span>
          </label>
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" name="bt[is_active]" value="1" checked class="w-4 h-4 accent-amber-600">
            <span>Active</span>
          </label>
        </div>

        <div class="col-span-2 md:col-span-4">
          <button type="submit" class="<?= $btn ?> bg-green-600 text-white hover:bg-green-700">Create booking type</button>
        </div>
      </form>
    </details>
  </div>

  <?php endif; ?>
</div>
