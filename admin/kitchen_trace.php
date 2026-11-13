<?php
// admin/kitchen_trace.php — Kitchen › Batches › Trace a lot (Farm OS Phase 4 slice 7, Nov 13, 2026; includes/kitchen_trace.php).
//   GET kitchen_trace.php                  find a lot by code; recent lots
//       kitchen_trace.php?lot=CODE         (or ?batch=N) where the lot went: sold, removed, left; who bought it
//       kitchen_trace.php?batch=N&csv=1    the people to contact, as a spreadsheet (needs Customers view; audited)
// Nothing is changed here. Customer names, emails and phones show only to roles that can view Customers.
// Permission: table 'recipes' (view).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'recipes']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/kitchen_trace.php';
require_once __DIR__ . '/../includes/admin_shell.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$role = (string)($_SESSION['user_role'] ?? '');
$canContact = hasViewAccess($role, 'customers');

if (!bcrf_kitchen_ready($pdo) || !bcrf_kitchen_versions_ready($pdo) || !bcrf_kitchen_trace_ready($pdo)) {
    bcrf_shell_open('Trace a lot');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Trace a lot</h1>', bcrf_shell_notice('warning', 'Run migrations 0050, 0051 and 0053 in Settings › Database first.');
    bcrf_shell_close(); exit;
}

$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$day  = fn($d) => $d ? date('M j, Y', strtotime($d)) : '—';
$when = function (?string $utc): string {
    if (!$utc) return '—';
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    return $d->setTimezone(new DateTimeZone('America/New_York'))->format('M j, Y g:i A');
};
$q = fn($m) => bcrf_kitchen_q(bcrf_qf((int)$m));

$lotIn = trim((string)($_GET['lot'] ?? ''));
$bid = (int)($_GET['batch'] ?? 0);
$error = '';
if (!$bid && $lotIn !== '') {
    $f = bcrf_kitchen_lot_find($pdo, $lotIn);
    if ($f) { header('Location: kitchen_trace.php?batch=' . (int)$f['id']); exit; }
    $error = 'No lot ' . $lotIn . '. Check the code on the label (like 261113-JAM-1).';
}

// ── CSV of people to contact ──
if ($bid && ($_GET['csv'] ?? '') !== '') {
    if (!$canContact) { http_response_code(403); exit('You need Customers access to download contact details.'); }
    try { $t = bcrf_kitchen_lot_trace($pdo, $bid); } catch (BcrfKitchenError $e) { http_response_code(404); exit($e->getMessage()); }
    $rows = bcrf_kitchen_trace_contacts($t);
    if (function_exists('bcrf_audit_event') && function_exists('bcrf_audit_actor')) {
        [$type, $auid, $aname, $ip] = bcrf_audit_actor();
        bcrf_audit_event($pdo, ['actor_type' => $type, 'actor_id' => $auid, 'actor_label' => $aname, 'ip' => $ip, 'action' => 'export', 'object_type' => 'report',
                                'object_id' => null, 'object_label' => 'Recall contacts for lot ' . $t['batch']['lot_code'] . ' (' . count($rows) . ' rows)']);
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="lot-' . preg_replace('/[^A-Za-z0-9._-]/', '-', $t['batch']['lot_code']) . '-contacts.csv"');
    header('Cache-Control: no-store');
    $f = fopen('php://output', 'w');
    fputcsv($f, ['List', 'Name', 'Email', 'Phone', 'Paid as', 'Sales', 'Units']);
    foreach ($rows as $r) {
        $line = [$r['list'] === 'likely' ? 'Likely from this lot' : 'Bought while it was on the shelf', $r['name'] !== '' ? $r['name'] : 'No customer on the sale',
                 $r['email'], $r['phone'], $r['payer'], implode(' ', $r['sales']), bcrf_kitchen_q($r['qty'])];
        fputcsv($f, array_map(fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false && !is_numeric($v) ? "'" . $v : $v, $line));
    }
    fclose($f);
    exit;
}

// ── One lot ──
if ($bid) {
    try { $t = bcrf_kitchen_lot_trace($pdo, $bid); }
    catch (BcrfKitchenError $e) { bcrf_shell_open('Trace a lot'); echo bcrf_shell_notice('err', $e->getMessage()); bcrf_shell_close(); exit; }
    $b = $t['batch']; $lot = $t['lot'];
    $who = function (array $r) use ($h, $canContact): string {
        if (!$r['customer_id']) return '<span class="text-stone-500">No customer on the sale</span>' . ($r['payer'] !== '' && $canContact ? '<br><span class="text-xs">Paid as ' . $h($r['payer']) . '</span>' : '');
        if (!$canContact) return '<span class="text-stone-500">Customer on file (needs Customers access to see)</span>';
        $s = '<a class="underline text-amber-800" href="customers.php?id=' . (int)$r['customer_id'] . '">' . $h($r['name'] !== '' ? $r['name'] : 'Customer #' . $r['customer_id']) . '</a>';
        $bits = [];
        if ($r['email'] !== '') $bits[] = '<a class="underline" href="mailto:' . $h($r['email']) . '">' . $h($r['email']) . '</a>';
        if ($r['phone'] !== '') $bits[] = '<a class="underline" href="tel:' . $h(preg_replace('/[^0-9+]/', '', $r['phone'])) . '">' . $h($r['phone']) . '</a>';
        return $s . ($bits ? '<br><span class="text-xs">' . implode(' · ', $bits) . '</span>' : '');
    };
    $list = function (array $rows, string $empty) use ($h, $when, $who): void {
        if (!$rows) { echo '<p class="text-sm text-stone-500">', $h($empty), '</p>'; return; }
        echo '<ul class="divide-y divide-stone-100 text-sm">';
        foreach ($rows as $r) {
            echo '<li class="py-2 flex flex-wrap justify-between gap-2"><span><strong>', $h($r['number']), '</strong> <span class="text-xs text-stone-500">', $h($when($r['at'])),
                 $r['status'] === 'refunded' || $r['status'] === 'partially_refunded' ? ' · ' . $h(str_replace('_', ' ', $r['status'])) : '', '</span><br>', $who($r), '</span>',
                 '<span class="text-right">', $h(bcrf_kitchen_q($r['qty'])), ' unit', (float)$r['qty'] == 1 ? '' : 's', '</span></li>';
        }
        echo '</ul>';
    };
    bcrf_shell_open('Trace lot ' . $b['lot_code'], ['width' => 'max-w-3xl']);
    echo '<p class="text-sm mb-2"><a class="underline text-amber-800" href="kitchen_trace.php">← Trace another lot</a> · <a class="underline text-amber-800" href="kitchen_batches.php?id=', (int)$b['id'], '">Batch record</a></p>';
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">Lot ', $h($b['lot_code']), '</h1>';
    echo '<p class="text-sm text-stone-500 mb-4">', $h(bcrf_kitchen_q($b['units_made'])), ' × ', $h($b['product_name']), ' · made ', $h($day($b['made_on'])), ' · best by ', $h($day($b['best_by'])), '</p>';
    if ($b['voided_at']) echo bcrf_shell_notice('warning', 'This batch was voided: ' . $b['void_reason'] . '.');
    if ($t['note'] !== '') echo bcrf_shell_notice('warning', $t['note']);
    $contacts = bcrf_kitchen_trace_contacts($t);
    ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Where it went</h2>
    <?php if ($lot): $removed = array_sum($lot['removed']); ?>
    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
      <div><dt class="text-stone-500 text-xs">Made</dt><dd class="font-semibold"><?= $h($q($lot['in'])) ?></dd></div>
      <div><dt class="text-stone-500 text-xs">Sold (likely)</dt><dd class="font-semibold"><?= $h($q($lot['sold'])) ?></dd></div>
      <div><dt class="text-stone-500 text-xs">Taken off otherwise</dt><dd class="font-semibold"><?= $h($q($removed)) ?></dd></div>
      <div><dt class="text-stone-500 text-xs">Still on hand</dt><dd class="font-semibold <?= $lot['left'] > 0 ? 'text-red-700' : '' ?>"><?= $h($q(max(0, $lot['left']))) ?></dd></div>
    </dl>
    <?php if ($lot['removed']): ?><p class="text-xs text-stone-500 mt-2"><?= $h(implode(' · ', array_map(fn($r, $n) => bcrf_stock_reason_label($r) . ' ' . $q($n), array_keys($lot['removed']), $lot['removed']))) ?></p><?php endif; ?>
    <p class="text-xs text-stone-500 mt-2">On the shelf <?= $h($when($t['from'])) ?> to <?= $lot['end'] ? $h($when($lot['end'])) : 'now' ?>.<?= $lot['left'] > 0 ? ' For a recall, pull what is left: check every location on the Stock page.' : '' ?></p>
    <?php else: ?>
    <p class="text-sm">Sales checked from <?= $h($when($t['from'])) ?> to <?= $h($when($t['to'])) ?>.</p>
    <?php endif; ?>
  </div>

  <div class="<?= $card ?>">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
      <h2 class="text-base font-semibold text-stone-800"><?= count($contacts) ?> <?= count($contacts) === 1 ? 'sale or customer' : 'sales or customers' ?> to contact in a recall</h2>
      <?php if ($canContact && $contacts): ?><a class="<?= $btnSec ?>" href="kitchen_trace.php?batch=<?= (int)$b['id'] ?>&amp;csv=1">Download contacts (CSV)</a><?php endif; ?>
    </div>
    <p class="text-xs text-stone-500"><?= $t['mode'] === 'fifo' ? 'The oldest units on the shelf are assumed to sell first, so "likely" is a best guess. For a recall, contact both lists.' : 'For a recall, contact everyone listed.' ?> Sales with no customer can't be traced to a person: post a notice at the farmstand and online too.</p>
  </div>

  <?php if ($t['mode'] === 'fifo'): ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Likely from this lot (<?= count($t['likely']) ?>)</h2>
    <?php $list($t['likely'], 'No sales took units from this lot yet.'); ?>
  </div>
  <?php endif; ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2"><?= $t['mode'] === 'fifo' ? 'Also bought while this lot was on the shelf' : 'Bought while this lot could have been on the shelf' ?> (<?= count($t['possible']) ?>)</h2>
    <?php $list($t['possible'], $t['mode'] === 'fifo' ? 'No other sales of this product while the lot was on the shelf.' : 'No sales of this product in that time.'); ?>
  </div>
<?php
    bcrf_shell_close(); exit;
}

// ── Find a lot ──
$recent = bcrf_kitchen_batches($pdo, ['limit' => 15]);
bcrf_shell_open('Trace a lot', ['width' => 'max-w-3xl']);
?>
  <h1 class="text-2xl font-bold text-stone-800 mb-1">Trace a lot</h1>
  <p class="text-sm text-stone-600 mb-4">For a recall or a customer complaint: type the lot code from the label to see who bought it and how much is still on hand.</p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>
  <form method="GET" class="<?= $card ?> flex flex-wrap gap-2 items-end">
    <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="lot">Lot code</label><input id="lot" name="lot" value="<?= $h($lotIn) ?>" class="<?= $input ?>" placeholder="261113-JAM-1" autocomplete="off"></div>
    <button class="<?= $btnMain ?>">Trace</button>
  </form>
  <?php if ($recent): ?>
  <h2 class="text-base font-semibold text-stone-800 mb-2">Recent lots</h2>
  <ul class="bg-white border border-stone-200 rounded-lg divide-y divide-stone-100 shadow-sm">
    <?php foreach ($recent as $r): ?>
    <li><a class="flex flex-wrap justify-between gap-2 px-4 py-3 hover:bg-stone-50" href="kitchen_trace.php?batch=<?= (int)$r['id'] ?>">
      <span><span class="font-semibold text-stone-800"><?= $h($r['lot_code']) ?></span> <span class="text-xs text-stone-500"><?= $h($r['product_name']) ?></span></span>
      <span class="text-xs text-stone-500"><?= $r['voided_at'] ? 'Voided · ' : '' ?>made <?= $h($day($r['made_on'])) ?></span></a></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
<?php
bcrf_shell_close();
