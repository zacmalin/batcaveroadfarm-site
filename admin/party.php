<?php
// admin/party.php — one party's plan: package, staff and guest list (Farm OS Phase 3, Nov 12, 2026; includes/parties.php;
// 07 › Private events).
//   GET  party.php?id=N
//   POST act=package               pick the party's package (sets the quote's starting price and the party's length)
//        act=staff_add / staff_remove
//        act=guests_add            names, one per line ("Name" or "Name, note"), as children or adults
//        act=guest_remove / arrived / not_arrived
// Access: Private Events (view event_inquiries) can do everything. Staff assigned to the party can open it and check
// guests in, nothing else.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/event_quotes.php';
require_once __DIR__ . '/../includes/tasks.php';
require_once __DIR__ . '/../includes/parties.php';
require_once __DIR__ . '/../includes/admin_shell.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid  = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['user_role'] ?? '');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$plural = fn(int $n, string $one, string $many) => $n . ' ' . ($n === 1 ? $one : $many);
$id = (int)($_REQUEST['id'] ?? 0);
$full = $role === 'administrator' || (function_exists('hasViewAccess') && hasViewAccess($role, 'event_inquiries'));
$ready = bcrf_party_ready($pdo);
$inq = $id > 0 ? bcrf_party_get($pdo, $id) : null;
$assigned = $ready && $inq && bcrf_party_is_staff($pdo, $id, $uid);
if (!$full && !$assigned) { http_response_code(403); bcrf_shell_open('Party'); echo bcrf_shell_notice('err', 'You don\'t have access to that party.'); bcrf_shell_close(); exit; }

if (!$ready || !$inq) {
    bcrf_shell_open('Party');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Party</h1>',
         !$ready ? bcrf_shell_notice('warning', 'Run migration 0035 in Settings › Database first.') : bcrf_shell_notice('err', 'That party wasn\'t found.'),
         '<a href="/admin/admin.php?view=event_inquiries&amp;status=all" class="text-sm text-amber-800 underline">Back to Private Events</a>';
    bcrf_shell_close();
    exit;
}

$audit = function (string $what) use ($pdo, $id, $inq) {
    if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'event_inquiries', $id, $what . ' (' . $inq['customer_name'] . ')');
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $back = 'party.php?id=' . $id . (in_array($act, ['arrived', 'not_arrived', 'guest_remove', 'guests_add'], true) ? '#guests' : '');
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfPartyError('Security token expired; try again.');
        $staffOnly = ['arrived', 'not_arrived'];
        if (!$full && !in_array($act, $staffOnly, true)) throw new BcrfPartyError('Only someone with Private Events access can change that.');
        $gid = (int)($_POST['guest_id'] ?? 0);
        if ($act === 'package') {
            $pkg = (int)($_POST['package_id'] ?? 0) ?: null;
            bcrf_party_set_package($pdo, $id, $pkg);
            $p = bcrf_party_package($pdo, $pkg);
            $audit('Party package: ' . ($p['name'] ?? 'none'));
            $q = bcrf_quote_get($pdo, 'event_inquiry', $id);
            $_SESSION['party_flash'] = ['ok', 'Package saved.' . ($q ? ' The quote you already made keeps its price; change it on the quote page if it should follow the package.' : '')];
        } elseif ($act === 'staff_add') {
            bcrf_party_staff_add($pdo, $id, (int)($_POST['user_id'] ?? 0), (string)($_POST['job'] ?? ''));
            $audit('Party staff added: user #' . (int)($_POST['user_id'] ?? 0));
            $_SESSION['party_flash'] = ['ok', 'Added. They\'ll see the party on their calendar.'];
        } elseif ($act === 'staff_remove') {
            bcrf_party_staff_remove($pdo, $id, (int)($_POST['user_id'] ?? 0));
            $audit('Party staff removed: user #' . (int)($_POST['user_id'] ?? 0));
            $_SESSION['party_flash'] = ['ok', 'Removed from the party.'];
        } elseif ($act === 'guests_add') {
            $n = bcrf_party_guests_add($pdo, $id, (string)($_POST['names'] ?? ''), ($_POST['kind'] ?? 'child') !== 'adult');
            $_SESSION['party_flash'] = ['ok', $n . ' added to the guest list.'];
        } elseif ($act === 'guest_remove') {
            bcrf_party_guest_remove($pdo, $id, $gid);
            $_SESSION['party_flash'] = ['ok', 'Taken off the list.'];
        } elseif ($act === 'arrived' || $act === 'not_arrived') {
            bcrf_party_guest_arrived($pdo, $id, $gid, $act === 'arrived');
            $_SESSION['party_flash'] = ['ok', $act === 'arrived' ? 'Checked in.' : 'Marked not here.'];
        } else {
            throw new BcrfPartyError('Unknown action.');
        }
    } catch (BcrfPartyError $e) {
        $_SESSION['party_flash'] = ['err', $e->getMessage()];
        if ($act === 'guests_add') $_SESSION['party_names'] = (string)($_POST['names'] ?? '');
    } catch (Throwable $e) {
        error_log('[party] ' . $act . ': ' . $e->getMessage());
        $_SESSION['party_flash'] = ['err', 'Could not save. Try again.'];
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['party_flash'] ?? null; unset($_SESSION['party_flash']);
$typedNames = (string)($_SESSION['party_names'] ?? ''); unset($_SESSION['party_names']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';

$s = bcrf_quote_subject($pdo, 'event_inquiry', $id);
$pkg = bcrf_party_package($pdo, (int)($inq['package_id'] ?? 0));
$packages = bcrf_party_packages($pdo);
if ($pkg && !$pkg['active']) $packages[] = $pkg;   // still shows the one this party has, even if it was turned off
$staff = bcrf_party_staff($pdo, $id);
$people = bcrf_tasks_people($pdo);
$guests = bcrf_party_guests($pdo, $id);
$gc = bcrf_party_guest_counts($guests);
$q = bcrf_quote_get($pdo, 'event_inquiry', $id);
$len = bcrf_party_length($pdo, $inq);
$expected = (int)$inq['adult_count'] + (int)$inq['child_count'];
$endLabel = !empty($inq['start_time']) ? date('g:i A', strtotime($inq['preferred_date'] . ' ' . $inq['start_time'] . " +{$len} minutes")) : '';

$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$btnDanger = 'px-3 py-2 rounded text-sm border border-red-300 text-red-700 hover:bg-red-50 bg-white';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$hidden  = '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '"><input type="hidden" name="id" value="' . $id . '">';

bcrf_shell_open('Party: ' . $inq['customer_name'], ['toast' => $toast, 'width' => 'max-w-3xl', 'js' => ['quotes.js']]);
?>
  <?php if ($full): ?><a href="/admin/admin.php?view=event_inquiries&amp;status=all" class="text-sm text-amber-800 underline">‹ Private Events</a><?php endif; ?>
  <div class="flex flex-wrap items-end justify-between gap-2 mt-2 mb-3">
    <div>
      <h1 class="text-2xl font-bold text-stone-800"><?= $h($s['what'] . ': ' . $inq['customer_name']) ?></h1>
      <p class="text-sm text-stone-600"><?= $h(bcrf_quote_when($s)) ?><?= $endLabel !== '' ? ' to ' . $h($endLabel) : '' ?> · <?= $expected ?> guests expected (<?= $h($plural((int)$inq['child_count'], 'child', 'children') . ', ' . $plural((int)$inq['adult_count'], 'adult', 'adults')) ?>)</p>
    </div>
    <span class="inline-block text-xs font-semibold px-2 py-1 rounded <?= $inq['status'] === 'confirmed' ? 'bg-emerald-50 text-emerald-800' : 'bg-stone-100 text-stone-600' ?>"><?= $h(ucfirst((string)$inq['status'])) ?></span>
  </div>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>
  <?php if ($full): ?>
  <div class="flex flex-wrap gap-2 mb-4">
    <a href="/admin/quotes.php?for=event_inquiry:<?= $id ?>" class="<?= $btnSec ?>"><?= $q ? 'Quote &amp; payments' : 'Make a quote' ?></a>
    <?php if (!empty($inq['phone'])): ?><a href="tel:<?= $h($inq['phone']) ?>" class="<?= $btnSec ?>">Call <?= $h(strtok((string)$inq['customer_name'], ' ')) ?></a><?php endif; ?>
    <?php if (!empty($inq['email'])): ?><a href="mailto:<?= $h($inq['email']) ?>" class="<?= $btnSec ?>">Email</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if (!empty($inq['special_requests'])): ?>
  <div class="<?= $card ?>"><h2 class="text-base font-semibold text-stone-800 mb-1">Their notes</h2><p class="text-sm text-stone-700 whitespace-pre-line"><?= $h($inq['special_requests']) ?></p></div>
  <?php endif; ?>

  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Package</h2>
    <?php if ($pkg): ?>
      <p class="text-sm text-stone-800"><span class="font-semibold"><?= $h($pkg['name']) ?></span> · <?= $h(bcrf_party_price_text($pkg)) ?> · <?= $pkg['duration_minutes'] ?> minutes</p>
      <?php if ($pkg['items']): ?><ul class="text-sm text-stone-600 mt-1 mb-2"><?php foreach ($pkg['items'] as $it): ?><li>• <?= $h($it) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php else: ?>
      <p class="text-sm text-stone-600 mb-2">No package picked yet, so the farm's usual per-guest rate applies.</p>
    <?php endif; ?>
    <?php if ($full): ?>
    <form method="POST" action="party.php" class="flex flex-wrap gap-2 items-end mt-2"><?= $hidden ?><input type="hidden" name="act" value="package">
      <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="pk">Change package</label>
        <select id="pk" name="package_id" class="<?= $input ?>">
          <option value="">No package</option>
          <?php foreach ($packages as $p): ?><option value="<?= $p['id'] ?>" <?= $pkg && $pkg['id'] === $p['id'] ? 'selected' : '' ?>><?= $h($p['name'] . ' · ' . bcrf_party_price_text($p)) ?><?= $p['active'] ? '' : ' (turned off)' ?></option><?php endforeach; ?>
        </select></div>
      <button class="<?= $btnMain ?>">Save</button>
    </form>
    <p class="text-xs text-stone-500 mt-2">A new quote starts from the package's price. <a href="/admin/party_packages.php" class="underline">Edit packages</a></p>
    <?php endif; ?>
  </div>

  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Staff</h2>
    <?php if (!$staff): ?>
      <p class="text-sm text-stone-500 mb-2">Nobody assigned yet.</p>
    <?php else: ?>
    <ul class="divide-y divide-stone-100 mb-2">
      <?php foreach ($staff as $p): ?>
      <li class="flex flex-wrap items-center justify-between gap-2 py-2">
        <span class="text-sm text-stone-800"><span class="font-semibold"><?= $h(trim($p['first_name'] . ' ' . $p['last_name'])) ?></span><?= $p['job'] ? ' · ' . $h($p['job']) : '' ?></span>
        <?php if ($full): ?>
        <form method="POST" action="party.php"><?= $hidden ?><input type="hidden" name="act" value="staff_remove"><input type="hidden" name="user_id" value="<?= (int)$p['user_id'] ?>">
          <button class="<?= $btnSec ?>" data-confirm="Take <?= $h($p['first_name']) ?> off this party?">Remove</button></form>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($full && $people): ?>
    <form method="POST" action="party.php" class="grid grid-cols-1 sm:grid-cols-3 gap-2 items-end"><?= $hidden ?><input type="hidden" name="act" value="staff_add">
      <div><label class="<?= $label ?>" for="su">Person</label>
        <select id="su" name="user_id" class="<?= $input ?>"><?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>"><?= $h($p['name']) ?></option><?php endforeach; ?></select></div>
      <div><label class="<?= $label ?>" for="sj">Job (optional)</label><input id="sj" name="job" maxlength="80" placeholder="Lead, animals, food…" class="<?= $input ?>"></div>
      <button class="<?= $btnSec ?>">Add to party</button>
    </form>
    <?php endif; ?>
  </div>

  <div class="<?= $card ?>" id="guests">
    <h2 class="text-base font-semibold text-stone-800 mb-1">Guest list</h2>
    <p class="text-sm text-stone-600 mb-2"><?= $gc['total'] ? $gc['total'] . ' on the list (' . $plural($gc['children'], 'child', 'children') . ', ' . $plural($gc['adults'], 'adult', 'adults') . ') of ' . $expected . ' expected · ' . $gc['arrived'] . ' here' : 'Optional. Add names if the host sends them, then check people in as they arrive.' ?></p>
    <?php if ($gc['children'] > 0 && $gc['adults'] === 0): ?><?= bcrf_shell_notice('warning', 'Every child needs an adult with them. No adults are on the list yet.') ?><?php endif; ?>
    <?php if ($guests): ?>
    <ul class="divide-y divide-stone-100 mb-3">
      <?php foreach ($guests as $g): ?>
      <li class="flex flex-wrap items-center justify-between gap-2 py-2">
        <span class="min-w-0">
          <span class="block text-sm <?= $g['arrived_at'] ? 'text-stone-800 font-semibold' : 'text-stone-700' ?>"><?= $g['arrived_at'] ? '✓ ' : '' ?><?= $h($g['name']) ?> <span class="text-xs text-stone-500 font-normal"><?= $g['is_child'] ? 'child' : 'adult' ?></span></span>
          <?php if ($g['note']): ?><span class="block text-xs text-stone-500"><?= $h($g['note']) ?></span><?php endif; ?>
        </span>
        <span class="flex gap-1">
          <form method="POST" action="party.php"><?= $hidden ?><input type="hidden" name="guest_id" value="<?= (int)$g['id'] ?>">
            <button name="act" value="<?= $g['arrived_at'] ? 'not_arrived' : 'arrived' ?>" class="<?= $g['arrived_at'] ? $btnSec : $btnMain ?>"><?= $g['arrived_at'] ? 'Undo' : 'Here' ?></button></form>
          <?php if ($full): ?>
          <form method="POST" action="party.php"><?= $hidden ?><input type="hidden" name="guest_id" value="<?= (int)$g['id'] ?>">
            <button name="act" value="guest_remove" class="<?= $btnSec ?>" aria-label="Remove <?= $h($g['name']) ?>" data-confirm="Take <?= $h($g['name']) ?> off the list?">✕</button></form>
          <?php endif; ?>
        </span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($full): ?>
    <form method="POST" action="party.php" class="border-t border-stone-100 pt-3"><?= $hidden ?><input type="hidden" name="act" value="guests_add">
      <label class="<?= $label ?>" for="gn">Add names, one per line. Add a note after a comma, like "Ava, peanut allergy".</label>
      <textarea id="gn" name="names" rows="4" class="<?= $input ?> mb-2"><?= $h($typedNames) ?></textarea>
      <div class="flex flex-wrap gap-2 items-center">
        <label class="text-sm text-stone-700 flex items-center gap-1"><input type="radio" name="kind" value="child" checked> Children</label>
        <label class="text-sm text-stone-700 flex items-center gap-1"><input type="radio" name="kind" value="adult"> Adults</label>
        <button class="<?= $btnSec ?> ml-auto">Add to list</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
<?php
bcrf_shell_close();
