<?php
// admin/customers.php — Customers: find anyone, see everything the farm has for them, households, and merging
// duplicates (Farm OS Phase 3, Nov 12, 2026; includes/crm.php; 07 › Customer model, CRM extension Phase 1).
//   GET  customers.php[?q=…]          search (name, email, phone); newest first when blank
//   GET  customers.php?id=N           one customer: summary strip (spent, visits, next, follow-ups; Nov 14, 2026), contact, notes, household, organizations, bookings, parties,
//                                     passes, tickets, register sales, rewards, email preferences, merges
//   GET  customers.php?dupes=1        possible duplicates (same phone or same name) to review and merge
//   POST act=save | household_new | household_join | household_leave | child_add | child_remove | merge | undo_merge
//        | email_off (staff can turn an email off when a customer asks; only the customer can turn news on)
// Permission: view 'customers' (Owners always; Managers by default). Merging and undoing need an Owner or Manager.
// Opening the page links any new bookings, parties, passes and tickets to their customer by email first.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['view', 'customers']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/event_quotes.php';
require_once __DIR__ . '/../includes/crm.php';
require_once __DIR__ . '/../includes/email_prefs.php';
require_once __DIR__ . '/../includes/admin_shell.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$mgr = bcrf_quote_is_manager();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$m = fn(int $c) => bcrf_quote_money($c);

if (!bcrf_crm_ready($pdo)) {
    bcrf_shell_open('Customers');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Customers</h1>', bcrf_shell_notice('warning', 'Run migration 0036 in Settings › Database first.'),
         '<a href="/admin/migrations.php" class="text-sm text-amber-800 underline">Open Settings › Database</a>';
    bcrf_shell_close(); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $back = 'customers.php?id=' . $id;
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfCrmError('Security token expired; try again.');
        $c = bcrf_crm_get($pdo, $id);
        if (!$c) throw new BcrfCrmError('That customer is gone.');
        $hid = (int)$c['household_id'];
        if ($act === 'save') {
            bcrf_crm_update($pdo, $id, $_POST);
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'customers', $id, 'Customer details or notes changed');
            $_SESSION['crm_flash'] = ['ok', 'Saved.'];
        } elseif ($act === 'household_new') {
            bcrf_crm_household_create($pdo, $id, (string)($_POST['name'] ?? ''), $uid);
            $_SESSION['crm_flash'] = ['ok', 'Household made.'];
        } elseif ($act === 'household_join') {
            $other = (int)($_POST['other_id'] ?? 0);
            if (!$other && ($e = bcrf_crm_email((string)($_POST['other_email'] ?? '')))) {
                $st = $pdo->prepare("SELECT id FROM customers WHERE email = ?"); $st->execute([$e]); $other = bcrf_crm_resolve($pdo, (int)$st->fetchColumn());
            }
            if (!$other) throw new BcrfCrmError('No customer has that email. Search for them first.');
            bcrf_crm_household_join($pdo, $other, $id, $uid);
            $_SESSION['crm_flash'] = ['ok', 'Added to the household.'];
        } elseif ($act === 'household_leave') {
            $who = (int)($_POST['member_id'] ?? $id);
            $mc = bcrf_crm_get($pdo, $who);
            if (!$mc || (int)$mc['household_id'] !== $hid) throw new BcrfCrmError('They\'re not in this household.');
            bcrf_crm_household_join($pdo, $who, 0, $uid);
            $_SESSION['crm_flash'] = ['ok', 'Taken out of the household.'];
        } elseif ($act === 'child_add') {
            if (!$hid) $hid = bcrf_crm_household_create($pdo, $id, '', $uid);
            bcrf_crm_child_add($pdo, $hid, (string)($_POST['first_name'] ?? ''), (string)($_POST['age'] ?? ''));
            $_SESSION['crm_flash'] = ['ok', 'Child added.'];
        } elseif ($act === 'child_remove') {
            bcrf_crm_child_remove($pdo, $hid, (int)($_POST['child_id'] ?? 0));
            $_SESSION['crm_flash'] = ['ok', 'Removed.'];
        } elseif ($act === 'merge') {
            if (!$mgr) throw new BcrfCrmError('Only an Owner or Manager can merge customers.');
            $dup = (int)($_POST['dup_id'] ?? 0);
            bcrf_crm_merge($pdo, $id, $dup, $uid);
            $_SESSION['crm_flash'] = ['ok', 'Merged. Everything from the other record is on this one now. You can undo it below.'];
        } elseif ($act === 'email_off') {
            $k = (string)($_POST['setting'] ?? '');
            $off = $k === 'all' ? null : (isset(BCRF_EMAIL_PREF_SERVICE[$k]) || isset(BCRF_EMAIL_PREF_TOPICS[$k]) ? [$k => false] : []);
            if ($off === []) throw new BcrfCrmError('Unknown email setting.');
            $changed = $off === null ? bcrf_email_prefs_all_off($pdo, (string)$c['email'], 'staff', $uid) : bcrf_email_prefs_save($pdo, (string)$c['email'], $off, 'staff', $uid);
            $_SESSION['crm_flash'] = ['ok', $changed ? 'Turned off.' : 'It was already off.'];
        } elseif ($act === 'undo_merge') {
            if (!$mgr) throw new BcrfCrmError('Only an Owner or Manager can undo a merge.');
            bcrf_crm_merge_undo($pdo, (int)($_POST['merge_id'] ?? 0), $uid);
            $_SESSION['crm_flash'] = ['ok', 'Merge undone. They\'re two separate records again.'];
        } else {
            throw new BcrfCrmError('Unknown action.');
        }
    } catch (BcrfCrmError $e) {
        $_SESSION['crm_flash'] = ['err', $e->getMessage()];
    } catch (Throwable $e) {
        error_log('[customers] ' . $act . ': ' . $e->getMessage());
        $_SESSION['crm_flash'] = ['err', 'Could not save. Try again.'];
    }
    header('Location: ' . $back); exit;
}

$sync = ['linked' => 0, 'created' => 0];
try { $sync = bcrf_crm_sync($pdo); } catch (Throwable $e) { error_log('[customers] sync: ' . $e->getMessage()); }

$flash = $_SESSION['crm_flash'] ?? null; unset($_SESSION['crm_flash']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';

$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$chip    = fn(string $t, string $c = 'bg-stone-100 text-stone-600') => '<span class="inline-block text-xs px-2 py-0.5 rounded ' . $c . '">' . $h($t) . '</span>';
$date    = fn($d) => $d ? date('M j, Y', strtotime((string)$d)) : '';
$hidden  = fn(int $id) => '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '"><input type="hidden" name="id" value="' . $id . '">';
$tabs = function (string $on) use ($h) {
    $out = '<div class="flex gap-1 mb-4">';
    foreach (['find' => ['Find', 'customers.php'], 'dupes' => ['Possible duplicates', 'customers.php?dupes=1'], 'orgs' => ['Organizations', 'organizations.php']] as $k => [$t, $u]) {
        $out .= '<a href="' . $u . '" class="px-3 py-2 rounded text-sm border ' . ($k === $on ? 'border-stone-800 bg-stone-800 text-white' : 'border-stone-300 text-stone-700 bg-white hover:bg-stone-100') . '">' . $h($t) . '</a>';
    }
    return $out . '</div>';
};
$badge = function (array $c) use ($chip): string {
    $out = '';
    if ($c['staff_user_id']) $out .= $chip('Staff', 'bg-violet-100 text-violet-800') . ' ';
    if ($c['password'] !== null && $c['password'] !== '') $out .= $chip('Website account', 'bg-sky-100 text-sky-800') . ' ';
    elseif (($c['source'] ?? '') === 'guest') $out .= $chip('Guest') . ' ';
    if ($c['merged_into_id']) $out .= $chip('Merged', 'bg-red-50 text-red-700') . ' ';
    elseif ($c['account_status'] === 'disabled') $out .= $chip('Switched off', 'bg-red-50 text-red-700') . ' ';
    return $out;
};

$id = (int)($_GET['id'] ?? 0);

// ── ONE CUSTOMER ──
if ($id) {
    $c = bcrf_crm_get($pdo, $id);
    if (!$c) { bcrf_shell_open('Customers'); echo bcrf_shell_notice('err', 'That customer wasn\'t found.'), '<a href="customers.php" class="underline text-sm">Back</a>'; bcrf_shell_close(); exit; }
    if ($c['merged_into_id']) { header('Location: customers.php?id=' . bcrf_crm_resolve($pdo, $id)); exit; }
    $p = bcrf_crm_profile($pdo, $id);
    $sum = bcrf_crm_summary($pdo, $id);
    $dupWith = (int)($_GET['merge'] ?? 0);
    $dupRow = $dupWith ? bcrf_crm_get($pdo, $dupWith) : null;
    bcrf_shell_open(bcrf_crm_name($c), ['toast' => $toast, 'width' => 'max-w-4xl', 'js' => ['quotes.js']]);
?>
  <a href="customers.php" class="text-sm text-amber-800 underline">‹ Customers</a>
  <div class="flex flex-wrap items-end justify-between gap-2 mt-2 mb-3">
    <div>
      <h1 class="text-2xl font-bold text-stone-800"><?= $h(bcrf_crm_name($c)) ?></h1>
      <p class="text-sm text-stone-600"><?= $h($c['email']) ?><?= $c['phone_number'] ? ' · ' . $h($c['phone_number']) : '' ?> · since <?= $h($date($c['created_at'])) ?></p>
    </div>
    <div><?= $badge($c) ?><?= $p['rewards'] !== null ? $chip('Roost Rewards: ' . $p['rewards'] . ' pts', 'bg-amber-100 text-amber-900') : '' ?></div>
  </div>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>

  <?php // Summary strip (Nov 14, 2026): bcrf_crm_summary() in includes/crm.php says what each number counts.
    $tile = 'bg-white border border-stone-200 rounded-lg px-3 py-2 shadow-sm';
    $nx = $sum['next'];
    $nxText = 'Nothing booked';
    $nxUrl = '';
    if ($nx) {
        $nxText = ($nx['kind'] === 'party' ? 'Party' : 'Booking') . ' ' . date('M j', strtotime((string)$nx['d'])) . ($nx['t'] ? ', ' . date('g:i A', strtotime((string)$nx['t'])) : '') . ' · ' . $nx['status'];
        $nxUrl = $nx['kind'] === 'party' ? '/admin/party.php?id=' . (int)$nx['id'] : '/admin/admin.php?view=bookings&day=' . rawurlencode((string)$nx['d']);
    }
  ?>
  <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mb-4">
    <div class="<?= $tile ?>"><p class="text-xs text-stone-500">Spent with us</p><p class="text-lg font-semibold text-stone-800"><?= $h($m($sum['spent_cents'])) ?></p></div>
    <div class="<?= $tile ?>"><p class="text-xs text-stone-500">Visits</p><p class="text-lg font-semibold text-stone-800"><?= (int)$sum['visits'] ?></p></div>
    <div class="<?= $tile ?>"><p class="text-xs text-stone-500">Next</p><p class="text-sm font-semibold text-stone-800"><?= $nxUrl ? '<a class="underline text-amber-800" href="' . $h($nxUrl) . '">' . $h($nxText) . '</a>' : $h($nxText) ?></p></div>
    <div class="<?= $tile ?>"><p class="text-xs text-stone-500">Follow-ups</p><p class="text-sm font-semibold text-stone-800"><?= $sum['open_tasks'] ? (int)$sum['open_tasks'] . ' open task' . ($sum['open_tasks'] === 1 ? '' : 's') : 'None open' ?></p>
      <?php if ($sum['pass_ends']): ?><p class="text-xs text-amber-800">Pass ends <?= $h($date($sum['pass_ends'])) ?></p><?php endif; ?></div>
  </div>

  <?php if ($dupRow && $mgr): $why = bcrf_crm_merge_blocker($pdo, $c, $dupRow); ?>
  <div class="<?= $card ?> border-amber-300">
    <h2 class="text-base font-semibold text-stone-800 mb-1">Merge <?= $h(bcrf_crm_name($dupRow)) ?> into this record?</h2>
    <p class="text-sm text-stone-700 mb-2"><?= $h($dupRow['email']) ?><?= $dupRow['phone_number'] ? ' · ' . $h($dupRow['phone_number']) : '' ?>. Their bookings, parties, passes, tickets, sales, waivers, incident reports and organizations move here, and their record is switched off. You can undo it afterwards.</p>
    <?php if ($why !== ''): ?>
      <?= bcrf_shell_notice('warning', $why) ?>
      <?php if (bcrf_crm_merge_blocker($pdo, $dupRow, $c) === ''): ?><a href="customers.php?id=<?= (int)$dupRow['id'] ?>&amp;merge=<?= $id ?>" class="<?= $btnSec ?> inline-block">Keep <?= $h(bcrf_crm_name($dupRow)) ?>'s record instead</a><?php endif; ?>
    <?php else: ?>
    <form method="POST" action="customers.php" class="flex gap-2"><?= $hidden($id) ?><input type="hidden" name="act" value="merge"><input type="hidden" name="dup_id" value="<?= (int)$dupRow['id'] ?>">
      <button class="<?= $btnMain ?>">Merge into this one</button><a href="customers.php?id=<?= $id ?>" class="<?= $btnSec ?>">Cancel</a></form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <form method="POST" action="customers.php" class="<?= $card ?> mb-0"><?= $hidden($id) ?><input type="hidden" name="act" value="save">
      <h2 class="text-base font-semibold text-stone-800 mb-2">Details</h2>
      <div class="grid grid-cols-2 gap-2 mb-2">
        <div><label class="<?= $label ?>" for="fn">First name</label><input id="fn" name="first_name" maxlength="50" value="<?= $h($c['first_name']) ?>" class="<?= $input ?>"></div>
        <div><label class="<?= $label ?>" for="ln">Last name</label><input id="ln" name="last_name" maxlength="50" value="<?= $h($c['last_name']) ?>" class="<?= $input ?>"></div>
      </div>
      <label class="<?= $label ?>" for="ph">Phone</label><input id="ph" name="phone" maxlength="20" value="<?= $h($c['phone_number']) ?>" class="<?= $input ?> mb-2">
      <label class="<?= $label ?>" for="sn">Staff notes (never shown to the customer)</label><textarea id="sn" name="staff_notes" rows="3" class="<?= $input ?> mb-2"><?= $h($c['staff_notes']) ?></textarea>
      <button class="<?= $btnSec ?>">Save</button>
    </form>

    <div class="<?= $card ?> mb-0">
      <h2 class="text-base font-semibold text-stone-800 mb-2">Household<?= $p['household'] ? ': ' . $h($p['household']['name']) : '' ?></h2>
      <?php if (!$p['household']): ?>
        <p class="text-sm text-stone-500 mb-2">Not in a household yet. A household groups family members for family passes and shared history.</p>
        <form method="POST" action="customers.php" class="flex gap-2 mb-3"><?= $hidden($id) ?><input type="hidden" name="act" value="household_new">
          <input name="name" maxlength="120" placeholder="<?= $h(($c['last_name'] ?: $c['first_name']) . ' family') ?>" aria-label="Household name" class="<?= $input ?>"><button class="<?= $btnSec ?> shrink-0">Make household</button></form>
      <?php else: ?>
        <ul class="text-sm mb-2">
          <?php foreach ($p['members'] as $mb): ?>
          <li class="flex items-center justify-between gap-2 py-1"><span><?= (int)$mb['id'] === $id ? '<strong>' . $h(bcrf_crm_name($mb)) . '</strong>' : '<a class="underline text-amber-800" href="customers.php?id=' . (int)$mb['id'] . '">' . $h(bcrf_crm_name($mb)) . '</a>' ?> <span class="text-xs text-stone-500">adult</span></span>
            <form method="POST" action="customers.php"><?= $hidden($id) ?><input type="hidden" name="act" value="household_leave"><input type="hidden" name="member_id" value="<?= (int)$mb['id'] ?>">
              <button class="text-xs underline text-stone-500" data-confirm="Take <?= $h($mb['first_name']) ?> out of this household?">Remove</button></form></li>
          <?php endforeach; ?>
          <?php foreach ($p['children'] as $ch): ?>
          <li class="flex items-center justify-between gap-2 py-1"><span><?= $h($ch['first_name']) ?> <span class="text-xs text-stone-500">child, <?= $h(BCRF_CRM_AGES[$ch['age_range']] ?? $ch['age_range']) ?></span></span>
            <form method="POST" action="customers.php"><?= $hidden($id) ?><input type="hidden" name="act" value="child_remove"><input type="hidden" name="child_id" value="<?= (int)$ch['id'] ?>">
              <button class="text-xs underline text-stone-500" data-confirm="Remove <?= $h($ch['first_name']) ?>?">Remove</button></form></li>
          <?php endforeach; ?>
        </ul>
        <form method="POST" action="customers.php" class="flex gap-2 mb-2"><?= $hidden($id) ?><input type="hidden" name="act" value="household_join">
          <input name="other_email" type="email" placeholder="Add an adult by email" aria-label="Adult's email" class="<?= $input ?>"><button class="<?= $btnSec ?> shrink-0">Add adult</button></form>
      <?php endif; ?>
      <form method="POST" action="customers.php" class="flex gap-2"><?= $hidden($id) ?><input type="hidden" name="act" value="child_add">
        <input name="first_name" maxlength="50" placeholder="Child's first name" aria-label="Child's first name" class="<?= $input ?>">
        <select name="age" aria-label="Age range" class="<?= $input ?> w-28"><?php foreach (BCRF_CRM_AGES as $k => $v): ?><option value="<?= $k ?>"><?= $h($v) ?></option><?php endforeach; ?></select>
        <button class="<?= $btnSec ?> shrink-0">Add child</button></form>
      <p class="text-xs text-stone-500 mt-1">Children are kept as first name and age range only.</p>
    </div>
  </div>

  <div class="<?= $card ?> mt-4">
    <h2 class="text-base font-semibold text-stone-800 mb-2">History</h2>
    <p class="text-sm text-stone-700 mb-3">Register: <?= $p['sales']['count'] ?> sale<?= $p['sales']['count'] === 1 ? '' : 's' ?>, <?= $h($m($p['sales']['total'])) ?><?= $p['sales']['last'] ? ', last ' . $h($date($p['sales']['last'])) : '' ?></p>
    <?php if ($p['orgs']): ?><p class="text-sm text-stone-700 mb-3">Organizations: <?php foreach ($p['orgs'] as $i => $o): ?><?= $i ? ', ' : '' ?><a class="underline text-amber-800" href="organizations.php?id=<?= (int)$o['id'] ?>"><?= $h($o['name']) ?></a> <span class="text-xs text-stone-500">(<?= $h(strtolower(BCRF_CRM_CONTACT_ROLES[$o['role']] ?? $o['role'])) ?>)</span><?php endforeach; ?></p><?php endif; ?>
    <?php
    $rows = [];
    foreach ($p['parties'] as $r) $rows[] = [$r['preferred_date'], 'Party', (BCRF_QUOTE_PARTY_KINDS[$r['event_type']] ?? 'Party') . ' · ' . ((int)$r['adult_count'] + (int)$r['child_count']) . ' guests', $r['status'], '/admin/party.php?id=' . (int)$r['id']];
    foreach ($p['bookings'] as $r) $rows[] = [$r['booking_date'], 'Booking', $r['label'] . ' · ' . (int)$r['party_size'] . ' guests', $r['status'], '/admin/admin.php?view=bookings&day=' . rawurlencode((string)$r['booking_date'])];
    foreach ($p['passes'] as $r) $rows[] = [$r['valid_until'], 'Pass', ($r['label'] ?: 'Annual pass') . ' · ' . $r['holder_name'] . ' · until ' . $date($r['valid_until']), $r['status'], '/admin/admin.php?view=passes'];
    foreach ($p['tickets'] as $r) $rows[] = [substr((string)$r['created_at'], 0, 10), 'Tickets', (int)$r['quantity'] . ' ticket' . ((int)$r['quantity'] === 1 ? '' : 's') . ', ' . (int)$r['quantity_redeemed'] . ' used', $r['payment_status'] === 'paid' ? $r['status'] : $r['payment_status'], ''];
    usort($rows, fn($a, $b) => strcmp((string)$b[0], (string)$a[0]));
    ?>
    <?php if (!$rows): ?><p class="text-sm text-stone-500">No bookings, parties, passes or tickets yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($rows as [$d, $kind, $what, $status, $url]): ?>
      <li class="flex flex-wrap items-baseline justify-between gap-2 py-2 text-sm">
        <span><span class="text-xs text-stone-500 w-24 inline-block"><?= $h($date($d)) ?></span> <strong><?= $h($kind) ?></strong> · <?= $url ? '<a class="underline text-amber-800" href="' . $h($url) . '">' . $h($what) . '</a>' : $h($what) ?></span>
        <?= $chip((string)$status) ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>

  <?php if (bcrf_email_prefs_ready($pdo)): $ep = bcrf_email_prefs_get($pdo, (string)$c['email']); $elog = bcrf_email_prefs_log($pdo, (string)$c['email'], 8); ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-1">Emails</h2>
    <p class="text-xs text-stone-500 mb-2">Confirmations, tickets, passes, receipts, payment links and weather closures always go. These are optional. You can turn one off when they ask; only they can turn news on.</p>
    <ul class="divide-y divide-stone-100">
      <?php foreach (BCRF_EMAIL_PREF_SERVICE + BCRF_EMAIL_PREF_TOPICS as $k => $def): ?>
      <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
        <span><?= $h($def[0]) ?> <?= $ep[$k] ? $chip('On', 'bg-green-100 text-green-800') : $chip('Off') ?></span>
        <?php if ($ep[$k]): ?>
        <form method="POST" action="customers.php"><?= $hidden($id) ?><input type="hidden" name="act" value="email_off"><input type="hidden" name="setting" value="<?= $h($k) ?>">
          <button class="text-xs underline text-stone-500" data-confirm="Turn off <?= $h(strtolower($def[0])) ?> for <?= $h($c['first_name']) ?>?">Turn off</button></form>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($ep['consent'] && $ep['consent']['at']): ?><p class="text-xs text-stone-500 mt-2">Newsletter consent: <?= $h($ep['consent']['source']) ?>, <?= $h($date($ep['consent']['at'])) ?><?= $ep['consent']['unsubscribed_at'] ? ' · unsubscribed ' . $h($date($ep['consent']['unsubscribed_at'])) : '' ?></p><?php endif; ?>
    <?php if ($elog): ?>
    <details class="mt-2"><summary class="text-xs text-stone-600 cursor-pointer">Changes</summary>
      <ul class="text-xs text-stone-600 mt-1">
        <?php foreach ($elog as $l): ?><li><?= $h($date($l['created_at'])) ?> · <?= $h((BCRF_EMAIL_PREF_SERVICE[$l['setting']] ?? BCRF_EMAIL_PREF_TOPICS[$l['setting']] ?? [$l['setting']])[0]) ?> <?= $l['value'] ? 'on' : 'off' ?> (<?= $h($l['via']) ?>)</li><?php endforeach; ?>
      </ul>
    </details>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($p['merges']): ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Merged into this record</h2>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($p['merges'] as $mg): ?>
      <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
        <span><?= $h(bcrf_crm_name($mg)) ?> &lt;<?= $h($mg['email']) ?>&gt; · <?= $h($date($mg['merged_at'])) ?><?= $mg['undone_at'] ? ' · undone' : '' ?></span>
        <?php if (!$mg['undone_at'] && $mgr): ?>
        <form method="POST" action="customers.php"><?= $hidden($id) ?><input type="hidden" name="act" value="undo_merge"><input type="hidden" name="merge_id" value="<?= (int)$mg['id'] ?>">
          <button class="<?= $btnSec ?>" data-confirm="Split <?= $h($mg['first_name']) ?> back out into their own record?">Undo</button></form>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
<?php
    bcrf_shell_close(); exit;
}

// ── POSSIBLE DUPLICATES ──
if (!empty($_GET['dupes'])) {
    $pairs = bcrf_crm_duplicates($pdo);
    bcrf_shell_open('Possible duplicates', ['toast' => $toast, 'width' => 'max-w-4xl']);
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">Customers</h1>', $tabs('dupes');
    if ($error) echo bcrf_shell_notice('err', $error);
    echo '<p class="text-sm text-stone-600 mb-3">Records with the same phone number or the same name. They may be the same person, or not: check before merging.', $mgr ? '' : ' An Owner or Manager can merge them.', '</p>';
    if (!$pairs) echo '<p class="text-sm text-stone-500">Nothing to review.</p>';
    foreach ($pairs as $pr): ?>
  <div class="<?= $card ?>">
    <p class="text-xs font-semibold text-stone-500 uppercase tracking-wider mb-2"><?= $h(ucfirst($pr['why'])) ?></p>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <?php foreach ([$pr['a'], $pr['b']] as $i => $c): $other = $i ? $pr['a'] : $pr['b']; ?>
      <div class="border border-stone-200 rounded p-3">
        <a href="customers.php?id=<?= (int)$c['id'] ?>" class="font-semibold text-stone-800 underline"><?= $h(bcrf_crm_name($c)) ?></a>
        <p class="text-sm text-stone-600 break-all"><?= $h($c['email']) ?><?= $c['phone_number'] ? ' · ' . $h($c['phone_number']) : '' ?></p>
        <p class="text-xs text-stone-500 mb-2">Since <?= $h($date($c['created_at'])) ?> <?= $c['has_account'] ? '· website account' : '' ?><?= $c['staff_user_id'] ? ' · staff' : '' ?></p>
        <?php if ($mgr): ?><a href="customers.php?id=<?= (int)$c['id'] ?>&amp;merge=<?= (int)$other['id'] ?>" class="<?= $btnSec ?> inline-block">Keep this one</a><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach;
    bcrf_shell_close(); exit;
}

// ── FIND ──
$q = trim((string)($_GET['q'] ?? ''));
$list = bcrf_crm_search($pdo, $q, 60);
bcrf_shell_open('Customers', ['toast' => $toast, 'width' => 'max-w-4xl']);
echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">Customers</h1>', $tabs('find');
if ($error) echo bcrf_shell_notice('err', $error);
if ($sync['linked']) echo '<p class="text-xs text-stone-500 mb-2">Linked ', $sync['linked'], ' new booking, party, pass or ticket record', $sync['linked'] === 1 ? '' : 's', ' to their customer', $sync['created'] ? ' (' . $sync['created'] . ' new guest customer' . ($sync['created'] === 1 ? '' : 's') . ')' : '', '.</p>';
?>
  <form method="GET" action="customers.php" class="flex gap-2 mb-4">
    <input name="q" value="<?= $h($q) ?>" placeholder="Name, email or phone" aria-label="Search customers" class="<?= $input ?>">
    <button class="<?= $btnMain ?> shrink-0">Search</button>
  </form>
  <?php if (!$list): ?><p class="text-sm text-stone-500"><?= $q !== '' ? 'Nobody matches that.' : 'No customers yet.' ?></p><?php else: ?>
  <div class="<?= $card ?> p-0 overflow-hidden">
    <ul class="divide-y divide-stone-100">
      <?php foreach ($list as $c): ?>
      <li><a href="customers.php?id=<?= (int)$c['id'] ?>" class="flex flex-wrap items-baseline justify-between gap-2 px-4 py-2 hover:bg-stone-50">
        <span class="min-w-0"><span class="block text-sm font-semibold text-stone-800"><?= $h(bcrf_crm_name($c)) ?></span>
          <span class="block text-xs text-stone-500 break-all"><?= $h($c['email']) ?><?= $c['phone_number'] ? ' · ' . $h($c['phone_number']) : '' ?><?= $c['household_name'] ? ' · ' . $h($c['household_name']) : '' ?></span></span>
        <span><?= $badge($c) ?></span>
      </a></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php if ($q === ''): ?><p class="text-xs text-stone-500">Newest 60 shown. Search to find anyone else.</p><?php endif; ?>
  <?php endif; ?>
<?php
bcrf_shell_close();
