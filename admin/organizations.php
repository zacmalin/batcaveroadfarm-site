<?php
// admin/organizations.php — schools, scout troops, churches and businesses that book (Farm OS Phase 3, Nov 12, 2026;
// includes/crm.php; 07 › Customer model: "Organizations … that book, with one or more contacts").
//   GET  organizations.php[?q=…]       list with bookings, parties and contacts counted
//   GET  organizations.php?id=N | new=1  one organization: details, contacts, its bookings and parties
//   POST act=save | contact_add | contact_remove | link | unlink
// Permission: view 'customers'. Contacts are added by email (a new email makes a guest customer).
// Contacts can't see anything on the website yet: an organization dashboard waits on the permission rules (07 open item).
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
require_once __DIR__ . '/../includes/admin_shell.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

if (!bcrf_crm_ready($pdo)) {
    bcrf_shell_open('Organizations');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Organizations</h1>', bcrf_shell_notice('warning', 'Run migration 0036 in Settings › Database first.');
    bcrf_shell_close(); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $id = (int)($_POST['id'] ?? 0) ?: null;
    $back = 'organizations.php' . ($id ? '?id=' . $id : '');
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfCrmError('Security token expired; try again.');
        if ($act === 'save') {
            $new = bcrf_crm_org_save($pdo, $id, $_POST, $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, $id ? 'update' : 'create', 'organizations', $new, ($id ? 'Organization changed: ' : 'Organization added: ') . trim((string)$_POST['name']));
            $back = 'organizations.php?id=' . $new;
            $_SESSION['org_flash'] = ['ok', 'Saved.'];
        } elseif (!$id || !bcrf_crm_org_get($pdo, $id)) {
            throw new BcrfCrmError('That organization is gone.');
        } elseif ($act === 'contact_add') {
            bcrf_crm_org_contact_add($pdo, $id, (string)($_POST['email'] ?? ''), (string)($_POST['name'] ?? ''), (string)($_POST['role'] ?? 'booking'));
            $_SESSION['org_flash'] = ['ok', 'Contact added.'];
        } elseif ($act === 'contact_remove') {
            bcrf_crm_org_contact_remove($pdo, $id, (int)($_POST['customer_id'] ?? 0));
            $_SESSION['org_flash'] = ['ok', 'Contact removed.'];
        } elseif ($act === 'link' || $act === 'unlink') {
            [$type, $rid] = array_pad(explode(':', (string)($_POST['record'] ?? ''), 2), 2, '0');
            bcrf_crm_org_link($pdo, $type, (int)$rid, $act === 'link' ? $id : 0);
            $_SESSION['org_flash'] = ['ok', $act === 'link' ? 'Added to this organization.' : 'Taken off this organization.'];
        } else {
            throw new BcrfCrmError('Unknown action.');
        }
    } catch (BcrfCrmError $e) {
        $_SESSION['org_flash'] = ['err', $e->getMessage()];
        if ($act === 'save' && !$id) $back = 'organizations.php?new=1';
    } catch (Throwable $e) {
        error_log('[organizations] ' . $act . ': ' . $e->getMessage());
        $_SESSION['org_flash'] = ['err', 'Could not save. Try again.'];
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['org_flash'] ?? null; unset($_SESSION['org_flash']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';

$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$date    = fn($d) => $d ? date('M j, Y', strtotime((string)$d)) : 'No date';
$hidden  = fn(int $id) => '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '"><input type="hidden" name="id" value="' . $id . '">';
$tabs = function () use ($h) {
    $out = '<div class="flex gap-1 mb-4">';
    foreach ([['Find', 'customers.php', false], ['Possible duplicates', 'customers.php?dupes=1', false], ['Organizations', 'organizations.php', true]] as [$t, $u, $on]) {
        $out .= '<a href="' . $u . '" class="px-3 py-2 rounded text-sm border ' . ($on ? 'border-stone-800 bg-stone-800 text-white' : 'border-stone-300 text-stone-700 bg-white hover:bg-stone-100') . '">' . $h($t) . '</a>';
    }
    return $out . '</div>';
};

$id = (int)($_GET['id'] ?? 0);
if ($id || isset($_GET['new'])) {
    $o = $id ? bcrf_crm_org_get($pdo, $id) : null;
    if ($id && !$o) { header('Location: organizations.php'); exit; }
    bcrf_shell_open($o ? $o['name'] : 'New organization', ['toast' => $toast, 'width' => 'max-w-3xl', 'js' => ['quotes.js']]);
    echo '<a href="organizations.php" class="text-sm text-amber-800 underline">‹ Organizations</a>';
    echo '<h1 class="text-2xl font-bold text-stone-800 mt-2 mb-3">', $h($o ? $o['name'] : 'New organization'), $o ? ' <span class="text-sm font-normal text-stone-500">' . $h(BCRF_CRM_ORG_KINDS[$o['kind']] ?? '') . '</span>' : '', '</h1>';
    if ($error) echo bcrf_shell_notice('err', $error);
?>
  <form method="POST" action="organizations.php" class="<?= $card ?>"><?= $hidden($id) ?><input type="hidden" name="act" value="save">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <div><label class="<?= $label ?>" for="on">Name</label><input id="on" name="name" maxlength="160" required value="<?= $h($o['name'] ?? '') ?>" class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="ok">Kind</label><select id="ok" name="kind" class="<?= $input ?>"><?php foreach (BCRF_CRM_ORG_KINDS as $k => $v): ?><option value="<?= $k ?>" <?= ($o['kind'] ?? 'school') === $k ? 'selected' : '' ?>><?= $h($v) ?></option><?php endforeach; ?></select></div>
      <div><label class="<?= $label ?>" for="oe">Main email</label><input id="oe" name="email" type="email" value="<?= $h($o['email'] ?? '') ?>" class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="op">Phone</label><input id="op" name="phone" maxlength="30" value="<?= $h($o['phone'] ?? '') ?>" class="<?= $input ?>"></div>
      <div class="sm:col-span-2"><label class="<?= $label ?>" for="oa">Address</label><input id="oa" name="address" maxlength="255" value="<?= $h($o['address'] ?? '') ?>" class="<?= $input ?>"></div>
      <div class="sm:col-span-2"><label class="<?= $label ?>" for="ono">Notes (staff only)</label><textarea id="ono" name="notes" rows="3" class="<?= $input ?>"><?= $h($o['notes'] ?? '') ?></textarea></div>
    </div>
    <button class="<?= $btnMain ?> mt-3"><?= $o ? 'Save' : 'Add organization' ?></button>
  </form>
<?php
    if ($o):
        $contacts = bcrf_crm_org_contacts($pdo, $id);
        $recs = bcrf_crm_org_records($pdo, $id);
        // Bookings and parties from the last 90 days or later that aren't linked to any organization yet
        $from = date('Y-m-d', strtotime('-90 days'));
        $st = $pdo->prepare("SELECT b.id, b.customer_name, b.booking_date AS d, t.label AS what FROM bookings b JOIN booking_types t ON t.id = b.type_id
                             WHERE b.organization_id IS NULL AND t.type_key <> 'day_admission' AND (b.booking_date >= ? OR b.date_flexible = 1) AND b.status <> 'cancelled' ORDER BY b.booking_date LIMIT 100");
        $st->execute([$from]);
        $open = array_map(fn($r) => ['booking:' . $r['id'], $date($r['d']) . ' · ' . $r['what'] . ' · ' . $r['customer_name']], $st->fetchAll(PDO::FETCH_ASSOC));
        $st = $pdo->prepare("SELECT id, customer_name, preferred_date AS d, event_type FROM event_inquiries
                             WHERE organization_id IS NULL AND (preferred_date >= ? OR preferred_date IS NULL) AND status NOT IN ('declined', 'archived') ORDER BY preferred_date LIMIT 100");
        $st->execute([$from]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $open[] = ['event_inquiry:' . $r['id'], $date($r['d']) . ' · ' . (BCRF_QUOTE_PARTY_KINDS[$r['event_type']] ?? 'Party') . ' · ' . $r['customer_name']];
?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Contacts</h2>
    <?php if (!$contacts): ?><p class="text-sm text-stone-500 mb-2">No contacts yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100 mb-3">
      <?php foreach ($contacts as $c): ?>
      <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
        <span><a class="underline text-amber-800 font-semibold" href="customers.php?id=<?= (int)$c['id'] ?>"><?= $h(bcrf_crm_name($c)) ?></a> · <?= $h(BCRF_CRM_CONTACT_ROLES[$c['role']] ?? $c['role']) ?>
          <span class="block text-xs text-stone-500 break-all"><?= $h($c['email']) ?><?= $c['phone_number'] ? ' · ' . $h($c['phone_number']) : '' ?></span></span>
        <form method="POST" action="organizations.php"><?= $hidden($id) ?><input type="hidden" name="act" value="contact_remove"><input type="hidden" name="customer_id" value="<?= (int)$c['id'] ?>">
          <button class="<?= $btnSec ?>" data-confirm="Remove <?= $h($c['first_name']) ?> as a contact?">Remove</button></form>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <form method="POST" action="organizations.php" class="grid grid-cols-1 sm:grid-cols-4 gap-2 items-end"><?= $hidden($id) ?><input type="hidden" name="act" value="contact_add">
      <div><label class="<?= $label ?>" for="cn">Name</label><input id="cn" name="name" maxlength="100" class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="ce">Email</label><input id="ce" name="email" type="email" required class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="cr">Role</label><select id="cr" name="role" class="<?= $input ?>"><?php foreach (BCRF_CRM_CONTACT_ROLES as $k => $v): ?><option value="<?= $k ?>"><?= $h($v) ?></option><?php endforeach; ?></select></div>
      <button class="<?= $btnSec ?>">Add contact</button>
    </form>
  </div>

  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Bookings and parties</h2>
    <?php if (!$recs['bookings'] && !$recs['parties']): ?><p class="text-sm text-stone-500 mb-2">None yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100 mb-3">
      <?php foreach ($recs['bookings'] as $r): ?>
      <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"><span><?= $h($date($r['booking_date'])) ?> · <a class="underline text-amber-800" href="/admin/admin.php?view=bookings&amp;day=<?= $h(rawurlencode((string)$r['booking_date'])) ?>"><?= $h($r['label']) ?></a> · <?= (int)$r['party_size'] ?> guests · <?= $h($r['status']) ?></span>
        <form method="POST" action="organizations.php"><?= $hidden($id) ?><input type="hidden" name="act" value="unlink"><input type="hidden" name="record" value="booking:<?= (int)$r['id'] ?>"><button class="text-xs underline text-stone-500">Take off</button></form></li>
      <?php endforeach; ?>
      <?php foreach ($recs['parties'] as $r): ?>
      <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"><span><?= $h($date($r['preferred_date'])) ?> · <a class="underline text-amber-800" href="/admin/party.php?id=<?= (int)$r['id'] ?>"><?= $h(BCRF_QUOTE_PARTY_KINDS[$r['event_type']] ?? 'Party') ?></a> · <?= (int)$r['adult_count'] + (int)$r['child_count'] ?> guests · <?= $h($r['status']) ?></span>
        <form method="POST" action="organizations.php"><?= $hidden($id) ?><input type="hidden" name="act" value="unlink"><input type="hidden" name="record" value="event_inquiry:<?= (int)$r['id'] ?>"><button class="text-xs underline text-stone-500">Take off</button></form></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($open): ?>
    <form method="POST" action="organizations.php" class="flex gap-2"><?= $hidden($id) ?><input type="hidden" name="act" value="link">
      <select name="record" aria-label="Booking or party" class="<?= $input ?>"><?php foreach ($open as [$v, $t]): ?><option value="<?= $h($v) ?>"><?= $h($t) ?></option><?php endforeach; ?></select>
      <button class="<?= $btnSec ?> shrink-0">Add</button></form>
    <?php endif; ?>
  </div>
<?php
    endif;
    bcrf_shell_close(); exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$orgs = bcrf_crm_orgs($pdo, $q);
bcrf_shell_open('Organizations', ['toast' => $toast, 'width' => 'max-w-4xl']);
echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">Customers</h1>', $tabs();
if ($error) echo bcrf_shell_notice('err', $error);
?>
  <div class="flex flex-wrap gap-2 mb-4">
    <form method="GET" action="organizations.php" class="flex gap-2 flex-1 min-w-48">
      <input name="q" value="<?= $h($q) ?>" placeholder="Search organizations" aria-label="Search organizations" class="<?= $input ?>"><button class="<?= $btnSec ?> shrink-0">Search</button></form>
    <a href="organizations.php?new=1" class="<?= $btnMain ?>">Add organization</a>
  </div>
  <?php if (!$orgs): ?><p class="text-sm text-stone-500"><?= $q !== '' ? 'Nothing matches that.' : 'No organizations yet. Add the schools, troops and churches that book with you.' ?></p><?php else: ?>
  <div class="<?= $card ?> p-0 overflow-hidden">
    <ul class="divide-y divide-stone-100">
      <?php foreach ($orgs as $o): ?>
      <li><a href="organizations.php?id=<?= (int)$o['id'] ?>" class="flex flex-wrap items-baseline justify-between gap-2 px-4 py-2 hover:bg-stone-50">
        <span><span class="text-sm font-semibold text-stone-800"><?= $h($o['name']) ?></span> <span class="text-xs text-stone-500"><?= $h(BCRF_CRM_ORG_KINDS[$o['kind']] ?? '') ?></span></span>
        <span class="text-xs text-stone-500"><?= (int)$o['n_bookings'] ?> bookings · <?= (int)$o['n_parties'] ?> parties · <?= (int)$o['n_contacts'] ?> contacts</span>
      </a></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
<?php
bcrf_shell_close();
