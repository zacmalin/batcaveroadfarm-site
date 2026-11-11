<?php
// admin/gift_cards.php — gift card list, balances and liability (Farm OS Phase 2, Nov 11, 2026; includes/gift_cards.php)
//   GET  gift_cards.php[?q=&status=&source=]   list with the outstanding total (gift card liability, 13)
//   GET  gift_cards.php?id=N                   one card: balance, history, actions
//   GET  gift_cards.php?new=1                  give a card without a sale (Owner or Manager)
//   GET  gift_cards.php?csv=1                  open balances as CSV (for the books)
//   POST gift_cards.php  act=adjust | void | email | issue
// Permission: the register (view pos) to look; Owner or Manager to change a balance, cancel, give, or see a full code.
// Staff see codes as ····AB12: whoever has the full code can spend it.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['view', 'pos']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/gift_cards.php';
require_once __DIR__ . '/../includes/admin_shell.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$mgr = bcrf_gc_is_manager();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$m = fn($c) => bcrf_gc_money((int)$c);
$local = function (?string $utc, string $fmt = 'n/j/Y g:i A'): string {
    if (!$utc) return '';
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format($fmt);
};
$code = fn(array $c) => $mgr ? $c['code_fmt'] : bcrf_gc_masked($c['code']);

if (!bcrf_gift_cards_ready($pdo)) {
    bcrf_shell_open('Gift Cards');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Gift Cards</h1>',
         bcrf_shell_notice('warning', 'Run migration 0028 in Settings › Database first.'),
         '<a href="/admin/migrations.php" class="text-sm text-amber-800 underline">Open Settings › Database</a>';
    bcrf_shell_close();
    exit;
}

// ── CSV: open balances ──
if (isset($_GET['csv'])) {
    $rows = bcrf_gc_list($pdo, ['status' => 'open'], 100000);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gift-card-balances-' . date('Y-m-d') . '.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['card', 'source', 'issued_date', 'initial', 'balance', 'purchaser', 'recipient'], ',', '"', '');
    foreach ($rows as $r) fputcsv($o, [bcrf_gc_masked($r['code']), $r['source'], $local($r['created_at'], 'Y-m-d'), number_format($r['initial_cents'] / 100, 2, '.', ''),
                                       number_format($r['balance_cents'] / 100, 2, '.', ''), (string)$r['purchaser_name'], (string)($r['recipient_name'] ?: $r['recipient_email'])], ',', '"', '');
    fclose($o);
    if (function_exists('bcrf_audit_event')) {
        [$t, $aid, $name, $ip] = bcrf_audit_actor();
        bcrf_audit_event($pdo, ['actor_type' => $t, 'actor_id' => $aid, 'actor_label' => $name, 'ip' => $ip, 'action' => 'export',
            'object_type' => 'gift_cards', 'object_id' => null, 'object_label' => 'Gift card balances CSV (' . count($rows) . ' cards)']);
    }
    exit;
}

// ── Actions ──
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $back = $id ? 'gift_cards.php?id=' . $id : 'gift_cards.php?new=1';
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['gc_flash'] = ['err', 'Security token expired; try again.'];
        header('Location: ' . $back); exit;
    }
    $act = (string)($_POST['act'] ?? '');
    try {
        if ($act === 'adjust') {
            $cents = (int)round(((float)($_POST['amount'] ?? 0)) * 100) * (($_POST['dir'] ?? '') === 'remove' ? -1 : 1);
            $c = bcrf_gc_adjust($pdo, $id, $cents, (string)($_POST['reason'] ?? ''), $uid);
            $_SESSION['gc_flash'] = ['ok', 'Balance is now ' . $m($c['balance_cents']) . '.'];
        } elseif ($act === 'void') {
            bcrf_gc_void($pdo, $id, (string)($_POST['reason'] ?? ''), $uid);
            $_SESSION['gc_flash'] = ['ok', 'Gift card cancelled.'];
        } elseif ($act === 'email') {
            if (!$mgr) throw new BcrfGiftCardError('Only an Owner or Manager can email a gift card from here. On the register, email it right after the sale.');
            $c = bcrf_gc_get($pdo, $id);
            if (!$c) throw new BcrfGiftCardError('Gift card not found.');
            $to = bcrf_gc_email($pdo, $c, trim((string)($_POST['email'] ?? '')) ?: null);
            $_SESSION['gc_flash'] = ['ok', 'Gift card emailed to ' . $to . '.'];
        } elseif ($act === 'issue') {
            $c = bcrf_gc_issue_manual($pdo, (int)round(((float)($_POST['amount'] ?? 0)) * 100), (string)($_POST['reason'] ?? ''),
                                      ['name' => $_POST['name'] ?? '', 'email' => $_POST['email'] ?? ''], $uid);
            $msg = 'Gift card ' . $c['code_fmt'] . ' made with ' . $m($c['balance_cents']) . '.';
            if (!empty($_POST['send']) && $c['recipient_email']) { bcrf_gc_email($pdo, $c); $msg .= ' Emailed to ' . $c['recipient_email'] . '.'; }
            $_SESSION['gc_flash'] = ['ok', $msg];
            header('Location: gift_cards.php?id=' . $c['id']); exit;
        } else {
            throw new BcrfGiftCardError('Unknown action.');
        }
    } catch (BcrfGiftCardError $e) {
        $_SESSION['gc_flash'] = ['err', $e->getMessage()];
        if ($act === 'issue') $_SESSION['gc_form'] = $_POST;
    } catch (Throwable $e) {
        error_log('[gift_cards] ' . $act . ': ' . $e->getMessage());
        $_SESSION['gc_flash'] = ['err', 'Could not save. Try again.'];
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['gc_flash'] ?? null; unset($_SESSION['gc_flash']);
$typed = $_SESSION['gc_form'] ?? null; unset($_SESSION['gc_form']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';

$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-1.5 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-1.5 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100';
$btnDanger = 'px-3 py-1.5 rounded text-sm border border-red-300 text-red-700 hover:bg-red-50';
$sources = ['pos' => 'Register', 'online' => 'Online', 'manual' => 'Given'];
$statusChip = function (array $c) use ($h) {
    if ($c['status'] === 'void') return '<span class="text-xs font-semibold px-2 py-1 rounded bg-stone-100 text-stone-500">Cancelled</span>';
    if ($c['status'] === 'pending') return '<span class="text-xs font-semibold px-2 py-1 rounded bg-amber-50 text-amber-800">Not paid</span>';
    if ($c['balance_cents'] <= 0) return '<span class="text-xs font-semibold px-2 py-1 rounded bg-stone-100 text-stone-500">Used up</span>';
    return '';
};

// ── NEW (give without a sale) ──
if (isset($_GET['new'])) {
    $d = $typed ?: [];
    bcrf_shell_open('Give a gift card — Gift Cards', ['toast' => $toast, 'width' => 'max-w-2xl']);
    ?>
  <a href="gift_cards.php" class="inline-block text-sm text-stone-500 hover:text-stone-800 mb-2">← All gift cards</a>
  <h1 class="text-2xl font-bold text-stone-800 mb-1">Give a gift card</h1>
  <p class="text-sm text-stone-500 mb-4">For a raffle prize, a thank-you, or to replace a lost card (cancel the old one first). No money is taken. To sell one, ring up the Gift Card item on the register.</p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>
  <?php if (!$mgr): echo bcrf_shell_notice('info', 'Only an Owner or Manager can give a gift card without a sale.'); else: ?>
  <form method="POST" action="gift_cards.php" class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm space-y-3">
    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
    <input type="hidden" name="act" value="issue">
    <div class="grid gap-3 sm:grid-cols-2">
      <div><label for="g_amt" class="<?= $label ?>">Amount ($)</label><input id="g_amt" name="amount" type="number" min="1" max="500" step="0.01" inputmode="decimal" required value="<?= $h($d['amount'] ?? '') ?>" class="<?= $input ?>"></div>
      <div><label for="g_reason" class="<?= $label ?>">Why</label><input id="g_reason" name="reason" maxlength="255" required placeholder="e.g. Raffle prize, replaces ····AB12" value="<?= $h($d['reason'] ?? '') ?>" class="<?= $input ?>"></div>
      <div><label for="g_name" class="<?= $label ?>">For <span class="font-normal text-stone-400">optional</span></label><input id="g_name" name="name" maxlength="120" value="<?= $h($d['name'] ?? '') ?>" class="<?= $input ?>"></div>
      <div><label for="g_email" class="<?= $label ?>">Their email <span class="font-normal text-stone-400">optional</span></label><input id="g_email" name="email" type="email" maxlength="190" value="<?= $h($d['email'] ?? '') ?>" class="<?= $input ?>"></div>
    </div>
    <label class="flex items-center gap-2 text-sm text-stone-700"><input type="checkbox" name="send" value="1" <?= !empty($d['send']) ? 'checked' : '' ?>> Email it to them now</label>
    <button type="submit" class="<?= $btnMain ?>">Make the gift card</button>
  </form>
  <?php endif;
    bcrf_shell_close();
    exit;
}

// ── ONE CARD ──
if (isset($_GET['id'])) {
    $c = bcrf_gc_get($pdo, (int)$_GET['id']);
    if (!$c) {
        http_response_code(404);
        bcrf_shell_open('Gift card not found', ['width' => 'max-w-2xl']);
        echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Gift card not found</h1>', bcrf_shell_notice('info', "That gift card doesn't exist."),
             '<a href="gift_cards.php" class="text-sm text-amber-800 underline">All gift cards</a>';
        bcrf_shell_close();
        exit;
    }
    $hist = bcrf_gc_history($pdo, (int)$c['id']);
    $sale = null;
    if ($c['sale_id']) { $st = $pdo->prepare("SELECT sale_number FROM sales WHERE id = ?"); $st->execute([$c['sale_id']]); $sale = $st->fetchColumn() ?: null; }
    bcrf_shell_open('Gift card ' . bcrf_gc_masked($c['code']) . ' — Gift Cards', ['toast' => $toast, 'width' => 'max-w-3xl']);
    ?>
  <a href="gift_cards.php" class="inline-block text-sm text-stone-500 hover:text-stone-800 mb-2">← All gift cards</a>
  <div class="flex flex-wrap items-center gap-3 mb-1">
    <h1 class="text-2xl font-bold text-stone-800 font-mono"><?= $h($code($c)) ?></h1>
    <?= $statusChip($c) ?>
  </div>
  <p class="text-sm text-stone-500 mb-4"><?= $h($sources[$c['source']] ?? $c['source']) ?> · <?= $h($local($c['created_at'])) ?><?= $sale ? ' · sale ' . $h($sale) : '' ?><?= $c['note'] ? ' · ' . $h($c['note']) : '' ?></p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>

  <div class="grid gap-3 sm:grid-cols-3 mb-4">
    <div class="bg-white border border-stone-200 rounded-lg p-3 shadow-sm"><div class="text-xs text-stone-500">Balance</div><div class="text-2xl font-bold text-stone-800"><?= $h($m($c['balance_cents'])) ?></div></div>
    <div class="bg-white border border-stone-200 rounded-lg p-3 shadow-sm"><div class="text-xs text-stone-500">Started with</div><div class="text-2xl font-bold text-stone-800"><?= $h($m($c['initial_cents'])) ?></div></div>
    <div class="bg-white border border-stone-200 rounded-lg p-3 shadow-sm"><div class="text-xs text-stone-500">Emailed</div><div class="text-sm font-semibold text-stone-800 mt-1"><?= $c['sent_at'] ? $h($local($c['sent_at'])) : 'Not yet' ?></div></div>
  </div>

  <?php if ($c['purchaser_name'] || $c['recipient_name'] || $c['recipient_email'] || $c['message']): ?>
  <div class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4 text-sm text-stone-700 space-y-1">
    <?php if ($c['purchaser_name'] || $c['purchaser_email']): ?><div><span class="text-stone-500">Bought by</span> <?= $h(trim($c['purchaser_name'] . ' ' . ($c['purchaser_email'] ? '<' . $c['purchaser_email'] . '>' : ''))) ?></div><?php endif; ?>
    <?php if ($c['recipient_name'] || $c['recipient_email']): ?><div><span class="text-stone-500">For</span> <?= $h(trim($c['recipient_name'] . ' ' . ($c['recipient_email'] ? '<' . $c['recipient_email'] . '>' : ''))) ?></div><?php endif; ?>
    <?php if ($c['message']): ?><div><span class="text-stone-500">Message</span> “<?= $h($c['message']) ?>”</div><?php endif; ?>
    <?php if ($c['status'] === 'void'): ?><div><span class="text-stone-500">Cancelled</span> <?= $h($local($c['voided_at'])) ?><?= $c['void_reason'] ? ': ' . $h($c['void_reason']) : '' ?></div><?php endif; ?>
  </div>
  <?php endif; ?>

  <h2 class="text-lg font-semibold text-stone-800 mb-2">History</h2>
  <div class="bg-white border border-stone-200 rounded-lg shadow-sm mb-4 divide-y divide-stone-100">
    <?php if (!$hist): ?><p class="p-3 text-sm text-stone-500">Nothing yet<?= $c['status'] === 'pending' ? ': waiting for the online payment.' : '.' ?></p><?php endif; ?>
    <?php foreach ($hist as $t): ?>
    <div class="flex items-center justify-between gap-3 p-3 text-sm">
      <div class="min-w-0">
        <div class="font-semibold text-stone-800"><?= $h(bcrf_gc_type_label($t['type'])) ?><?= $t['sale_number'] ? ' · ' . $h($t['sale_number']) : '' ?><?= $t['refund_number'] ? ' · ' . $h($t['refund_number']) : '' ?></div>
        <div class="text-xs text-stone-500"><?= $h($local($t['created_at'])) ?><?= $t['note'] ? ' · ' . $h($t['note']) : '' ?></div>
      </div>
      <div class="text-right shrink-0">
        <div class="font-semibold <?= (int)$t['amount_cents'] < 0 ? 'text-red-700' : 'text-green-700' ?>"><?= (int)$t['amount_cents'] > 0 ? '+' : '' ?><?= $h($m($t['amount_cents'])) ?></div>
        <div class="text-xs text-stone-500">left <?= $h($m($t['balance_after_cents'])) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($c['status'] === 'active'): ?>
  <div class="flex flex-wrap gap-2 mb-4">
    <?php if ($mgr): ?><a href="gift_card_print.php?id=<?= (int)$c['id'] ?>" target="_blank" rel="noopener" class="<?= $btnSec ?>">Print card</a>
    <a href="<?= $h(bcrf_gc_url($c['code'])) ?>" target="_blank" rel="noopener" class="<?= $btnSec ?>">Open the card page</a><?php endif; ?>
  </div>
  <?php if ($mgr): ?>
  <div class="grid gap-3 sm:grid-cols-2">
    <form method="POST" action="gift_cards.php" class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm space-y-2">
      <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="email"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <h3 class="font-semibold text-stone-800">Email the card</h3>
      <label for="e_to" class="<?= $label ?>">To</label>
      <input id="e_to" name="email" type="email" maxlength="190" value="<?= $h($c['recipient_email'] ?: $c['purchaser_email']) ?>" class="<?= $input ?>">
      <button type="submit" class="<?= $btnSec ?>">Send</button>
    </form>
    <form method="POST" action="gift_cards.php" class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm space-y-2">
      <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="adjust"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <h3 class="font-semibold text-stone-800">Change the balance</h3>
      <div class="flex gap-2">
        <select name="dir" class="<?= $input ?> w-28" aria-label="Add or take off"><option value="add">Add</option><option value="remove">Take off</option></select>
        <input name="amount" type="number" min="0.01" max="500" step="0.01" inputmode="decimal" required placeholder="$" aria-label="Amount" class="<?= $input ?>">
      </div>
      <input name="reason" maxlength="255" required placeholder="Reason (e.g. partial refund of the card)" aria-label="Reason" class="<?= $input ?>">
      <button type="submit" class="<?= $btnSec ?>">Change</button>
    </form>
    <form method="POST" action="gift_cards.php" class="bg-white border border-red-200 rounded-lg p-4 shadow-sm space-y-2 sm:col-span-2">
      <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="void"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <h3 class="font-semibold text-red-800">Cancel this card</h3>
      <p class="text-xs text-stone-500">For a lost or stolen card: cancel it, then give a new one for what was left (<?= $h($m($c['balance_cents'])) ?>). It can't be undone.</p>
      <div class="flex gap-2">
        <input name="reason" maxlength="255" required placeholder="Reason" aria-label="Reason to cancel" class="<?= $input ?>">
        <button type="submit" class="<?= $btnDanger ?>">Cancel card</button>
      </div>
    </form>
  </div>
  <?php else: echo bcrf_shell_notice('info', 'An Owner or Manager can email, print, change or cancel this card.'); endif; ?>
  <?php endif;
    bcrf_shell_close();
    exit;
}

// ── LIST ──
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'status' => in_array($_GET['status'] ?? '', ['open', 'active', 'pending', 'void', 'all'], true) ? $_GET['status'] : 'open',
      'source' => isset($sources[$_GET['source'] ?? '']) ? $_GET['source'] : ''];
$rows = bcrf_gc_list($pdo, $f);   // status 'all' = no filter
$liab = bcrf_gc_liability($pdo);
bcrf_shell_open('Gift Cards', ['toast' => $toast, 'width' => 'max-w-3xl']);
?>
  <div class="flex flex-wrap items-end justify-between gap-3 mb-1">
    <h1 class="text-2xl font-bold text-stone-800">Gift Cards</h1>
    <div class="flex gap-2">
      <a href="gift_cards.php?csv=1" class="<?= $btnSec ?>">Balances CSV</a>
      <?php if ($mgr): ?><a href="gift_cards.php?new=1" class="<?= $btnMain ?>">Give a card</a><?php endif; ?>
    </div>
  </div>
  <p class="text-sm text-stone-500 mb-4">Sold on the register (the Gift Card item) and online at <a class="underline" href="/gift-cards/" target="_blank" rel="noopener">/gift-cards/</a>. Spent on the register with the Gift card payment type. They never expire.</p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>

  <div class="grid gap-3 sm:grid-cols-4 mb-4">
    <div class="bg-white border border-stone-200 rounded-lg p-3 shadow-sm sm:col-span-2">
      <div class="text-xs text-stone-500">Still owed on cards (liability)</div>
      <div class="text-2xl font-bold text-stone-800"><?= $h($m($liab['cents'])) ?></div>
      <div class="text-xs text-stone-500"><?= (int)$liab['cards'] ?> card<?= $liab['cards'] === 1 ? '' : 's' ?> with money on them</div>
    </div>
    <?php foreach ($sources as $k => $lbl): $x = $liab['issued_by_source'][$k] ?? ['n' => 0, 'cents' => 0]; ?>
    <div class="bg-white border border-stone-200 rounded-lg p-3 shadow-sm">
      <div class="text-xs text-stone-500"><?= $h($lbl) ?></div>
      <div class="text-lg font-semibold text-stone-800"><?= $h($m($x['cents'])) ?></div>
      <div class="text-xs text-stone-500"><?= (int)$x['n'] ?> issued</div>
    </div>
    <?php endforeach; ?>
  </div>

  <form method="GET" action="gift_cards.php" class="bg-white border border-stone-200 rounded-lg p-3 shadow-sm mb-4 flex flex-wrap gap-2 items-end">
    <div class="flex-1 min-w-[12rem]">
      <label for="gq" class="<?= $label ?>">Search</label>
      <input id="gq" type="search" name="q" value="<?= $h($f['q']) ?>" placeholder="Last 4, full code, name or email" class="<?= $input ?>">
    </div>
    <div class="w-36"><label class="<?= $label ?>" for="gs">Show</label>
      <select id="gs" name="status" class="<?= $input ?>">
        <?php foreach (['open' => 'With money on', 'active' => 'All active', 'pending' => 'Not paid yet', 'void' => 'Cancelled', 'all' => 'All'] as $k => $v): ?>
        <option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= $h($v) ?></option><?php endforeach; ?>
      </select></div>
    <div class="w-32"><label class="<?= $label ?>" for="gsrc">From</label>
      <select id="gsrc" name="source" class="<?= $input ?>"><option value="">Anywhere</option>
        <?php foreach ($sources as $k => $v): ?><option value="<?= $k ?>"<?= $f['source'] === $k ? ' selected' : '' ?>><?= $h($v) ?></option><?php endforeach; ?>
      </select></div>
    <button type="submit" class="<?= $btnSec ?>">Show</button>
  </form>

  <?php if (!$rows): ?>
    <div class="bg-white border border-stone-200 rounded-lg p-6 text-center text-sm text-stone-500">
      <?= $f['q'] !== '' || $f['status'] !== 'open' || $f['source'] !== '' ? 'No gift cards match.' : 'No gift cards with money on them yet.' ?>
    </div>
  <?php else: ?>
    <div class="space-y-2">
      <?php foreach ($rows as $r):
          $who = trim((string)($r['recipient_name'] ?: $r['recipient_email'] ?: $r['purchaser_name'] ?: $r['note'])); ?>
      <a href="gift_cards.php?id=<?= (int)$r['id'] ?>" class="flex items-center justify-between gap-3 bg-white border border-stone-200 rounded-lg p-3 shadow-sm hover:border-stone-400">
        <div class="min-w-0">
          <div class="text-base font-semibold text-stone-800 font-mono truncate"><?= $h($code($r)) ?></div>
          <div class="text-xs text-stone-500 mt-0.5 truncate"><?= $h($sources[$r['source']] ?? $r['source']) ?> · <?= $h($local($r['created_at'], 'n/j/Y')) ?><?= $who !== '' ? ' · ' . $h($who) : '' ?></div>
        </div>
        <div class="flex items-center gap-2 shrink-0">
          <?= $statusChip($r) ?>
          <div class="text-right"><div class="font-semibold text-stone-800"><?= $h($m($r['balance_cents'])) ?></div><div class="text-xs text-stone-500">of <?= $h($m($r['initial_cents'])) ?></div></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <p class="text-xs text-stone-400 mt-3"><?= count($rows) ?> card<?= count($rows) === 1 ? '' : 's' ?><?= count($rows) >= 200 ? ' (newest 200; search to find others)' : '' ?>.</p>
  <?php endif;
bcrf_shell_close();
