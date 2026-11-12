<?php
// admin/quotes.php — quotes and payments for group bookings and parties (Farm OS Phase 3 slice 4, Nov 12, 2026;
// includes/event_quotes.php; 07 › Bookings and Private events).
//   GET  quotes.php[?kind=party|booking&show=open|all]   upcoming bookings and parties with where the money stands
//   GET  quotes.php?for=booking:12 | event_inquiry:3      one quote: price lines, deposit, send, payments
//   POST quotes.php  act=create | save | send | mark_sent | pay | void | refund
// Permission: Bookings (view bookings) for bookings, Private Events (view event_inquiries) for parties.
// Removing a payment typed by mistake and refunds need an Owner or Manager.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['view', 'bookings'], ['view', 'event_inquiries']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/event_quotes.php';
require_once __DIR__ . '/../includes/admin_shell.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$role = (string)($_SESSION['user_role'] ?? '');
$mgr = bcrf_quote_is_manager();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$m = fn($c) => bcrf_quote_money((int)$c);
$can = function (string $type) use ($role): bool {
    if ($role === 'administrator') return true;
    return function_exists('hasViewAccess') && hasViewAccess($role, $type === 'booking' ? 'bookings' : 'event_inquiries');
};
$local = function (?string $utc, string $fmt = 'n/j/Y g:i A'): string {
    if (!$utc) return '';
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format($fmt);
};

if (!bcrf_quotes_ready($pdo)) {
    bcrf_shell_open('Quotes & payments');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Quotes &amp; payments</h1>',
         bcrf_shell_notice('warning', 'Run migration 0032 in Settings › Database first.'),
         '<a href="/admin/migrations.php" class="text-sm text-amber-800 underline">Open Settings › Database</a>';
    bcrf_shell_close();
    exit;
}

// for=booking:12 / event_inquiry:3
$for = (string)($_REQUEST['for'] ?? '');
$type = ''; $sid = 0;
if (preg_match('/^(booking|event_inquiry):(\d+)$/', $for, $mm)) { $type = $mm[1]; $sid = (int)$mm[2]; }

// ── Actions ──
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $back = 'quotes.php?for=' . rawurlencode($type . ':' . $sid);
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['q_flash'] = ['err', 'Security token expired; try again.'];
        header('Location: ' . $back); exit;
    }
    $act = (string)($_POST['act'] ?? '');
    try {
        if (!$type || !$can($type)) throw new BcrfQuoteError('You don\'t have access to that.');
        $q = bcrf_quote_get($pdo, $type, $sid);
        if ($act === 'create') {
            bcrf_quote_create($pdo, $type, $sid, $uid);
            $_SESSION['q_flash'] = ['ok', 'Quote started. Check the price, then save.'];
        } else {
            if (!$q) throw new BcrfQuoteError('Start the quote first.');
            if ($act === 'save') {
                $dep = bcrf_quote_parse_cents($_POST['deposit'] ?? '');
                if ($dep === null && trim((string)($_POST['deposit'] ?? '')) !== '') throw new BcrfQuoteError('The deposit must be an amount like 50 or 25.00, or blank for none.');
                bcrf_quote_save($pdo, $q['id'], [
                    'lines' => bcrf_quote_clean_lines((array)($_POST['label'] ?? []), (array)($_POST['qty'] ?? []), (array)($_POST['unit'] ?? [])),
                    'deposit' => $dep ?? 0, 'deposit_due' => (string)($_POST['deposit_due'] ?? ''),
                    'balance_when' => (string)($_POST['balance_when'] ?? 'arrival'), 'pay_online' => !empty($_POST['pay_online']),
                    'customer_note' => (string)($_POST['customer_note'] ?? ''),
                ], $uid);
                $_SESSION['q_flash'] = ['ok', 'Quote saved.' . ($q['sent_at'] ? ' The customer\'s link shows the new price; send it again if they should hear about it.' : '')];
            } elseif ($act === 'send') {
                $to = bcrf_quote_email($pdo, $q, $uid);
                $_SESSION['q_flash'] = ['ok', 'Quote emailed to ' . $to . '.'];
            } elseif ($act === 'mark_sent') {
                bcrf_quote_mark_sent($pdo, $q, null, $uid, 'marked sent (link sent by hand)');
                $_SESSION['q_flash'] = ['ok', 'Marked as sent.'];
            } elseif ($act === 'pay') {
                $cents = bcrf_quote_parse_cents($_POST['amount'] ?? '');
                if ($cents === null) throw new BcrfQuoteError('Enter the amount they paid, like 140 or 25.50.');
                bcrf_quote_record_payment($pdo, $q['id'], (string)($_POST['method'] ?? ''), $cents, (string)($_POST['reference'] ?? ''), (string)($_POST['note'] ?? ''), $uid);
                $_SESSION['q_flash'] = ['ok', 'Payment of ' . $m($cents) . ' recorded.'];
            } elseif ($act === 'void') {
                if (!$mgr) throw new BcrfQuoteError('Only an Owner or Manager can remove a payment.');
                bcrf_quote_void_payment($pdo, (int)($_POST['payment_id'] ?? 0), (string)($_POST['reason'] ?? ''), $uid);
                $_SESSION['q_flash'] = ['ok', 'Removed.'];
            } elseif ($act === 'refund') {
                if (!$mgr) throw new BcrfQuoteError('Only an Owner or Manager can refund.');
                $cents = bcrf_quote_parse_cents($_POST['amount'] ?? '');
                if ($cents === null) throw new BcrfQuoteError('Enter the amount to refund.');
                $pid = (int)($_POST['payment_id'] ?? 0);
                $st = $pdo->prepare("SELECT quote_id, method FROM event_payments WHERE id = ?");
                $st->execute([$pid]);
                $pr = $st->fetch(PDO::FETCH_ASSOC);
                if (!$pr || (int)$pr['quote_id'] !== $q['id']) throw new BcrfQuoteError('Pick a payment on this quote.');
                bcrf_quote_refund($pdo, $pid, $cents, (string)($_POST['reason'] ?? ''), $uid);
                $_SESSION['q_flash'] = ['ok', $m($cents) . ($pr['method'] === 'stripe' ? ' refunded through Stripe (it shows on their card in 5–10 days).' : ' refund recorded. Hand the money back the same way they paid.')];
            } else {
                throw new BcrfQuoteError('Unknown action.');
            }
        }
    } catch (BcrfQuoteError $e) {
        $_SESSION['q_flash'] = ['err', $e->getMessage()];
        if ($act === 'save') $_SESSION['q_form'] = $_POST;
    } catch (Throwable $e) {
        error_log('[quotes] ' . $act . ': ' . $e->getMessage());
        $_SESSION['q_flash'] = ['err', $act === 'refund' ? 'The refund didn\'t go through: ' . $e->getMessage() : 'Could not save. Try again.'];
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['q_flash'] ?? null; unset($_SESSION['q_flash']);
$typed = $_SESSION['q_form'] ?? null; unset($_SESSION['q_form']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';

$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100';
$btnDanger = 'px-3 py-2 rounded text-sm border border-red-300 text-red-700 hover:bg-red-50';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$stageChip = function (string $stage, string $text) use ($h): string {
    $c = ['paid' => 'bg-emerald-50 text-emerald-800', 'deposit_due' => 'bg-amber-50 text-amber-800', 'balance_due' => 'bg-sky-50 text-sky-800',
          'overpaid' => 'bg-red-50 text-red-700', 'draft' => 'bg-stone-100 text-stone-600', 'no_price' => 'bg-stone-100 text-stone-600',
          'none' => 'bg-stone-100 text-stone-500'][$stage] ?? 'bg-stone-100 text-stone-600';
    return '<span class="inline-block text-xs font-semibold px-2 py-1 rounded ' . $c . '">' . $h($text) . '</span>';
};

// ── ONE QUOTE ──
if ($type) {
    if (!$can($type)) { http_response_code(403); echo 'Access denied.'; exit; }
    $s = bcrf_quote_subject($pdo, $type, $sid);
    $listUrl = 'quotes.php' . ($type === 'event_inquiry' ? '?kind=party' : '');
    if (!$s) {
        bcrf_shell_open('Quotes & payments', ['toast' => $toast]);
        echo '<a href="' . $h($listUrl) . '" class="inline-block text-sm text-stone-500 hover:text-stone-800 mb-2">← Quotes &amp; payments</a>', bcrf_shell_notice('err', 'That booking or party was not found.');
        bcrf_shell_close();
        exit;
    }
    $q = bcrf_quote_get($pdo, $type, $sid);
    $st = $q ? bcrf_quote_status($pdo, $q) : null;
    $pays = $q ? bcrf_quote_payments($pdo, $q['id']) : [];
    $back = $type === 'booking' ? '/admin/admin.php?view=bookings&day=' . rawurlencode($s['date'] ?: date('Y-m-d')) : '/admin/admin.php?view=event_inquiries&status=' . rawurlencode($s['status']);
    $kind = $s['is_party'] ? 'party' : 'booking';

    bcrf_shell_open($s['name'] . ' — Quotes & payments', ['toast' => $toast, 'width' => 'max-w-3xl', 'js' => ['quotes.js']]);
    ?>
  <a href="<?= $h($listUrl) ?>" class="inline-block text-sm text-stone-500 hover:text-stone-800 mb-2">← Quotes &amp; payments</a>
  <div class="flex flex-wrap items-start justify-between gap-2 mb-1">
    <h1 class="text-2xl font-bold text-stone-800"><?= $h($s['name']) ?></h1>
    <?= $q ? $stageChip($st['stage'], $st['label']) : $stageChip('none', 'No quote yet') ?>
  </div>
  <p class="text-sm text-stone-600 mb-1"><?= $h($s['what']) ?> · <?= $h(bcrf_quote_when($s)) ?> · <?= (int)$s['guests'] ?> guest<?= $s['guests'] === 1 ? '' : 's' ?>
    <?php if ($s['is_party'] && ($s['adults'] || $s['children'])): ?>(<?= (int)$s['adults'] ?> adult<?= $s['adults'] === 1 ? '' : 's' ?>, <?= (int)$s['children'] ?> child<?= $s['children'] === 1 ? '' : 'ren' ?>)<?php endif; ?></p>
  <p class="text-sm text-stone-500 mb-4"><?= $h(ucfirst($s['status'])) ?> · <?= $h($s['email'] ?: 'no email') ?><?= $s['phone'] ? ' · ' . $h($s['phone']) : '' ?> · <a class="underline" href="<?= $h($back) ?>">Open the <?= $kind ?></a></p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>
  <?php if (!$s['live']) echo bcrf_shell_notice('warning', 'This ' . $kind . ' is ' . $s['status'] . '. The customer can\'t pay online; refunds still work.'); ?>

  <?php if (!$q): ?>
    <div class="<?= $card ?>">
      <p class="text-sm text-stone-700 mb-3"><?= $s['is_party']
          ? 'Starts at ' . $h($m(bcrf_quote_party_price($pdo))) . ' a guest × ' . (int)$s['guests'] . ' guests, paid on arrival with no deposit. You can change it before sending.'
          : 'Starts from the booking type\'s price. You can change it, add a deposit and turn online payment on or off before sending.' ?></p>
      <form method="POST" action="quotes.php"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="for" value="<?= $h($type . ':' . $sid) ?>">
        <button name="act" value="create" class="<?= $btnMain ?>">Start the quote</button></form>
    </div>
  <?php else:
    $d = $typed ?: [];
    $lines = $q['lines'];
    if ($d) { $lines = []; foreach ((array)($d['label'] ?? []) as $i => $l) $lines[] = ['label' => $l, 'qty' => $d['qty'][$i] ?? '', 'unit' => $d['unit'][$i] ?? '']; }
    else { foreach ($lines as &$l) $l['unit'] = number_format($l['unit_cents'] / 100, 2, '.', ''); unset($l); }
    while (count($lines) < max(3, count($q['lines']) + 2)) $lines[] = ['label' => '', 'qty' => '', 'unit' => ''];
    $pay = bcrf_quote_payable($pdo, $q, $s, $st);
  ?>
    <div class="grid grid-cols-3 gap-2 mb-4">
      <div class="bg-white border border-stone-200 rounded-lg p-3"><div class="text-xs text-stone-500">Total</div><div class="text-lg font-bold text-stone-800"><?= $h($m($q['total_cents'])) ?></div></div>
      <div class="bg-white border border-stone-200 rounded-lg p-3"><div class="text-xs text-stone-500">Paid</div><div class="text-lg font-bold text-emerald-700"><?= $h($m($st['paid'])) ?></div></div>
      <div class="bg-white border border-stone-200 rounded-lg p-3"><div class="text-xs text-stone-500"><?= $st['balance'] < 0 ? 'Refund owed' : 'Left to pay' ?></div><div class="text-lg font-bold <?= $st['balance'] < 0 ? 'text-red-700' : 'text-stone-800' ?>"><?= $h($m(abs($st['balance']))) ?></div></div>
    </div>

    <form method="POST" action="quotes.php" class="<?= $card ?> space-y-3" id="q-editor">
      <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="for" value="<?= $h($type . ':' . $sid) ?>">
      <h2 class="text-base font-semibold text-stone-800">Price</h2>
      <?php foreach ($lines as $i => $l): ?>
      <div class="grid grid-cols-6 gap-2 items-end border-b border-stone-100 pb-2" data-q-line>
        <div class="col-span-6 md:col-span-3"><label for="ql<?= $i ?>" class="<?= $label ?>">Line <?= $i + 1 ?></label><input id="ql<?= $i ?>" name="label[]" maxlength="150" value="<?= $h($l['label']) ?>" class="<?= $input ?>" placeholder="<?= $i === 0 ? 'What it\'s for' : 'Add-on (optional)' ?>"></div>
        <div class="col-span-2 md:col-span-1"><label for="qq<?= $i ?>" class="<?= $label ?>">Qty</label><input id="qq<?= $i ?>" name="qty[]" inputmode="numeric" pattern="[0-9]*" value="<?= $h($l['qty']) ?>" class="<?= $input ?>" data-q-qty></div>
        <div class="col-span-2 md:col-span-1"><label for="qu<?= $i ?>" class="<?= $label ?>">Each ($)</label><input id="qu<?= $i ?>" name="unit[]" inputmode="decimal" value="<?= $h($l['unit']) ?>" class="<?= $input ?>" data-q-unit></div>
        <div class="col-span-2 md:col-span-1 text-right text-sm text-stone-700 pb-2" data-q-sum></div>
      </div>
      <?php endforeach; ?>
      <p class="text-right text-sm font-semibold text-stone-800">Total <span id="q-total"><?= $h($m($q['total_cents'])) ?></span></p>
      <div class="grid gap-3 sm:grid-cols-2">
        <div><label for="q_dep" class="<?= $label ?>">Deposit ($) <span class="font-normal text-stone-400">blank = none</span></label>
          <input id="q_dep" name="deposit" inputmode="decimal" value="<?= $h($d['deposit'] ?? ($q['deposit_cents'] ? number_format($q['deposit_cents'] / 100, 2, '.', '') : '')) ?>" class="<?= $input ?>"></div>
        <div><label for="q_due" class="<?= $label ?>">Deposit due by <span class="font-normal text-stone-400">optional</span></label>
          <input id="q_due" name="deposit_due" type="date" value="<?= $h($d['deposit_due'] ?? (string)$q['deposit_due']) ?>" class="<?= $input ?>"></div>
        <div><label for="q_when" class="<?= $label ?>">The rest is due</label>
          <select id="q_when" name="balance_when" class="<?= $input ?>">
            <?php $bw = $d['balance_when'] ?? $q['balance_when']; ?>
            <option value="arrival" <?= $bw === 'arrival' ? 'selected' : '' ?>>On arrival</option>
            <option value="before" <?= $bw === 'before' ? 'selected' : '' ?>>Before the day</option>
          </select></div>
        <label class="flex items-center gap-2 text-sm text-stone-700 sm:pt-6"><input type="checkbox" name="pay_online" value="1" <?= ($d ? !empty($d['pay_online']) : $q['pay_online']) ? 'checked' : '' ?>> Let them pay online (card, through Stripe)</label>
      </div>
      <div><label for="q_note" class="<?= $label ?>">Note to the customer <span class="font-normal text-stone-400">optional; shown on the quote</span></label>
        <textarea id="q_note" name="customer_note" rows="2" maxlength="1000" class="<?= $input ?>"><?= $h($d['customer_note'] ?? (string)$q['customer_note']) ?></textarea></div>
      <?php if ($s['is_party']): ?><p class="text-xs text-stone-500">Party terms (shown to the customer): $10 a guest, no deposit, paid in full on arrival, rain or shine, every child with an adult around the animals.</p><?php endif; ?>
      <button name="act" value="save" class="<?= $btnMain ?>">Save quote</button>
    </form>

    <div class="<?= $card ?>">
      <h2 class="text-base font-semibold text-stone-800 mb-1">Send</h2>
      <p class="text-sm text-stone-600 mb-3"><?= $q['sent_at'] ? 'Sent ' . $h($local($q['sent_at'])) . ($q['sent_count'] > 1 ? ' (' . $q['sent_count'] . ' times)' : '') . '.' : 'Not sent yet.' ?>
        <?= $q['viewed_at'] ? ' They opened it ' . $h($local($q['viewed_at'])) . '.' : '' ?>
        <?= $pay['balance'] > 0 ? ' The link lets them pay online.' : ($q['pay_online'] ? '' : ' Online payment is off, so the link only shows the price and terms.') ?></p>
      <div class="flex flex-wrap gap-2 mb-3">
        <form method="POST" action="quotes.php"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="for" value="<?= $h($type . ':' . $sid) ?>">
          <button name="act" value="send" class="<?= $btnMain ?>" data-confirm="Email this quote (<?= $h($m($q['total_cents'])) ?>) to <?= $h($s['email']) ?>?" <?= ($s['email'] && $s['live']) ? '' : 'disabled' ?>>Email the quote to <?= $h($s['email'] ?: '(no email)') ?></button></form>
        <form method="POST" action="quotes.php"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="for" value="<?= $h($type . ':' . $sid) ?>">
          <button name="act" value="mark_sent" class="<?= $btnSec ?>">I sent it myself</button></form>
      </div>
      <label for="q_link" class="<?= $label ?>">Customer's link</label>
      <div class="flex gap-2"><input id="q_link" readonly value="<?= $h(bcrf_quote_url($q)) ?>" class="<?= $input ?> font-mono text-xs"><button type="button" class="<?= $btnSec ?>" data-copy="q_link">Copy</button></div>
    </div>

    <div class="<?= $card ?>">
      <h2 class="text-base font-semibold text-stone-800 mb-2">Payments</h2>
      <?php if (!$pays): ?><p class="text-sm text-stone-500 mb-3">Nothing paid yet.</p><?php else: ?>
      <div class="divide-y divide-stone-100 mb-3">
        <?php foreach ($pays as $p):
          $void = (bool)$p['voided_at'];
          $mname = $p['method'] === 'stripe' ? 'Online (Stripe)' : (BCRF_QUOTE_METHODS[$p['method']] ?? $p['method']);
          $refundable = !$void && $p['kind'] === 'payment';
          if ($refundable) {
              $left = $p['amount_cents'];
              foreach ($pays as $r) if ((int)$r['refund_of'] === $p['id'] && !$r['voided_at']) $left += $r['amount_cents'];
              $refundable = $left > 0;
          }
        ?>
        <div class="py-2 text-sm <?= $void ? 'opacity-50' : '' ?>">
          <div class="flex flex-wrap justify-between gap-2">
            <span class="<?= $void ? 'line-through' : '' ?>"><strong class="<?= $p['amount_cents'] < 0 ? 'text-red-700' : 'text-stone-800' ?>"><?= $h($m($p['amount_cents'])) ?></strong>
              <?= $p['kind'] === 'refund' ? 'refund · ' : '' ?><?= $h($mname) ?><?= $p['reference'] && $p['method'] !== 'stripe' ? ' · ' . $h($p['reference']) : '' ?></span>
            <span class="text-xs text-stone-500"><?= $h($local($p['created_at'])) ?> · <?= $h($p['recorded_by'] ? ($p['recorded_name'] ?: 'Staff') : 'Stripe (automatic)') ?></span>
          </div>
          <?php if ($p['note']): ?><div class="text-xs text-stone-500"><?= $h($p['note']) ?></div><?php endif; ?>
          <?php if ($void): ?><div class="text-xs text-red-700">Removed: <?= $h($p['void_reason']) ?></div><?php endif; ?>
          <?php if ($mgr && ($refundable || (!$void && $p['method'] !== 'stripe'))): ?>
          <details class="mt-1"><summary class="text-xs text-stone-500 cursor-pointer">Refund or remove</summary>
            <div class="flex flex-wrap gap-3 mt-2">
            <?php if ($refundable): ?>
            <form method="POST" action="quotes.php" class="flex flex-wrap gap-2 items-end">
              <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="for" value="<?= $h($type . ':' . $sid) ?>"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
              <div><label class="<?= $label ?>" for="ra<?= (int)$p['id'] ?>">Refund ($)</label><input id="ra<?= (int)$p['id'] ?>" name="amount" inputmode="decimal" value="<?= $h(number_format($left / 100, 2, '.', '')) ?>" class="<?= $input ?> w-28"></div>
              <div><label class="<?= $label ?>" for="rr<?= (int)$p['id'] ?>">Why</label><input id="rr<?= (int)$p['id'] ?>" name="reason" maxlength="255" required class="<?= $input ?>" placeholder="e.g. cancelled 30+ days ahead"></div>
              <button name="act" value="refund" class="<?= $btnDanger ?>" data-confirm="<?= $p['method'] === 'stripe' ? 'Refund this amount to their card through Stripe? This can\'t be undone.' : 'Record that you handed this money back?' ?>">Refund</button>
            </form>
            <?php endif; ?>
            <?php if (!$void && $p['method'] !== 'stripe'): ?>
            <form method="POST" action="quotes.php" class="flex flex-wrap gap-2 items-end">
              <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="for" value="<?= $h($type . ':' . $sid) ?>"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
              <div><label class="<?= $label ?>" for="vr<?= (int)$p['id'] ?>">Typed by mistake? Why</label><input id="vr<?= (int)$p['id'] ?>" name="reason" maxlength="255" required class="<?= $input ?>"></div>
              <button name="act" value="void" class="<?= $btnSec ?>" data-confirm="Remove this <?= $p['kind'] ?>? It stays in the history, crossed out.">Remove</button>
            </form>
            <?php endif; ?>
            </div>
          </details>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($st['balance'] > 0): ?>
      <form method="POST" action="quotes.php" class="space-y-3 border-t border-stone-100 pt-3">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="for" value="<?= $h($type . ':' . $sid) ?>">
        <h3 class="text-sm font-semibold text-stone-700">Record a payment</h3>
        <div class="grid gap-3 sm:grid-cols-3">
          <div><label for="p_amt" class="<?= $label ?>">Amount ($)</label><input id="p_amt" name="amount" inputmode="decimal" required value="<?= $h(number_format(($st['deposit_left'] ?: $st['balance']) / 100, 2, '.', '')) ?>" class="<?= $input ?>"></div>
          <div><label for="p_m" class="<?= $label ?>">How</label><select id="p_m" name="method" class="<?= $input ?>">
            <?php foreach (BCRF_QUOTE_METHODS as $k => $v): ?><option value="<?= $h($k) ?>"><?= $h($v) ?></option><?php endforeach; ?></select></div>
          <div><label for="p_ref" class="<?= $label ?>">Reference <span class="font-normal text-stone-400">receipt #, check #, app note</span></label><input id="p_ref" name="reference" maxlength="120" class="<?= $input ?>"></div>
        </div>
        <div><label for="p_note" class="<?= $label ?>">Note <span class="font-normal text-stone-400">optional</span></label><input id="p_note" name="note" maxlength="255" class="<?= $input ?>"></div>
        <p class="text-xs text-stone-500">Rang it up on the register? Choose "Rung up on the register" and type the receipt number, so the money isn't counted twice. Cash and app payments taken here don't go in a drawer.</p>
        <button name="act" value="pay" class="<?= $btnMain ?>">Record payment</button>
      </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php
    bcrf_shell_close();
    exit;
}

// ── LIST: upcoming bookings and parties ──
$kind = in_array($_GET['kind'] ?? '', ['party', 'booking'], true) ? $_GET['kind'] : 'all';
$show = ($_GET['show'] ?? '') === 'all' ? 'all' : 'open';
$today = date('Y-m-d');
$rows = [];
if ($kind !== 'party' && $can('booking')) {
    $sql = "SELECT b.id, b.customer_name, b.booking_date, b.start_time, b.party_size, b.status, t.label FROM bookings b JOIN booking_types t ON t.id = b.type_id
            WHERE t.type_key <> 'day_admission' AND b.date_flexible = 0 AND b.booking_date >= ?" . ($show === 'open' ? " AND b.status IN ('pending', 'confirmed')" : '') . "
            ORDER BY b.booking_date, b.start_time LIMIT 300";
    $q = $pdo->prepare($sql); $q->execute([$show === 'open' ? $today : date('Y-m-d', strtotime('-60 days'))]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[] = ['type' => 'booking', 'id' => (int)$r['id'], 'name' => $r['customer_name'], 'date' => $r['booking_date'],
        'time' => $r['start_time'], 'guests' => (int)$r['party_size'], 'status' => $r['status'], 'what' => $r['label']];
}
if ($kind !== 'booking' && $can('event_inquiry')) {
    $sql = "SELECT id, customer_name, preferred_date, adult_count, child_count, status, event_type FROM event_inquiries
            WHERE " . ($show === 'open' ? "status IN ('new', 'contacted', 'quoted', 'confirmed') AND (preferred_date IS NULL OR preferred_date >= ?)" : "(preferred_date IS NULL OR preferred_date >= ?)") . "
            ORDER BY preferred_date IS NULL, preferred_date LIMIT 300";
    $q = $pdo->prepare($sql); $q->execute([$show === 'open' ? $today : date('Y-m-d', strtotime('-60 days'))]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[] = ['type' => 'event_inquiry', 'id' => (int)$r['id'], 'name' => $r['customer_name'], 'date' => (string)$r['preferred_date'],
        'time' => '', 'guests' => (int)$r['adult_count'] + (int)$r['child_count'], 'status' => $r['status'], 'what' => BCRF_QUOTE_PARTY_KINDS[$r['event_type']] ?? 'Private event'];
}
usort($rows, fn($a, $b) => [$a['date'] === '' ? '9999' : $a['date'], $a['time']] <=> [$b['date'] === '' ? '9999' : $b['date'], $b['time']]);
$badges = ['booking' => bcrf_quote_badges($pdo, 'booking', array_column(array_filter($rows, fn($r) => $r['type'] === 'booking'), 'id')),
           'event_inquiry' => bcrf_quote_badges($pdo, 'event_inquiry', array_column(array_filter($rows, fn($r) => $r['type'] === 'event_inquiry'), 'id'))];
$owed = 0; $depDue = 0;
foreach ($rows as $r) {
    $b = $badges[$r['type']][$r['id']] ?? null;
    if ($b && !in_array($r['status'], ['cancelled', 'declined', 'archived', 'no_show'], true)) { $owed += max(0, $b['status']['balance']); $depDue += $b['status']['deposit_left']; }
}

bcrf_shell_open('Quotes & payments', ['toast' => $toast]);
?>
  <h1 class="text-2xl font-bold text-stone-800 mb-1">Quotes &amp; payments</h1>
  <p class="text-sm text-stone-500 mb-4">Upcoming group bookings and parties: the quote, any deposit, and what's left to pay. Tap one to price it, send it, or record a payment.</p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>
  <div class="flex flex-wrap gap-2 mb-4 text-sm">
    <?php foreach (['all' => 'Everything', 'party' => 'Parties', 'booking' => 'Group bookings'] as $k => $v): ?>
      <a href="?kind=<?= $k ?>&amp;show=<?= $show ?>" class="px-3 py-2 rounded-lg border <?= $kind === $k ? 'bg-stone-800 text-white border-stone-800' : 'bg-white text-stone-600 border-stone-200' ?>"><?= $v ?></a>
    <?php endforeach; ?>
    <a href="?kind=<?= $kind ?>&amp;show=<?= $show === 'open' ? 'all' : 'open' ?>" class="px-3 py-2 rounded-lg border bg-white text-stone-600 border-stone-200"><?= $show === 'open' ? 'Include cancelled and past 60 days' : 'Upcoming only' ?></a>
  </div>
  <div class="grid grid-cols-2 gap-2 mb-4">
    <div class="bg-white border border-stone-200 rounded-lg p-3"><div class="text-xs text-stone-500">Still to collect</div><div class="text-lg font-bold text-stone-800"><?= $h($m($owed)) ?></div></div>
    <div class="bg-white border border-stone-200 rounded-lg p-3"><div class="text-xs text-stone-500">Deposits due</div><div class="text-lg font-bold <?= $depDue ? 'text-amber-700' : 'text-stone-800' ?>"><?= $h($m($depDue)) ?></div></div>
  </div>
  <?php if (!$rows): ?>
    <div class="<?= $card ?> text-sm text-stone-500">No upcoming group bookings or parties.</div>
  <?php else: ?>
  <div class="bg-white border border-stone-200 rounded-lg shadow-sm divide-y divide-stone-100">
    <?php foreach ($rows as $r): $b = $badges[$r['type']][$r['id']] ?? null; ?>
    <a href="quotes.php?for=<?= $h($r['type'] . ':' . $r['id']) ?>" class="flex flex-wrap items-center justify-between gap-2 p-3 hover:bg-stone-50">
      <span class="text-sm"><strong class="text-stone-800"><?= $h($r['name']) ?></strong>
        <span class="text-stone-500"> · <?= $h($r['what']) ?> · <?= $r['date'] ? $h(date('D, M j', strtotime($r['date']))) . ($r['time'] ? ' ' . $h(date('g:i A', strtotime($r['time']))) : '') : 'no date' ?> · <?= (int)$r['guests'] ?> guests · <?= $h($r['status']) ?></span></span>
      <?= $b ? $stageChip($b['status']['stage'], $b['status']['label']) : $stageChip('none', 'No quote yet') ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php
bcrf_shell_close();
